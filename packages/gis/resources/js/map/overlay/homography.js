/**
 * The projective transform that warps an overlay image onto four corners.
 *
 * **Projective, not affine, and that is a usability decision before it is a
 * mathematical one.** An affine fit takes three corners and derives the fourth,
 * so the image is always a parallelogram: it cannot represent a plan scanned or
 * photographed at an angle, and — worse — dragging one corner silently moves
 * another the user did not touch. Four independent corners need eight degrees
 * of freedom, which is a homography (specification section 13).
 *
 * Eight unknowns, so an 8x8 solve per drag. That sounds expensive and is not:
 * it is a few hundred floating-point operations, against a budget of 8 ms per
 * frame. The GPU then does the actual warping, because the result is handed to
 * CSS as a `matrix3d` rather than drawn.
 */

/**
 * Solve `A x = b` by Gaussian elimination with partial pivoting.
 *
 * Partial pivoting is not optional here. Without it a corner dragged onto the
 * same row or column as another produces a zero pivot, and the solve returns
 * `Infinity` rather than failing — which renders as an image that vanishes
 * mid-drag and comes back when the pointer moves on.
 *
 * @param {Array<Array<number>>} a square matrix, consumed in place
 * @param {Array<number>} b right-hand side, consumed in place
 * @returns {Array<number>|null} the solution, or null when the system is singular
 */
export function solve(a, b) {
    const n = b.length;

    for (let col = 0; col < n; col++) {
        let pivot = col;

        for (let row = col + 1; row < n; row++) {
            if (Math.abs(a[row][col]) > Math.abs(a[pivot][col])) {
                pivot = row;
            }
        }

        if (Math.abs(a[pivot][col]) < 1e-12) {
            return null;
        }

        [a[col], a[pivot]] = [a[pivot], a[col]];
        [b[col], b[pivot]] = [b[pivot], b[col]];

        for (let row = col + 1; row < n; row++) {
            const factor = a[row][col] / a[col][col];

            if (factor === 0) {
                continue;
            }

            for (let k = col; k < n; k++) {
                a[row][k] -= factor * a[col][k];
            }

            b[row] -= factor * b[col];
        }
    }

    const x = new Array(n).fill(0);

    for (let row = n - 1; row >= 0; row--) {
        let sum = b[row];

        for (let k = row + 1; k < n; k++) {
            sum -= a[row][k] * x[k];
        }

        x[row] = sum / a[row][row];
    }

    return x;
}

/**
 * The 3x3 homography taking each `from` point to the matching `to` point.
 *
 * Both arrays are four `[x, y]` pairs in the same order. The result is row
 * major, with `h33` fixed at 1 — a homography is defined up to scale, so one
 * element has to be pinned or the system is underdetermined.
 *
 * @returns {Array<number>|null} nine numbers, or null when the quad is degenerate
 */
export function homography(from, to) {
    const a = [];
    const b = [];

    for (let i = 0; i < 4; i++) {
        const [x, y] = from[i];
        const [u, v] = to[i];

        // u = (h11 x + h12 y + h13) / (h31 x + h32 y + 1), rearranged so the
        // denominator's terms move to the left-hand side.
        a.push([x, y, 1, 0, 0, 0, -u * x, -u * y]);
        b.push(u);
        a.push([0, 0, 0, x, y, 1, -v * x, -v * y]);
        b.push(v);
    }

    const h = solve(a, b);

    return h === null ? null : [h[0], h[1], h[2], h[3], h[4], h[5], h[6], h[7], 1];
}

/**
 * A homography as a CSS `matrix3d`.
 *
 * `matrix3d` takes sixteen values in COLUMN-major order, and the third row and
 * column are the identity's because the transform is planar. The perspective
 * terms land in the fourth row, which is the whole reason this is `matrix3d`
 * and not `matrix`: a 2D CSS matrix has no way to express them, and would
 * silently drop them.
 */
export function toMatrix3d(h) {
    const [a, b, c, d, e, f, g, i] = h;

    return `matrix3d(${[
        a, d, 0, g,
        b, e, 0, i,
        0, 0, 1, 0,
        c, f, 0, 1,
    ].join(', ')})`;
}

/**
 * Apply a homography to one point.
 *
 * Only used for checking and for hit-testing the image body; the rendering
 * path never calls it, because the GPU does that work.
 */
export function project(h, [x, y]) {
    const w = h[6] * x + h[7] * y + h[8];

    return [
        (h[0] * x + h[1] * y + h[2]) / w,
        (h[3] * x + h[4] * y + h[5]) / w,
    ];
}
