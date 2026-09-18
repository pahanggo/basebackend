/**
 * Parsing and index bounds, off the main thread.
 *
 * The worker stays dependency-free — no Turf, no Leaflet, no rbush — so its
 * only job is arithmetic over typed arrays. Results go back as transferable
 * `ArrayBuffer`s, so the handoff costs nothing: the buffers are moved, not
 * cloned.
 */

import { buildGeometry, transferables } from '../geometry.js';
import { decodeGis1 } from '../../data/gis1.js';

self.onmessage = (event) => {
    const { id, buffer, binary } = event.data;

    try {
        // Binary is viewed; GeoJSON is parsed. Both end as the same arrays,
        // which is what lets the renderer above this know nothing about either.
        const decoded = binary ? decodeGis1(buffer) : null;
        const collection = binary ? null : JSON.parse(new TextDecoder().decode(buffer));
        const geometry = binary ? decoded.geometry : buildGeometry(collection);

        // Binary arrives as one buffer with every array a view onto it, so it
        // is transferred once and re-viewed on the other side. GeoJSON built
        // seven separate arrays, which are transferred as seven buffers.
        const payload = binary
            ? {
                id,
                binary: true,
                // The response buffer carries every array except quantised
                // coordinates, which were expanded into one of their own.
                buffer: decoded.layout.quantised ? buffer : decoded.geometry.coords.buffer,
                coordsBuffer: decoded.layout.quantised ? decoded.geometry.coords.buffer : null,
                layout: decoded.layout,
                properties: decoded.properties,
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
                cull: collection ? collection.cull || null : null,
            };

        const transfer = binary ? [payload.buffer] : transferables(geometry);

        if (binary && payload.coordsBuffer) {
            transfer.push(payload.coordsBuffer);
        }

        self.postMessage(payload, transfer);
    } catch (error) {
        self.postMessage({ id, error: String(error && error.message ? error.message : error) });
    }
};
