# S15 — Share links and embed *(v2)*

**Depends on:** S14
**Specification:** §15 (share links), §20 (share links, authorization)
**Gate:** security review.

## Goal

Let a map leave the account it lives in, safely.

## In scope

- `GET /s/{token}` with serialised view state
- Snapshot mode (frozen immutable revision) and live mode
- Access: public, password, org members only
- Permission: view, view + comment, edit
- Expiry and allowed-domain embed list
- Embed mode with `postMessage` view-state reporting to the host page

## Constraints that apply here

- **Tokens are 32 bytes from a CSPRNG** — not sequential ids, and not signed payloads that reveal structure.
- Password-protected shares use a per-share hash, rate-limited at **10 attempts per hour per IP**.
- **Expiry is enforced server-side on every request, never only in the UI.**
- Every access is logged with timestamp, IP and user agent, visible to the map owner.
- Embed enforces allowed domains via `X-Frame-Options`/`frame-ancestors`; **an unlisted host gets a refusal page, not the map.**
- **Snapshot mode copies features at share time into an immutable revision**, so a link in a report stays meaningful after the map changes. This is the whole reason snapshot mode exists — a live link in a filed document is a liability.
- Embed mode hides editing UI but retains pan, zoom, layer toggles and popups.
- API keys never appear in share links or exported HTML (§20).

## Gate

A security review covering: token entropy and non-enumerability, password rate limiting, server-side expiry, embed domain enforcement, what a snapshot actually freezes, and what an embedded page can read from its host.

## Tests

- Feature: token is unguessable and reveals nothing; a revoked or expired token is refused.
- Feature: password rate limiting per IP.
- Feature: an unlisted embed host is refused.
- Feature: a snapshot is unaffected by subsequent edits to the source map; a live link follows them.
- Feature: a view-permission share cannot mutate through any endpoint.

## Results

_Fill in when complete._
