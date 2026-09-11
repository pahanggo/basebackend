<script>
    // Drawflow instance lifecycle: init, node/edge CRUD on the canvas,
    // pan/zoom/minimap, copy-paste, keyboard shortcuts. Merged onto the
    // main workflowDesigner() Alpine component in edit.blade.php — see
    // that file for how the mixins are composed and why (this uses plain
    // Object.assign, not ES modules, since this package ships no build step).
    const WF_CANVAS_MIXIN = {
            init() {
                // Guard against double-initialization (e.g. Alpine re-processing
                // the page) leaving two overlapping Drawflow instances behind.
                var canvasEl = document.getElementById('workflow-canvas');
                if (canvasEl.dataset.wfInitialized) {
                    return;
                }
                canvasEl.dataset.wfInitialized = '1';

                this.graph.nodes = (this.initialGraph.nodes || []).map(n => ({
                    id: n.id, name: n.name || n.id, type: n.type || 'state', field_policy: n.field_policy || [],
                    header_view: n.header_view || '', footer_view: n.footer_view || '',
                    x: n.x, y: n.y,
                }));
                this.graph.edges = (this.initialGraph.edges || []).map(e => this.normalizeEdge(e));

                this.editor = new Drawflow(canvasEl);
                this.editor.reroute = false;
                this.editor.curvature = 0;
                this.editor.start();

                this.graph.nodes.forEach((node, i) => {
                    // Use the saved position if this node has one; otherwise
                    // fall back to the auto-grid layout (e.g. a brand new node).
                    const x = typeof node.x === 'number' ? node.x : this.snap(120 + (i % 4) * 240);
                    const y = typeof node.y === 'number' ? node.y : this.snap(100 + Math.floor(i / 4) * 160);
                    this.drawNode(node, x, y);
                });
                this.$nextTick(() => {
                    this.drawEdges();
                    this.redrawOrthogonalConnections();
                    this.updateMinimap();
                    this.layoutFullBleed();
                    this.restoreView(this.initialGraph.view);
                    // Alpine bound the "Start node" <select>'s initial value
                    // before its x-for had any <option>s to match against
                    // (graph.nodes started empty), so the browser silently
                    // fell back to "— none —" even though graph.start itself
                    // was already correct — reconcile the visible selection
                    // now that the options actually exist.
                    const startSelect = document.querySelector('.wf-float-toolbar select');
                    if (startSelect) startSelect.value = this.graph.start;
                });

                this.editor.on('connectionCreated', (c) => { this.onConnectionCreated(c); this.redrawOrthogonalConnections(); });
                this.editor.on('connectionRemoved', (c) => this.onConnectionRemoved(c));
                this.editor.on('nodeSelected', (id) => this.onNodeSelected(id));
                this.editor.on('nodeMoved', (id) => this.onNodeMoved(id));
                this.editor.on('connectionSelected', (c) => this.onConnectionSelected(c));
                this.editor.on('zoom', (z) => this.onZoom(z));
                this.editor.on('translate', (pos) => this.onTranslate(pos));

                // Redraw connections live while dragging a node, and keep them
                // orthogonal even mid-drag rather than snapping only at drop.
                canvasEl.addEventListener('mousemove', (e) => {
                    if (this.editor.drag) this.redrawOrthogonalConnections();
                    if (this.editor.connection) this.updateDraggingConnectionPath(e);
                    if (this.reconnecting) this.updateReconnectPath(e);
                });

                // Grabbing a selected connection's endpoint handle starts a
                // reconnect drag instead of Drawflow's own node/connection
                // logic — capture phase + stopPropagation so Drawflow's own
                // mousedown handler never sees this click at all.
                canvasEl.addEventListener('mousedown', (e) => {
                    if (e.target.classList && e.target.classList.contains('wf-reconnect-handle')) {
                        e.preventDefault();
                        e.stopPropagation();
                        this.startReconnect(e.target);
                    }
                }, true);
                window.addEventListener('mouseup', (e) => this.finishReconnect(e));

                // Drawflow's own right-click handler (bound inside
                // editor.start(), so it's already attached by the time we
                // get here) shows a small "×" delete-this overlay on
                // whatever was under the cursor — redundant with, and less
                // discoverable than, this inspector's own "Delete node"/
                // "Delete connection" button, and easy to trigger by
                // accident. Intercepting on the capture phase from the
                // wrapping element (rather than canvasEl itself, where
                // Drawflow's listener would still run first since it was
                // registered earlier) stops the event before it ever
                // reaches Drawflow's handler.
                document.getElementById('workflow-canvas-wrap').addEventListener('contextmenu', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                }, true);

                window.addEventListener('resize', () => this.layoutFullBleed());
                window.addEventListener('keydown', (e) => this.handleKeydown(e));

                // Toggling the admin sidebar changes .main's offset via a CSS
                // class on <body> (sidebar-hidden/sidebar-lg-show/etc, per
                // CoreUI) — that never fires a window "resize" event since
                // the viewport itself doesn't change, so layoutFullBleed()
                // would otherwise never re-run and the canvas would keep
                // whatever width it had when the sidebar was last in a
                // different state. Re-measure once immediately (for a snappy
                // response) and again after the sidebar's own CSS transition
                // finishes, so the final position is exact either way.
                new MutationObserver(() => {
                    this.layoutFullBleed();
                    setTimeout(() => this.layoutFullBleed(), 300);
                }).observe(document.body, { attributes: true, attributeFilter: ['class'] });

                // The toolbar itself is static markup (unlike the inspector
                // panel, which re-renders via x-html), so a single init here
                // is enough — no need to reinitialize on every redraw the way
                // the select2 widgets do. Same convention as the rest of the
                // app (e.g. crud/buttons/delete.blade.php): data-toggle
                // ="tooltip" + title, not the raw browser tooltip.
                $('.wf-float-toolbar [data-toggle="tooltip"], .wf-zoom-controls [data-toggle="tooltip"]').tooltip();
            },

            /** Ctrl/Cmd+C copies the selected state/fork/join node; Ctrl/Cmd+V
             *  drops a clone of the last-copied node next to its source.
             *  Ignored while typing in an inspector field so it doesn't
             *  hijack normal copy/paste there. */
            handleKeydown(e) {
                if (! (e.ctrlKey || e.metaKey)) return;
                const key = e.key.toLowerCase();

                // Save works from anywhere on the page, including while typing
                // in an inspector field — it should also suppress the browser's
                // own "Save page" dialog rather than let that fire alongside it.
                if (key === 's') {
                    e.preventDefault();
                    this.saveDraft();
                    return;
                }

                const tag = document.activeElement ? document.activeElement.tagName : '';
                if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || (document.activeElement && document.activeElement.isContentEditable)) return;

                if (key === 'c' && this.selected && this.selected.kind === 'node') {
                    e.preventDefault();
                    this.copySelectedNode();
                } else if (key === 'v' && this.clipboardNode) {
                    e.preventDefault();
                    this.pasteClonedNode();
                }
            },

            copySelectedNode() {
                const node = this.findNode(this.selected.id);
                if (node) this.clipboardNode = JSON.parse(JSON.stringify(node));
            },

            pasteClonedNode() {
                const source = this.clipboardNode;
                if (! source) return;

                const id = this.generateNodeId(source.type);
                const node = {
                    id,
                    name: source.name + ' copy',
                    type: source.type,
                    field_policy: JSON.parse(JSON.stringify(source.field_policy || [])),
                    header_view: source.header_view || '',
                    footer_view: source.footer_view || '',
                };
                this.graph.nodes.push(node);

                const sourceDfId = this.nodeIdToDrawflowId[source.id];
                const sourcePos = sourceDfId != null ? this.editor.drawflow.drawflow[this.editor.module].data[sourceDfId] : null;
                const x = this.snap((sourcePos ? sourcePos.pos_x : 120) + 220);
                const y = this.snap(sourcePos ? sourcePos.pos_y : 100);
                this.drawNode(node, x, y);
                this.updateMinimap();
                this.selectNodeVisually(id);
            },

            /** Marks a node selected both in our Alpine state (drives the
             *  inspector) and in the DOM (Drawflow's own "selected" styling),
             *  for selections we trigger ourselves rather than via a real
             *  click through Drawflow's own handler. */
            selectNodeVisually(nodeId) {
                document.querySelectorAll('.drawflow-node.selected').forEach(el => el.classList.remove('selected'));
                const dfId = this.nodeIdToDrawflowId[nodeId];
                if (dfId != null) {
                    const el = document.getElementById('node-' + dfId);
                    if (el) el.classList.add('selected');
                }
                this.selected = { kind: 'node', id: nodeId };
                this.redrawOrthogonalConnections();
            },

            snap(value) {
                return Math.round(value / this.gridSize) * this.gridSize;
            },

            /** Makes the canvas fill the viewport below/right of the admin
             *  chrome (the fixed header + sidebar). Measures `.main` — a
             *  still-in-flow ancestor, never `#workflow-canvas-wrap` itself
             *  (which this function fixed-positions) — so the canvas keeps
             *  tracking the sidebar's current width: CoreUI already shifts
             *  `.main`'s own offset whenever the admin sidebar is toggled,
             *  we just need to keep re-reading it instead of caching it once. */
            layoutFullBleed() {
                const wrap = document.getElementById('workflow-canvas-wrap');
                const reference = wrap.closest('.main') || wrap.parentElement;
                const rect = reference.getBoundingClientRect();
                wrap.style.position = 'fixed';
                wrap.style.top = rect.top + 'px';
                wrap.style.left = rect.left + 'px';
                wrap.style.right = '0px';
                wrap.style.bottom = '0px';
                wrap.style.zIndex = '1000';
            },

            toggleFullscreen() {
                const el = document.getElementById('workflow-canvas-wrap');
                if (! document.fullscreenElement) {
                    el.requestFullscreen();
                } else {
                    document.exitFullscreen();
                }
            },

            toggleInspector() {
                this.inspectorVisible = ! this.inspectorVisible;
                try {
                    localStorage.setItem('wf-inspector-visible', this.inspectorVisible ? '1' : '0');
                } catch (e) {
                    // Private-browsing/storage-disabled edge case — the
                    // toggle still works for this session, it just won't
                    // be remembered next time.
                }
            },

            /** Keeps the dot-grid background in step with Drawflow's zoom, so it
             *  still lines up with the (unscaled-coordinate) node grid. */
            onZoom(zoom) {
                const canvasEl = document.getElementById('workflow-canvas');
                const size = this.gridSize * zoom;
                canvasEl.style.backgroundSize = size + 'px ' + size + 'px';
                // Scale the dot radius too, not just the grid spacing, so dots
                // stay visually proportional at any zoom level.
                const dotRadius = 1.5 * zoom;
                canvasEl.style.backgroundImage = `radial-gradient(#c7cbd1 ${dotRadius}px, transparent ${dotRadius}px)`;
            },

            /** Keeps the dot-grid in sync while panning the canvas, since the
             *  background lives on the (untransformed) outer container, not
             *  on precanvas which is what Drawflow actually pans/zooms. */
            onTranslate(pos) {
                document.getElementById('workflow-canvas').style.backgroundPosition = pos.x + 'px ' + pos.y + 'px';
            },

            zoomIn() { this.editor.zoom_in(); },
            zoomOut() { this.editor.zoom_out(); },

            resetView() {
                this.editor.zoom = 1;
                this.editor.zoom_last_value = 1;
                this.editor.canvas_x = 0;
                this.editor.canvas_y = 0;
                this.editor.precanvas.style.transform = 'translate(0px, 0px) scale(1)';
                this.onZoom(1);
                this.onTranslate({ x: 0, y: 0 });
            },

            /** Applies a previously-saved pan/zoom, or leaves the default
             *  (100%, top-left) view when this definition has never saved one. */
            restoreView(view) {
                if (! view || typeof view.zoom !== 'number') return;
                this.editor.zoom = view.zoom;
                this.editor.zoom_last_value = view.zoom;
                this.editor.canvas_x = view.x || 0;
                this.editor.canvas_y = view.y || 0;
                this.editor.precanvas.style.transform = `translate(${this.editor.canvas_x}px, ${this.editor.canvas_y}px) scale(${this.editor.zoom})`;
                this.onZoom(this.editor.zoom);
                this.onTranslate({ x: this.editor.canvas_x, y: this.editor.canvas_y });
            },

            normalizeEdge(e) {
                return {
                    id: e.id, name: e.name || e.id, from: e.from, to: e.to, trigger: e.trigger || 'manual',
                    actor_rule: { roles: [], permissions: [], users: [], model_callback: '', match: 'any', ...(e.actor_rule || {}) },
                    surfaces: e.surfaces || ['record_button'],
                    button_label: e.button_label || '',
                    requires_confirmation: !! e.requires_confirmation,
                    preconditions: e.preconditions || null,
                    actions: e.actions || [],
                    inputs: e.inputs || [],
                };
            },

            // ---------- Canvas <-> graph sync ----------

            drawNode(node, x, y) {
                const html = `<div>${node.name}</div>`;
                const cssClass = node.type === 'fork' ? 'wf-node-fork' : (node.type === 'join' ? 'wf-node-join' : '');
                const dfId = this.editor.addNode(node.id, 1, 1, x, y, cssClass, { nodeId: node.id }, html, false);
                this.nodeIdToDrawflowId[node.id] = dfId;
                this.drawflowIdToNodeId[dfId] = node.id;
            },

            /** Updates a node's on-canvas label after its name is edited in the inspector. */
            updateNodeLabel(nodeId) {
                const node = this.findNode(nodeId);
                const dfId = this.nodeIdToDrawflowId[nodeId];
                if (! node || dfId == null) return;
                const contentEl = document.querySelector('#node-' + dfId + ' .drawflow_content_node');
                if (contentEl) contentEl.textContent = node.name;
            },

            drawEdges() {
                this.suppressEvents = true;
                this.graph.edges.forEach(edge => {
                    const fromDf = this.nodeIdToDrawflowId[edge.from];
                    const toDf = this.nodeIdToDrawflowId[edge.to];
                    if (fromDf && toDf) {
                        try { this.editor.addConnection(fromDf, toDf, 'output_1', 'input_1'); } catch (e) {}
                    }
                });
                this.suppressEvents = false;
            },

            /** Finds the next free `type_N` id — a plain counter can collide
             *  once nodes have been deleted or cloned out of sequence. */
            generateNodeId(type) {
                let n = this.graph.nodes.length + 1;
                let id = type + '_' + n;
                while (this.findNode(id)) {
                    n++;
                    id = type + '_' + n;
                }
                return id;
            },

            addNode(type) {
                const id = this.generateNodeId(type);
                const node = { id, name: id, type, field_policy: [] };
                this.graph.nodes.push(node);
                const count = this.graph.nodes.length;
                this.drawNode(node, this.snap(120 + ((count - 1) % 4) * 240), this.snap(100 + Math.floor((count - 1) / 4) * 160));
                this.updateMinimap();
            },

            onConnectionCreated(c) {
                if (this.suppressEvents) return;
                const from = this.drawflowIdToNodeId[c.output_id];
                const to = this.drawflowIdToNodeId[c.input_id];
                if (! from || ! to) return;
                const id = 'edge_' + (this.graph.edges.length + 1) + '_' + Date.now().toString(36);
                this.graph.edges.push(this.normalizeEdge({ id, from, to }));
            },

            onConnectionRemoved(c) {
                if (this.suppressEvents) return;
                const from = this.drawflowIdToNodeId[c.output_id];
                const to = this.drawflowIdToNodeId[c.input_id];
                const idx = this.graph.edges.findIndex(e => e.from === from && e.to === to);
                if (idx !== -1) this.graph.edges.splice(idx, 1);
                if (this.selected && this.selected.kind === 'edge') this.selected = null;
                this.redrawOrthogonalConnections();
            },

            onNodeSelected(dfId) {
                const nodeId = this.drawflowIdToNodeId[dfId];
                if (nodeId) this.selected = { kind: 'node', id: nodeId };
                this.redrawOrthogonalConnections();
            },

            onNodeMoved(dfId) {
                const nodeData = this.editor.drawflow.drawflow[this.editor.module].data[dfId];
                if (! nodeData) return;
                nodeData.pos_x = this.snap(nodeData.pos_x);
                nodeData.pos_y = this.snap(nodeData.pos_y);
                const el = document.getElementById('node-' + dfId);
                if (el) {
                    el.style.left = nodeData.pos_x + 'px';
                    el.style.top = nodeData.pos_y + 'px';
                    this.editor.updateConnectionNodes('node-' + dfId);
                }
                this.redrawOrthogonalConnections();
                this.updateMinimap();
            },

            onConnectionSelected(c) {
                const from = this.drawflowIdToNodeId[c.output_id];
                const to = this.drawflowIdToNodeId[c.input_id];
                const edge = this.graph.edges.find(e => e.from === from && e.to === to);
                if (edge) {
                    this.selected = { kind: 'edge', id: edge.id };
                    this.redrawOrthogonalConnections();
                    this.$nextTick(() => this.reinitSelect2Widgets());
                }
            },

            findNode(id) { return this.graph.nodes.find(n => n.id === id); },
            findEdge(id) { return this.graph.edges.find(e => e.id === id); },

            removeSelectedNode() {
                const node = this.findNode(this.selected.id);
                if (! node) return;
                const dfId = this.nodeIdToDrawflowId[node.id];
                if (dfId) this.editor.removeNodeId('node-' + dfId);
                this.graph.nodes = this.graph.nodes.filter(n => n.id !== node.id);
                this.graph.edges = this.graph.edges.filter(e => e.from !== node.id && e.to !== node.id);
                this.selected = null;
                this.redrawOrthogonalConnections();
                this.updateMinimap();
            },

            removeSelectedEdge() {
                const edge = this.findEdge(this.selected.id);
                if (! edge) return;
                this.graph.edges = this.graph.edges.filter(e => e.id !== edge.id);
                this.selected = null;
                this.redrawOrthogonalConnections();
                // Also remove the visual connection, if it still exists.
                const fromDf = this.nodeIdToDrawflowId[edge.from];
                const toDf = this.nodeIdToDrawflowId[edge.to];
                if (fromDf && toDf) {
                    this.suppressEvents = true;
                    try { this.editor.removeSingleConnection(fromDf, toDf, 'output_1', 'input_1'); } catch (e) {}
                    this.suppressEvents = false;
                }
            },

        // ---------- Minimap ----------

            updateMinimap() {
                const inner = document.getElementById('workflow-minimap-inner');
                if (! inner || ! this.editor) return;

                const data = this.editor.drawflow.drawflow[this.editor.module].data;
                const boxes = [];
                let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;

                Object.keys(data).forEach(id => {
                    const n = data[id];
                    const el = document.getElementById('node-' + id);
                    const w = el ? el.offsetWidth : 150, h = el ? el.offsetHeight : 50;
                    boxes.push({ x: n.pos_x, y: n.pos_y, w, h });
                    minX = Math.min(minX, n.pos_x);
                    minY = Math.min(minY, n.pos_y);
                    maxX = Math.max(maxX, n.pos_x + w);
                    maxY = Math.max(maxY, n.pos_y + h);
                });

                if (! isFinite(minX)) {
                    inner.innerHTML = '';
                    return;
                }

                const pad = 30;
                minX -= pad; minY -= pad; maxX += pad; maxY += pad;
                const spanX = Math.max(maxX - minX, 1), spanY = Math.max(maxY - minY, 1);
                const scale = Math.min(inner.clientWidth / spanX, inner.clientHeight / spanY);

                inner.innerHTML = boxes.map(b => {
                    const x = (b.x - minX) * scale, y = (b.y - minY) * scale;
                    const w = Math.max(4, b.w * scale), h = Math.max(4, b.h * scale);
                    return `<div style="position:absolute;left:${x}px;top:${y}px;width:${w}px;height:${h}px;background:#7c3aed;opacity:.55;border-radius:2px;"></div>`;
                }).join('');
            },
    };
</script>
