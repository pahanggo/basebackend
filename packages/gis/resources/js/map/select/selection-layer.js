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

/**
 * **Not the layer's colour, and not a colour any layer is likely to be.**
 *
 * The first version highlighted in the same blue as the default vector style,
 * which meant a selected parcel on a default layer was a blue outline on a
 * blue outline — the highlight was drawn, measurably, and could not be seen. A
 * selection has to read as selected whatever the layer underneath is painted,
 * so it gets a colour of its own: magenta, which is neither the blue of a
 * default layer nor the amber the measurements use.
 *
 * The white halo does the rest of the work. Over a dark satellite tile or a
 * saturated land-use fill, a single-colour outline of any hue can vanish; two
 * strokes, light under dark, cannot.
 */
import { POLYGON } from '../geometry.js';

const FILL = 'rgba(213, 63, 140, 0.18)';
const STROKE = '#d53f8c';
const HALO = 'rgba(255, 255, 255, 0.95)';

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

        const { coords, ringStarts, featStarts, ids, types } = geometry;

        // **Two paths, for two different reasons.** The outline accumulates
        // across the whole layer, because a stroke of many subpaths is one
        // canvas call and looks the same either way.
        //
        // The FILL cannot. `evenodd` is what makes a parcel with a hole
        // highlight as the shape it is, and on one shared path that same rule
        // makes two overlapping features cancel each other out — a circle
        // behind a rectangle came out with a white bite taken out of it. So
        // each feature is filled on its own, and only polygons are filled at
        // all: canvas closes an open path before filling it, so a filled line
        // is a filled triangle.
        const outline = new Path2D();
        let drawn = 0;

        context.fillStyle = FILL;

        for (let f = 0; f < ids.length; f++) {
            if (!selected.has(ids[f])) {
                continue;
            }

            const shape = new Path2D();

            // `featStarts` indexes RINGS; `ringStarts` indexes VERTICES.
            for (let r = featStarts[f]; r < featStarts[f + 1]; r++) {
                const start = ringStarts[r];
                const end = ringStarts[r + 1];

                for (let v = start; v < end; v++) {
                    const x = coords[v * 2] * scale - originX;
                    const y = coords[v * 2 + 1] * scale - originY;

                    if (v === start) {
                        shape.moveTo(x, y);
                    } else {
                        shape.lineTo(x, y);
                    }
                }
            }

            if (types[f] === POLYGON) {
                context.fill(shape, 'evenodd');
            }

            outline.addPath(shape);
            drawn += 1;
        }

        if (drawn === 0) {
            return 0;
        }

        const path = outline;

        context.setLineDash([]);
        context.strokeStyle = HALO;
        context.lineWidth = 5;
        context.stroke(path);

        context.strokeStyle = STROKE;
        context.lineWidth = 2.5;
        context.stroke(path);

        // A dashed white overlay on top of the magenta. It costs one more
        // stroke and makes the selection legible without relying on colour at
        // all, which is what a reader who cannot separate magenta from the
        // layer's own hue needs (specification §18).
        context.setLineDash([6, 5]);
        context.strokeStyle = 'rgba(255, 255, 255, 0.9)';
        context.lineWidth = 2.5;
        context.stroke(path);
        context.setLineDash([]);

        return drawn;
    }
}
