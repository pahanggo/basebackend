/**
 * The selection, drawn on the overlay canvas.
 *
 * **The whole point is that changing a selection does not repaint features.**
 * S2 separated the canvases so this would be possible, and §14 makes it a
 * budget: a selection change must land in under 8 ms. Highlighting by
 * re-styling the feature canvas would instead rebuild every path in view —
 * tens of thousands of parcels — to change how three of them look.
 *
 * So the highlight is a second stroke over the top, walking only the selected
 * features. It costs what the selection costs, not what the viewport costs.
 *
 * It draws in the SAME projected space the feature canvas just used, handed
 * over by the renderer. Projecting each vertex through Leaflet instead would
 * be a function call and a trigonometric pair per point, on geometry that has
 * already been projected once this frame.
 */

const FILL = 'rgba(43, 108, 176, 0.22)';
const STROKE = '#2b6cb0';
const HALO = 'rgba(255, 255, 255, 0.9)';

export class SelectionLayer {
    /**
     * @param {Object} options
     * @param {Object} options.renderer
     * @param {Function} options.selection () => Set of selected feature ids
     */
    constructor({ renderer, selection }) {
        this.renderer = renderer;
        this.selection = selection;
        this.lastPaintMs = 0;
        this.lastDrawn = 0;

        // Registered before the measurements, so an annotation is never buried
        // under a highlight.
        renderer.setOverlayPainter('selection', (context, map, view) => this.paint(context, view));
    }

    invalidate() {
        this.renderer.schedule();
    }

    paint(context, view) {
        const selected = this.selection();

        if (!selected || selected.size === 0 || !view) {
            this.lastPaintMs = 0;
            this.lastDrawn = 0;

            return;
        }

        const started = performance.now();

        context.save();
        context.lineJoin = 'round';

        let drawn = 0;

        for (const layer of this.renderer.drawnLayers()) {
            drawn += this.paintLayer(context, layer, selected, view);
        }

        context.restore();

        this.lastPaintMs = performance.now() - started;
        this.lastDrawn = drawn;
    }

    paintLayer(context, layer, selected, { scale, originX, originY }) {
        const geometry = layer.geometry;

        if (!layer.visible || !geometry?.ids) {
            return 0;
        }

        const { coords, ringStarts, featStarts, ids } = geometry;
        const path = new Path2D();
        let drawn = 0;

        for (let f = 0; f < ids.length; f++) {
            if (!selected.has(ids[f])) {
                continue;
            }

            // `featStarts` indexes RINGS; `ringStarts` indexes VERTICES.
            for (let r = featStarts[f]; r < featStarts[f + 1]; r++) {
                const start = ringStarts[r];
                const end = ringStarts[r + 1];

                for (let v = start; v < end; v++) {
                    const x = coords[v * 2] * scale - originX;
                    const y = coords[v * 2 + 1] * scale - originY;

                    if (v === start) {
                        path.moveTo(x, y);
                    } else {
                        path.lineTo(x, y);
                    }
                }
            }

            drawn += 1;
        }

        if (drawn === 0) {
            return 0;
        }

        // One accumulated path per layer, so a selection of four hundred is
        // four canvas calls rather than twelve hundred. `evenodd` so a parcel
        // with a hole highlights as the shape it is.
        context.fillStyle = FILL;
        context.fill(path, 'evenodd');
        context.strokeStyle = HALO;
        context.lineWidth = 4;
        context.stroke(path);
        context.strokeStyle = STROKE;
        context.lineWidth = 2;
        context.stroke(path);

        return drawn;
    }
}
