<script>
    // Orthogonal (Manhattan-style) connector routing/rendering — Drawflow
    // draws smooth bezier curves by default, this overrides every
    // connection's path with right-angle elbows, obstacle avoidance,
    // endpoint reconnect handles, and the edge label. Merged onto the main
    // workflowDesigner() Alpine component in edit.blade.php.
    const WF_CONNECTIONS_MIXIN = {
            // ---------- Orthogonal (Manhattan-style) connector rendering ----------
            // Drawflow draws smooth bezier curves by default; this overrides each
            // connection's path with a right-angle elbow so it matches box-style
            // flow-chart UIs (with arrowheads via the shared <marker> defined in
            // the Blade template).

            /** Shared by both finished connections and the in-progress one being
             *  dragged out, so both use identical routing rules. */
            /** Builds the SVG path 'd' from an ordered list of {x,y} points. */
            pointsToPath(points) {
                return 'M ' + points.map(p => `${p.x} ${p.y}`).join(' L ');
            },

            /** Axis-aligned segment/rectangle overlap test — every segment we
             *  ever draw is purely vertical or purely horizontal, so this
             *  never needs general line-rect intersection math. */
            segmentHitsRect(ax, ay, bx, by, rect) {
                if (ax === bx) {
                    const lo = Math.min(ay, by), hi = Math.max(ay, by);
                    return ax >= rect.left && ax <= rect.right && hi >= rect.top && lo <= rect.bottom;
                }
                if (ay === by) {
                    const lo = Math.min(ax, bx), hi = Math.max(ax, bx);
                    return ay >= rect.top && ay <= rect.bottom && hi >= rect.left && lo <= rect.right;
                }
                return false;
            },

            pathHitsObstacle(points, obstacles) {
                for (let i = 0; i < points.length - 1; i++) {
                    for (const rect of obstacles) {
                        if (this.segmentHitsRect(points[i].x, points[i].y, points[i + 1].x, points[i + 1].y, rect)) {
                            return true;
                        }
                    }
                }
                return false;
            },

            /** Bounding boxes (with a small margin) of every node except the
             *  ones given — i.e. every node a connector could accidentally
             *  cut through besides the two it's actually meant to touch. */
            obstacleRectsExcluding(excludeIds) {
                const data = this.editor.drawflow.drawflow[this.editor.module].data;
                const margin = 10;
                const rects = [];
                Object.keys(data).forEach(dfId => {
                    const nodeId = this.drawflowIdToNodeId[dfId];
                    if (! nodeId || excludeIds.includes(nodeId)) return;
                    const el = document.getElementById('node-' + dfId);
                    const n = data[dfId];
                    if (! el || ! n) return;
                    rects.push({
                        left: n.pos_x - margin,
                        right: n.pos_x + el.offsetWidth + margin,
                        top: n.pos_y - margin,
                        bottom: n.pos_y + el.offsetHeight + margin,
                    });
                });
                return rects;
            },

            orthogonalPath(x1, y1, x2, y2, lane = 0, obstacles = []) {
                // Connectors always leave/enter perpendicular to the node edge
                // — vertically here, since ports sit on the top/bottom faces
                // (output at the bottom, input at the top). The simple 2-bend
                // "jog at the midpoint" path only looks right when that gives
                // both the exit and entrance a real stub to travel along
                // before turning; below a minimum length (or when the target
                // sits above the source, making the stub negative) it
                // collapses into an ugly near-instant turn right at the port
                // — so fall back to routing around with fixed-length stubs instead.
                const minStub = 10;
                const midY = (y1 + y2) / 2;
                const exitStub = midY - y1;
                const entranceStub = y2 - midY;

                if (exitStub >= minStub && entranceStub >= minStub) {
                    const straight = [{ x: x1, y: y1 }, { x: x1, y: midY }, { x: x2, y: midY }, { x: x2, y: y2 }];
                    if (! this.pathHitsObstacle(straight, obstacles)) {
                        return this.pointsToPath(straight);
                    }
                    // The direct jog would cut through some other node that
                    // just happens to sit between source and target — fall
                    // through to the routed-around shape below instead.
                }

                const stub = 24;
                // When both ports sit on (or near) the same column — the
                // common case for a "return"/backward edge between two boxes
                // stacked vertically — the midpoint-average jog degenerates
                // to a near-zero horizontal offset, so the "vertical" leg
                // cuts straight through both node bodies instead of routing
                // around them. Detour to the side of the column by a fixed
                // clearance instead whenever the columns are too close to
                // leave real room to route between.
                // `lane` (assigned by computeDetourLanes, for edges whose
                // detour ranges overlap on screen) pushes this one further
                // over so two such edges never trace the exact same pixels —
                // otherwise clicking one could hit whichever happens to be on
                // top in the DOM, selecting a completely unrelated edge.
                const columnClearance = 100;
                const laneStep = 24;
                const baseMidX = Math.abs(x1 - x2) < columnClearance
                    ? Math.max(x1, x2) + columnClearance
                    : (x1 + x2) / 2;
                const p1y = y1 + stub;
                const p2y = y2 - stub;

                // If a plain node (not involved in this edge) still sits in
                // the way of that detour column, keep pushing further over —
                // an unrelated third node can easily land between two edge
                // endpoints in a busy diagram, and a fixed clearance alone
                // only ever accounts for the source/target pair.
                let points;
                for (let attempt = 0; attempt < 8; attempt++) {
                    const midX = baseMidX + lane * laneStep + attempt * laneStep;
                    points = [
                        { x: x1, y: y1 }, { x: x1, y: p1y }, { x: midX, y: p1y },
                        { x: midX, y: p2y }, { x: x2, y: p2y }, { x: x2, y: y2 },
                    ];
                    if (! this.pathHitsObstacle(points, obstacles)) break;
                }
                return this.pointsToPath(points);
            },

            /** Assigns each edge that needs the routed (non-diagonal) fallback
             *  path a "lane" — greedy interval-overlap stacking, like calendar
             *  event layout — so two detouring edges whose vertical ranges
             *  overlap never end up tracing the exact same line on screen. */
            computeDetourLanes() {
                const data = this.editor.drawflow.drawflow[this.editor.module].data;
                const minStub = 10;
                const stub = 24;
                const items = [];

                this.graph.edges.forEach(edge => {
                    const fromDf = this.nodeIdToDrawflowId[edge.from];
                    const toDf = this.nodeIdToDrawflowId[edge.to];
                    const outNode = data[fromDf], inNode = data[toDf];
                    const outEl = document.getElementById('node-' + fromDf);
                    const inEl = document.getElementById('node-' + toDf);
                    if (! outNode || ! inNode || ! outEl || ! inEl) return;

                    const y1 = outNode.pos_y + outEl.offsetHeight;
                    const y2 = inNode.pos_y;
                    const midY = (y1 + y2) / 2;
                    if (midY - y1 >= minStub && y2 - midY >= minStub) return; // 2-bend path, no detour

                    const p1y = y1 + stub, p2y = y2 - stub;
                    items.push({ id: edge.id, min: Math.min(p1y, p2y), max: Math.max(p1y, p2y) });
                });

                items.sort((a, b) => a.min - b.min);
                const laneEnds = [];
                const lanes = {};
                items.forEach(item => {
                    let lane = laneEnds.findIndex(end => end <= item.min);
                    if (lane === -1) { lane = laneEnds.length; laneEnds.push(item.max); }
                    else { laneEnds[lane] = item.max; }
                    lanes[item.id] = lane;
                });

                this.edgeDetourLane = lanes;
            },

            redrawOrthogonalConnections() {
                this.computeDetourLanes();
                // Handles are re-created below only for the currently selected
                // edge — drop stale ones from whatever was selected before.
                document.querySelectorAll('.wf-reconnect-handle').forEach(h => h.remove());
                const data = this.editor.drawflow.drawflow[this.editor.module].data;
                document.querySelectorAll('#workflow-canvas .connection').forEach(svg => {
                    const inClass = Array.from(svg.classList).find(c => c.startsWith('node_in_'));
                    const outClass = Array.from(svg.classList).find(c => c.startsWith('node_out_'));
                    if (! inClass || ! outClass) return;

                    const inId = inClass.replace('node_in_node-', '');
                    const outId = outClass.replace('node_out_node-', '');
                    const outNode = data[outId], inNode = data[inId];
                    const outEl = document.getElementById('node-' + outId);
                    const inEl = document.getElementById('node-' + inId);
                    if (! outNode || ! inNode || ! outEl || ! inEl) return;

                    const x1 = outNode.pos_x + outEl.offsetWidth / 2, y1 = outNode.pos_y + outEl.offsetHeight;
                    const x2 = inNode.pos_x + inEl.offsetWidth / 2, y2 = inNode.pos_y;

                    const fromNodeId = this.drawflowIdToNodeId[outId];
                    const toNodeId = this.drawflowIdToNodeId[inId];
                    const edge = this.graph.edges.find(e => e.from === fromNodeId && e.to === toNodeId);
                    const lane = edge ? (this.edgeDetourLane[edge.id] || 0) : 0;
                    const obstacles = this.obstacleRectsExcluding([fromNodeId, toNodeId]);

                    const path = svg.querySelector('.main-path');
                    if (path) {
                        const d = this.orthogonalPath(x1, y1, x2, y2, lane, obstacles);
                        path.setAttribute('d', d);
                        this.ensureHitPath(svg, d);
                    }

                    if (edge) {
                        this.updateConnectionLabel(svg, edge);
                        if (path && this.selected?.kind === 'edge' && this.selected.id === edge.id) {
                            // Offset a little way along the path rather than
                            // sitting exactly on the endpoint — Drawflow's own
                            // port dot lives right there with a higher
                            // z-index, and would swallow the click before it
                            // ever reached our handle.
                            const length = path.getTotalLength();
                            const offset = Math.min(20, length / 3);
                            const fromPt = length ? path.getPointAtLength(offset) : { x: x1, y: y1 };
                            const toPt = length ? path.getPointAtLength(length - offset) : { x: x2, y: y2 };
                            this.renderReconnectHandles(svg, edge, fromPt.x, fromPt.y, toPt.x, toPt.y);
                        }
                    }
                });
                this.updateSelectedConnectionStyling();
            },

            /** Draggable grab points at a selected connection's two endpoints
             *  — see startReconnect/updateReconnectPath/finishReconnect. */
            renderReconnectHandles(svg, edge, x1, y1, x2, y2) {
                ['from', 'to'].forEach(end => {
                    let handle = svg.querySelector(`.wf-reconnect-handle[data-end="${end}"]`);
                    if (! handle) {
                        handle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                        handle.setAttribute('class', 'wf-reconnect-handle');
                        handle.setAttribute('r', 6);
                        handle.dataset.end = end;
                        svg.appendChild(handle);
                    }
                    handle.dataset.edgeId = edge.id;
                    handle.setAttribute('cx', end === 'from' ? x1 : x2);
                    handle.setAttribute('cy', end === 'from' ? y1 : y2);
                });
            },

            startReconnect(handleEl) {
                const svg = handleEl.closest('.connection');
                const edge = this.findEdge(handleEl.dataset.edgeId);
                if (! edge || ! svg) return;
                this.reconnecting = { edgeId: edge.id, end: handleEl.dataset.end, svg };
            },

            /** Redraws the dragged connection live with the grabbed end
             *  following the cursor, and highlights whatever node it's
             *  currently hovering as the drop target. */
            updateReconnectPath(e) {
                const edge = this.findEdge(this.reconnecting.edgeId);
                if (! edge) return;

                const data = this.editor.drawflow.drawflow[this.editor.module].data;
                const fromDf = this.nodeIdToDrawflowId[edge.from];
                const toDf = this.nodeIdToDrawflowId[edge.to];
                const fromNode = data[fromDf], toNode = data[toDf];
                const fromEl = document.getElementById('node-' + fromDf);
                const toEl = document.getElementById('node-' + toDf);
                if (! fromNode || ! toNode || ! fromEl || ! toEl) return;

                const precanvasRect = this.editor.precanvas.getBoundingClientRect();
                const mouseX = (e.clientX - precanvasRect.left) / this.editor.zoom;
                const mouseY = (e.clientY - precanvasRect.top) / this.editor.zoom;

                let x1 = fromNode.pos_x + fromEl.offsetWidth / 2, y1 = fromNode.pos_y + fromEl.offsetHeight;
                let x2 = toNode.pos_x + toEl.offsetWidth / 2, y2 = toNode.pos_y;
                if (this.reconnecting.end === 'from') { x1 = mouseX; y1 = mouseY; }
                else { x2 = mouseX; y2 = mouseY; }

                const path = this.reconnecting.svg.querySelector('.main-path');
                if (path) path.setAttribute('d', this.orthogonalPath(x1, y1, x2, y2));

                const hoverNode = document.elementFromPoint(e.clientX, e.clientY)?.closest('.drawflow-node');
                document.querySelectorAll('.drawflow-node.wf-drop-target').forEach(el => el.classList.remove('wf-drop-target'));
                if (hoverNode) hoverNode.classList.add('wf-drop-target');
            },

            /** Drops onto a node reattaches that end of the edge to it (a
             *  drop that would make the edge point at itself, or anywhere
             *  that isn't a node, is treated as a cancel); either way the
             *  connection is redrawn from the graph's own state afterward,
             *  which snaps a cancelled drag straight back to where it was. */
            finishReconnect(e) {
                if (! this.reconnecting) return;
                const { edgeId, end } = this.reconnecting;
                this.reconnecting = null;
                document.querySelectorAll('.drawflow-node.wf-drop-target').forEach(el => el.classList.remove('wf-drop-target'));

                const edge = this.findEdge(edgeId);
                const targetEl = document.elementFromPoint(e.clientX, e.clientY)?.closest('.drawflow-node');
                if (edge && targetEl) {
                    const newNodeId = this.drawflowIdToNodeId[targetEl.id.replace('node-', '')];
                    const otherEnd = end === 'from' ? edge.to : edge.from;
                    if (newNodeId && newNodeId !== otherEnd) {
                        const oldFromDf = this.nodeIdToDrawflowId[edge.from];
                        const oldToDf = this.nodeIdToDrawflowId[edge.to];
                        this.suppressEvents = true;
                        try { this.editor.removeSingleConnection(oldFromDf, oldToDf, 'output_1', 'input_1'); } catch (err) {}

                        if (end === 'from') edge.from = newNodeId; else edge.to = newNodeId;

                        try { this.editor.addConnection(this.nodeIdToDrawflowId[edge.from], this.nodeIdToDrawflowId[edge.to], 'output_1', 'input_1'); } catch (err) {}
                        this.suppressEvents = false;
                    }
                }

                this.redrawOrthogonalConnections();
            },

            /** Keeps each connection SVG's `wf-selected` class in sync with
             *  `this.selected`. Deliberately not relying on Drawflow's own
             *  "selected" class — it lands on whichever literal <path> element
             *  was actually clicked, which may be our wide invisible hit-path
             *  rather than the visible line, so it can't drive visual styling. */
            updateSelectedConnectionStyling() {
                document.querySelectorAll('#workflow-canvas .connection').forEach(svg => {
                    const inClass = Array.from(svg.classList).find(c => c.startsWith('node_in_'));
                    const outClass = Array.from(svg.classList).find(c => c.startsWith('node_out_'));
                    if (! inClass || ! outClass) return;
                    const fromNodeId = this.drawflowIdToNodeId[outClass.replace('node_out_node-', '')];
                    const toNodeId = this.drawflowIdToNodeId[inClass.replace('node_in_node-', '')];
                    const edge = this.graph.edges.find(e => e.from === fromNodeId && e.to === toNodeId);
                    const isSelected = !! (edge && this.selected?.kind === 'edge' && this.selected.id === edge.id);
                    svg.classList.toggle('wf-selected', isSelected);
                    // SVGs stack in DOM order, so a connection that overlaps
                    // another one can end up visually underneath it — moving
                    // the selected one to be the last child of its parent
                    // brings it (and its label/reconnect handles) to the top.
                    if (isSelected) svg.parentNode.appendChild(svg);
                });
            },

            /** Keeps a wide, invisible twin of the connection's path in sync —
             *  see the .wf-hit-path CSS comment for why this exists. Always
             *  appended (never inserted first) so `svg.querySelector('.main-path')`
             *  elsewhere keeps resolving to Drawflow's own original element. */
            ensureHitPath(svg, d) {
                let hit = svg.querySelector('.wf-hit-path');
                if (! hit) {
                    hit = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                    hit.setAttribute('class', 'main-path wf-hit-path');
                    svg.appendChild(hit);
                }
                hit.setAttribute('d', d);
            },

            /** Renders (or updates) the edge's label, centered along the middle
             *  of its already-drawn path — using getPointAtLength so it stays
             *  centered regardless of how many bends the orthogonal route took. */
            updateConnectionLabel(svg, edge) {
                const path = svg.querySelector('.main-path');
                if (! path) return;
                const text = edge.button_label || edge.name || edge.id;

                let bg = svg.querySelector('.wf-edge-label-bg');
                let label = svg.querySelector('.wf-edge-label');
                if (! label) {
                    bg = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                    bg.setAttribute('class', 'wf-edge-label-bg');
                    svg.appendChild(bg);
                    label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                    label.setAttribute('class', 'wf-edge-label');
                    svg.appendChild(label);
                }

                label.textContent = text;

                const length = path.getTotalLength();
                const mid = length ? path.getPointAtLength(length / 2) : { x: 0, y: 0 };
                label.setAttribute('x', mid.x);
                label.setAttribute('y', mid.y);

                const bbox = label.getBBox();
                const padX = 4, padY = 2;
                bg.setAttribute('x', bbox.x - padX);
                bg.setAttribute('y', bbox.y - padY);
                bg.setAttribute('width', bbox.width + padX * 2);
                bg.setAttribute('height', bbox.height + padY * 2);
            },

            /** Keeps the connector angled while it's still being dragged out
             *  from an output dot, before it's dropped onto a target. */
            updateDraggingConnectionPath(e) {
                const outEl = this.editor.ele_selected ? this.editor.ele_selected.closest('.drawflow-node') : null;
                if (! outEl || ! this.editor.connection_ele) return;

                const outId = outEl.id.replace('node-', '');
                const outNode = this.editor.drawflow.drawflow[this.editor.module].data[outId];
                if (! outNode) return;

                const precanvasRect = this.editor.precanvas.getBoundingClientRect();
                const x1 = outNode.pos_x + outEl.offsetWidth / 2;
                const y1 = outNode.pos_y + outEl.offsetHeight;
                const x2 = (e.clientX - precanvasRect.left) / this.editor.zoom;
                const y2 = (e.clientY - precanvasRect.top) / this.editor.zoom;

                const path = this.editor.connection_ele.querySelector('.main-path');
                if (path) path.setAttribute('d', this.orthogonalPath(x1, y1, x2, y2));
            },
    };
</script>
