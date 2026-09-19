/**
 * Reading and writing a position four ways.
 *
 * Decimal degrees is what everything stores. The other three are what people
 * have written down: a title document gives degrees, minutes and seconds, a
 * survey plan gives UTM, and a field team on a radio gives MGRS. Typing any of
 * them into the same box has to work, because otherwise the user converts by
 * hand and the conversion is where the mistake goes (specification §11).
 *
 * **Everything here is WGS84**, the same datum the package stores. A UTM
 * easting that came off a plan drawn on a different datum is in the wrong
 * place by a few hundred metres, and nothing here can detect that — it is a
 * question about the paper, not about the arithmetic.
 */

const RAD = Math.PI / 180;

/** WGS84. */
const A = 6378137.0;
const F = 1 / 298.257223563;
const E2 = F * (2 - F);
const K0 = 0.9996;                 // UTM scale factor on the central meridian

/** The MGRS column letters, by zone group. `I` and `O` are never used. */
const COLUMNS = ['ABCDEFGH', 'JKLMNPQR', 'STUVWXYZ'];
const ROWS = ['ABCDEFGHJKLMNPQRSTUV', 'FGHJKLMNPQRSTUVABCDE'];

/** Latitude bands, south to north. `I` and `O` are skipped here too. */
const BANDS = 'CDEFGHJKLMNPQRSTUVWX';

/**
 * Parse whatever the user typed, or null.
 *
 * Tried in order of how distinctive each format is: MGRS has letters in a
 * shape nothing else does, UTM names its zone, DMS has the marks, and decimal
 * is what is left. Guessing wrong is worse than refusing, so each parser is
 * strict about its own shape.
 *
 * @returns {{lng: number, lat: number, format: string}|null}
 */
export function parse(text) {
    const value = String(text ?? '').trim();

    if (value === '') {
        return null;
    }

    return parseMgrs(value) ?? parseUtm(value) ?? parseDms(value) ?? parseDecimal(value);
}

/**
 * `3.8077, 103.3260` — latitude first, as every map reads it out.
 *
 * **Either order is accepted, and the ambiguity is settled without guessing
 * where it can be.** A value beyond ±90 can only be a longitude, so
 * `103.326, 3.8077` is unambiguous and is read that way. Where both numbers
 * could be either, latitude comes first: that is what every mapping service
 * prints and what every pasted URL contains.
 */
export function parseDecimal(text) {
    const parts = text.split(/[,\s]+/).filter(Boolean);

    if (parts.length !== 2 || parts.some((part) => !/^-?\d+(\.\d+)?$/.test(part))) {
        return null;
    }

    const a = Number(parts[0]);
    const b = Number(parts[1]);

    if (Math.abs(a) > 90 && Math.abs(b) <= 90) {
        return inRange(a, b) ? { lng: a, lat: b, format: 'decimal' } : null;
    }

    return inRange(b, a) ? { lng: b, lat: a, format: 'decimal' } : null;
}

