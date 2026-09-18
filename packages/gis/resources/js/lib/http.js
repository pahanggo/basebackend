/**
 * The package's HTTP calls.
 *
 * The API is same-origin and authenticated by the session cookie, so there is
 * no token to store, refresh or leak — but every mutating request must carry
 * `X-CSRF-TOKEN`. The application's shared `inc.scripts` partial already calls
 * `$.ajaxSetup` with that header, which covers jQuery-issued requests; `fetch`
 * is not jQuery, so this module sets it explicitly (specification section 7).
 */

let csrfToken = '';

export function configureHttp({ token }) {
    csrfToken = token;
}

/** @returns {Promise<Object>} the decoded body, or a rejection carrying the problem document */
async function send(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            ...(options.body ? { 'Content-Type': 'application/json' } : {}),
            'X-CSRF-TOKEN': csrfToken,
            ...(options.headers || {}),
        },
    });

    const body = response.status === 204 ? null : await response.json().catch(() => null);

    if (!response.ok) {
        // Rejected with the problem document itself, so callers branch on
        // `code` — the stable member — rather than on a message string that is
        // free to change (specification section 7).
        const error = new Error(body?.detail || `Request failed: ${response.status}`);

        error.problem = body;
        error.status = response.status;

        throw error;
    }

    return body;
}

export function getJson(url, params = {}) {
    const query = new URLSearchParams(
        Object.entries(params).filter(([, value]) => value !== null && value !== '' && value !== undefined),
    );

    return send(query.toString() === '' ? url : `${url}?${query}`);
}

export function postJson(url, body) {
    return send(url, { method: 'POST', body: JSON.stringify(body) });
}

export function putJson(url, body) {
    return send(url, { method: 'PUT', body: JSON.stringify(body) });
}

export function patchJson(url, body) {
    return send(url, { method: 'PATCH', body: JSON.stringify(body) });
}

export function deleteJson(url, params = {}) {
    const query = new URLSearchParams(params);

    return send(query.toString() === '' ? url : `${url}?${query}`, { method: 'DELETE' });
}
