/**
 * Parsing, index bounds and simplification, off the main thread.
 *
 * The worker stays dependency-free — no Turf, no Leaflet, no rbush — so its
 * only job is arithmetic over typed arrays. Results go back as transferable
 * `ArrayBuffer`s, so the handoff costs nothing: the buffers are moved, not
 * cloned.
 */

import { buildGeometry, transferables } from '../geometry.js';
import { simplifyLargeFeatures } from '../simplify.js';
import { decodeGis1 } from '../../data/gis1.js';

self.onmessage = (event) => {
    const { id, buffer, simplifyThreshold, binary } = event.data;

    try {
        // Binary is viewed; GeoJSON is parsed. Both end as the same arrays,
        // which is what lets the renderer above this know nothing about either.
        const decoded = binary ? decodeGis1(buffer) : null;
        const collection = binary ? null : JSON.parse(new TextDecoder().decode(buffer));
        const geometry = binary ? decoded.geometry : buildGeometry(collection);

        const simplified = simplifyThreshold > 0
            ? simplifyLargeFeatures(geometry, simplifyThreshold)
            : null;

        // Binary arrives as one buffer with every array a view onto it, so it
        // is transferred once and re-viewed on the other side. GeoJSON built
        // seven separate arrays, which are transferred as seven buffers.
        const payload = binary
            ? {
                id,
                binary: true,
                buffer: decoded.geometry.coords.buffer,
                layout: decoded.layout,
                properties: decoded.properties,
                keep: simplified ? simplified.buffer : null,
                cull: null,
            }
            : {
                id,
                binary: false,
                count: geometry.count,
                coords: geometry.coords.buffer,
                ringStarts: geometry.ringStarts.buffer,
                featStarts: geometry.featStarts.buffer,
                types: geometry.types.buffer,
                bbox: geometry.bbox.buffer,
                ids: geometry.ids.buffer,
                area: geometry.area.buffer,
                keep: simplified ? simplified.buffer : null,
                cull: collection ? collection.cull || null : null,
            };

        const transfer = binary ? [payload.buffer] : transferables(geometry);

        if (simplified) {
            transfer.push(simplified.buffer);
        }

        self.postMessage(payload, transfer);
    } catch (error) {
        self.postMessage({ id, error: String(error && error.message ? error.message : error) });
    }
};