/** `3°48'27.7"N 103°19'33.6"E`, in any of the usual punctuations. */
export function parseDms(text) {
    const pattern = /(\d+)\s*[°d:\s]\s*(\d+(?:\.\d+)?)?\s*['m:\s]?\s*(\d+(?:\.\d+)?)?\s*["s]?\s*([NSEW])/gi;
    const found = [...text.matchAll(pattern)];

    if (found.length !== 2) {
        return null;
    }

    const values = {};

    for (const [, d, m, s, hemisphere] of found) {
        const decimal = Number(d) + Number(m ?? 0) / 60 + Number(s ?? 0) / 3600;
        const letter = hemisphere.toUpperCase();

        values[letter === 'N' || letter === 'S' ? 'lat' : 'lng'] =
            letter === 'S' || letter === 'W' ? -decimal : decimal;
    }

    if (values.lat === undefined || values.lng === undefined) {
        return null;
    }

    return inRange(values.lng, values.lat) ? { ...values, format: 'dms' } : null;
}

/** `48N 314108 421052` — zone, hemisphere, easting, northing. */
export function parseUtm(text) {
    const match = text.trim().match(/^(\d{1,2})\s*([NS])\s+(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)$/i);

    if (!match) {
        return null;
    }

    const zone = Number(match[1]);

    if (zone < 1 || zone > 60) {
        return null;
    }

    const point = utmToLatLng(zone, match[2].toUpperCase() === 'N', Number(match[3]), Number(match[4]));

    return point && inRange(point.lng, point.lat) ? { ...point, format: 'utm' } : null;
}

/** `48NEG1410821052` or `48N EG 14108 21052`. */
export function parseMgrs(text) {
    const cleaned = text.replace(/\s+/g, '').toUpperCase();
    const match = cleaned.match(/^(\d{1,2})([C-HJ-NP-X])([A-HJ-NP-Z])([A-HJ-NP-V])(\d+)$/);

    if (!match || match[5].length % 2 !== 0) {
        return null;
    }

    const zone = Number(match[1]);
    const band = match[2];
    const digits = match[5];
    const half = digits.length / 2;

    // A ten-digit reference is metres; a four-digit one is kilometres. The
    // multiplier is what the omitted trailing zeros would have been.
    const precision = 10 ** (5 - half);
    const columnSet = COLUMNS[(zone - 1) % 3];
    const rowSet = ROWS[(zone - 1) % 2];
    const column = columnSet.indexOf(match[3]);
    const row = rowSet.indexOf(match[4]);

    if (column === -1 || row === -1) {
        return null;
    }

    const easting = (column + 1) * 100_000 + Number(digits.slice(0, half)) * precision;
    const bandIndex = BANDS.indexOf(band);

    if (bandIndex === -1) {
        return null;
    }

    // The 100 km row letters repeat every 2,000 km, so the band says which
    // repetition is meant. Without it a reference is ambiguous by that much.
    const bandSouth = (bandIndex - 10) * 8;
    const northBase = row * 100_000 + Number(digits.slice(half)) * precision;
    const approximate = latLngToUtm(bandSouth + 4, (zone - 1) * 6 - 180 + 3);
    const cycles = Math.round((approximate.northing - northBase) / 2_000_000);
    const northing = northBase + cycles * 2_000_000;

    const point = utmToLatLng(zone, bandIndex >= BANDS.indexOf('N'), easting, northing);

    return point && inRange(point.lng, point.lat) ? { ...point, format: 'mgrs' } : null;
}

/** `3°48'27.7"N 103°19'33.6"E` */
export function formatDms(lng, lat) {
    return `${dms(lat, 'NS')} ${dms(lng, 'EW')}`;
}

/** `48N 314108 421052` */
export function formatUtm(lng, lat) {
    const { zone, easting, northing } = latLngToUtm(lat, lng);

    return `${zone}${lat >= 0 ? 'N' : 'S'} ${Math.round(easting)} ${Math.round(northing)}`;
}

/**
 * `48N EG 14108 21052`, at the requested number of digits per axis.
 *
 * Five digits is metre precision, which is what a survey wants; a field
 * report is usually three, and shortening is the point of the format.
 */
export function formatMgrs(lng, lat, digits = 5) {
    const { zone, easting, northing } = latLngToUtm(lat, lng);
    const band = BANDS[Math.floor((Math.max(-80, Math.min(84, lat)) + 80) / 8)];
    const column = COLUMNS[(zone - 1) % 3][Math.floor(easting / 100_000) - 1];
    const row = ROWS[(zone - 1) % 2][Math.floor(northing / 100_000) % 20];

    const scale = 10 ** (5 - digits);
    const e = String(Math.floor((easting % 100_000) / scale)).padStart(digits, '0');
    const n = String(Math.floor((northing % 100_000) / scale)).padStart(digits, '0');

    return `${zone}${band} ${column}${row} ${e} ${n}`;
}

/** Longitude and latitude to a UTM zone, easting and northing. */
export function latLngToUtm(lat, lng, forceZone = null) {
    const zone = forceZone ?? Math.floor((lng + 180) / 6) + 1;
    const centralMeridian = (zone - 1) * 6 - 180 + 3;

    const phi = lat * RAD;
    const lambda = (lng - centralMeridian) * RAD;

    const n = A / Math.sqrt(1 - E2 * Math.sin(phi) ** 2);
    const t = Math.tan(phi) ** 2;
    const c = (E2 / (1 - E2)) * Math.cos(phi) ** 2;
    const a1 = Math.cos(phi) * lambda;

    const m = A * (
        (1 - E2 / 4 - (3 * E2 ** 2) / 64 - (5 * E2 ** 3) / 256) * phi
        - ((3 * E2) / 8 + (3 * E2 ** 2) / 32 + (45 * E2 ** 3) / 1024) * Math.sin(2 * phi)
        + ((15 * E2 ** 2) / 256 + (45 * E2 ** 3) / 1024) * Math.sin(4 * phi)
        - ((35 * E2 ** 3) / 3072) * Math.sin(6 * phi)
    );

    const easting = K0 * n * (
        a1 + ((1 - t + c) * a1 ** 3) / 6
        + ((5 - 18 * t + t ** 2 + 72 * c - 58 * (E2 / (1 - E2))) * a1 ** 5) / 120
    ) + 500_000;

    let northing = K0 * (m + n * Math.tan(phi) * (
        a1 ** 2 / 2 + ((5 - t + 9 * c + 4 * c ** 2) * a1 ** 4) / 24
        + ((61 - 58 * t + t ** 2 + 600 * c - 330 * (E2 / (1 - E2))) * a1 ** 6) / 720
    ));

    if (lat < 0) {
        // The southern hemisphere is given a false northing so the value never
        // goes negative, which is what keeps a grid reference unsigned.
        northing += 10_000_000;
    }

    return { zone, easting, northing };
}

/** The way back. */
export function utmToLatLng(zone, north, easting, northing) {
    const x = easting - 500_000;
    const y = north ? northing : northing - 10_000_000;

    const e1 = (1 - Math.sqrt(1 - E2)) / (1 + Math.sqrt(1 - E2));
    const m = y / K0;
    const mu = m / (A * (1 - E2 / 4 - (3 * E2 ** 2) / 64 - (5 * E2 ** 3) / 256));

    const phi1 = mu
        + ((3 * e1) / 2 - (27 * e1 ** 3) / 32) * Math.sin(2 * mu)
        + ((21 * e1 ** 2) / 16 - (55 * e1 ** 4) / 32) * Math.sin(4 * mu)
        + ((151 * e1 ** 3) / 96) * Math.sin(6 * mu);

    const c1 = (E2 / (1 - E2)) * Math.cos(phi1) ** 2;
    const t1 = Math.tan(phi1) ** 2;
    const n1 = A / Math.sqrt(1 - E2 * Math.sin(phi1) ** 2);
    const r1 = (A * (1 - E2)) / (1 - E2 * Math.sin(phi1) ** 2) ** 1.5;
    const d = x / (n1 * K0);

    const lat = phi1 - ((n1 * Math.tan(phi1)) / r1) * (
        d ** 2 / 2
        - ((5 + 3 * t1 + 10 * c1 - 4 * c1 ** 2 - 9 * (E2 / (1 - E2))) * d ** 4) / 24
        + ((61 + 90 * t1 + 298 * c1 + 45 * t1 ** 2 - 252 * (E2 / (1 - E2)) - 3 * c1 ** 2) * d ** 6) / 720
    );

    const lng = (d
        - ((1 + 2 * t1 + c1) * d ** 3) / 6
        + ((5 - 2 * c1 + 28 * t1 - 3 * c1 ** 2 + 8 * (E2 / (1 - E2)) + 24 * t1 ** 2) * d ** 5) / 120
    ) / Math.cos(phi1);

    return {
        lat: lat / RAD,
        lng: (zone - 1) * 6 - 180 + 3 + lng / RAD,
    };
}

function dms(value, hemispheres) {
    const hemisphere = value >= 0 ? hemispheres[0] : hemispheres[1];
    const absolute = Math.abs(value);
    const d = Math.floor(absolute);
    const minutesFloat = (absolute - d) * 60;
    const m = Math.floor(minutesFloat);
    const s = ((minutesFloat - m) * 60).toFixed(1);

    return `${d}°${String(m).padStart(2, '0')}'${String(s).padStart(4, '0')}"${hemisphere}`;
}

function inRange(lng, lat) {
    return Number.isFinite(lng) && Number.isFinite(lat)
        && Math.abs(lng) <= 180 && Math.abs(lat) <= 90;
}
