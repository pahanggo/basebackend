# S4 — Store, commands, undo, sync

**Depends on:** S1
**Specification:** §3 (state), §7 (commands, conflicts, error codes), §16 (whole section)
**Gate:** every command's inverse restores byte-identical state.

## Goal

One command vocabulary serving undo, server sync and bug reproduction. This is the session that must not be retrofitted — everything after it speaks this vocabulary.

## In scope

- The store: plain object, topic subscription, `commit()` as the single mutation point
- Command objects: `apply` returns its own inverse, `serialize` produces the API payload
- Undo stack: depth 100 or 50 MB, gesture coalescing at 500 ms
- Outbound sync queue: 400 ms debounce, immediate for structural commands
- `POST /api/geo/maps/{map}/commands` — atomic, idempotent, versioned
- `409` conflict payload and the conflict panel
- RFC 7807 problem documents with stable `code` values
- The 200-command ring buffer for diagnostics
- Gesture-to-op collapsing: vertex edits, transforms and boolean ops all serialise to `feature.update`
- Per-field merge for auto-resolvable conflicts
- Reverb on `private-map.{id}`, carrying conflict notices

## Out of scope

The commands themselves beyond a representative few — later sessions add their own ops to the catalogue. Layer tree UI (S5), drawing (S6).

**The catalogue lives in §7 and only in §7.** §16 describes gesture granularity, not a second list. If you find yourself maintaining two, one of them is wrong.

## Deliverables

```
packages/gis/
  resources/js/store/{store.js,undo.js,sync.js,commands/}
  src/Http/Controllers/Api/CommandController.php
  src/Commands/                  server-side command handlers
  src/Support/IdempotencyStore.php
```

## The three load-bearing properties

1. **Atomic.** All commands in a batch apply or none do, in one transaction. The client never reasons about partial application.
2. **Idempotent.** `(clientId, seq)` is stored for 24 hours; a retry after a timeout replays the original response rather than applying twice. Without this, every network blip risks duplicate features.
3. **Symmetrical with undo.** The objects pushed onto the undo stack are the objects sent to the server. One serialisation, one vocabulary. **A translation layer between store commands and API payloads means the design has gone wrong** — if you find yourself writing one, stop.

## Built for co-editing, shipped with optimistic locking

Real-time multi-user editing is expected within a year (§23), which changes what this session **builds** without changing what v1 **ships**. v1 ships optimistic locking. Three things are done now because retrofitting them later is expensive:

- **Per-field merge is built, not deferred.** It is the same machinery co-editing needs, and building it for the rare v1 conflict means it is proven before conflicts become routine.
- **The command log is shaped for replay**, not only for undo: commands carry their originating `clientId` and a server-assigned monotonic sequence, so a client that falls behind can be brought forward by replaying what it missed instead of re-reading the map.
- **Reverb is stood up here** rather than in v2, so the channel, auth and client plumbing exist before presence and live cursors need them.

Deliberately not built: presence, live cursors, per-field locking, CRDT or OT convergence. Geometry conflicts stay one-side-wins — merging two edits to the same ring is a product decision nobody has made.

## Constraints that apply here

- Subscription is by explicit topic string, never dependency tracking. No proxies, no getters, no dirty-checking. If state changed and the UI did not update, the cause is a missing topic in `command.topics` — one place to look.
- Every command is invertible without consulting the server. `apply` returns the inverse; undo applies a stored inverse rather than recomputing.
- A vertex drag is one undo step, not one per pointer move. Coalescing is time-boxed at 500 ms and broken by any other command type.
- Bulk operations over 1,000 features push a single coarse inverse (a snapshot reference), not per-feature inverses, to bound memory.
- Geometry is WKB base64 by default. It is smaller than GeoJSON and removes a second place where coordinate precision could be silently truncated.
- Policies are enforced **per command inside the batch**, not per endpoint — one batch may mix commands of differing sensitivity, and any unauthorised command rejects the whole batch (§20).
- Conflicts abort the whole batch and return enough server state to resolve without a second round trip.

## Gate

- Every command in the catalogue round-trips: `apply` then apply-inverse restores byte-identical state.
- A replayed `(clientId, seq)` returns the original response and writes nothing.
- A version mismatch returns `409` with server state and applies nothing.
- Batch command apply, 100 commands, p95 < 300 ms.

## Tests

- Harness page: apply/invert round-trip for every command, property-based over random inputs.
- Feature: idempotency replay, conflict payload shape, batch atomicity under a mid-batch failure.
- Feature: two clients editing different fields of one feature auto-resolve; editing the same field conflicts.
- Feature: a client replaying from a stale sequence catches up without a full re-read.
- Feature: an unauthorised command anywhere in a batch rejects the whole batch.
- Harness page: undo after a failed sync leaves the store consistent with the server.

## Notes

The ring buffer is the practical payoff of routing all mutation through one function: a bug report captures the last 200 commands plus store state and reproduces deterministically. Build it now, while it is free.

## Results

_Fill in when complete._
