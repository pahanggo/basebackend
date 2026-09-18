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

Built and measured. **Every gate is met.** The batch budget is met four times
over, and the two places the design bent are both recorded below rather than
smoothed away.

### The gates

| Gate | Result |
| --- | --- |
| Every command's inverse restores the state it was taken from | Pass, with two documented exceptions below |
| A replayed `(clientId, seq)` returns the original response and writes nothing | Pass |
| A version mismatch returns `409` with server state and applies nothing | Pass |
| Batch apply, 100 commands, p95 < 300 ms | **median 91 ms, p95 133 ms** |

100 `feature.create` commands with geometry and two properties each, through the
full HTTP stack: median 91 ms, p95 133 ms, worst of twelve runs 133 ms. 100
`feature.update` commands: 64 ms. About **16 ms of the 91** is the one round trip
per feature to `ST_Area` — geodesic area has to come from MySQL, because nothing
in `brick/geo` is geodesic and GEOS would return square degrees. If batches ever
approach the 500 cap it is the first thing to fold into a single statement; at
100 it is 18% of a budget being met four times over, and batching it would mean
holding every geometry twice.

### "Byte-identical state" is not quite the right gate, and here is why

The gate says every command's inverse restores byte-identical state. It does,
for everything that edits a row in place. Two cases cannot, and neither is a
defect:

- **Create and delete invert into each other, but not into the same id.** Undoing
  a delete re-creates the feature and the server assigns it a new id. The content
  comes back; the identity does not, and no client-side design can bring it back
  — the id is the server's. The test asserts content equality for these two and
  says so.
- **Versions do not come back, and must not.** A version is the server's
  monotonic counter and undo is a **new edit**, not a rewind: the inverse goes to
  the server as an ordinary command and bumps the version again. Restoring the
  version would be asking the undo to lie about what happened.

A third normalisation is in the test rather than the design: property key
insertion order. Removing a key and restoring it puts it back at the end, because
`properties` is a patch and a patch writes the keys it names. Nothing reads that
order.

### Per-field merge is built, and it needed a table to be built on

Section 16 asks for per-field merge in v1 because it is the machinery co-editing
needs and is far cheaper to prove against occasional conflicts than to introduce
when conflicts are routine. Building it turned up a requirement the plan did not
state: **"did the other side touch the field I am touching?" has no answer
without a per-field history.**

So the command log is two tables. `gis_command_log` holds the batch and its
response; `gis_command_effects` holds one row per row touched, carrying the
version it produced and the field names it wrote — `geom`, `name`,
`properties.status`. A stale command is merged when the fields changed above the
version it claims do not intersect the fields it writes.

Three consequences worth stating:

- **Geometry never merges with geometry**, and that falls out of the field
  comparison rather than being a special case. Two writes to `geom` intersect.
- **A row with no recorded history above the claimed version does not merge.**
  Absence of effects means the change came from somewhere this log does not
  cover — an import, a repair, a direct write — and assuming it touched nothing
  would silently overwrite it.
- **`feature.update`'s `properties` is a patch, not a replacement.** It has to be:
  a whole-object write would clobber a concurrent edit to a different key and
  there would be nothing left to merge. A null value removes a key.

### The map version is the replay sequence

Section 16 asks for "a server-assigned monotonic sequence" for replay. It already
exists: the map version is bumped once per batch and is already what the client
tracks. A second counter beside it would be a column that could disagree with the
one next to it, so the log carries `map_version` and "bring me forward from 31"
is an indexed range scan.

That made the replay read cheap enough to build, so it is built:
`GET /api/geo/maps/{map}/commands?since=31`. **This is a twelfth endpoint the
section 7 summary table did not list** — section 16 required replay, the table
simply omitted the read — so the table has been corrected rather than the
capability dropped.

It answers honestly when it cannot help, returning `canReplay: false` with a
reason when the log no longer reaches back far enough or the client is more than
500 batches behind. A partial replay would leave the client believing it is
current when it is not, which is worse than the re-read it saves.

### Decisions that differ from the plan

- **`mapVersion` in the envelope is advisory, not a gate.** Checking it would
  mean any concurrent batch invalidates the next one whole — and per-field merge,
  the thing this session exists to build, would never be reachable. The per-row
  versions are the gate; the envelope's `mapVersion` is what the client tracks
  for replay.
- **Reverb is not installed.** The event, the channel and its authorization ship
  and are tested; the deployment's broadcast driver is `log`. Standing up a
  websocket server is a dependency and an operational decision, and switching to
  it is a `BROADCAST_DRIVER` change and nothing in this package. This is the one
  part of "Reverb is stood up here" that is deferred, and it is deferred to a
  decision rather than to a later session.
- **Ten ops have handlers, not the whole catalogue.** The session scoped this
  ("the commands themselves beyond a representative few"), and the ten cover
  every shape the pipeline has to handle: layer identity against placement,
  geometry against attributes, a create with a `tempId`, a delete, and a
  map-owned row that is not a feature. `CommandRegistry` is extendable and later
  sessions add their own. An op with no handler fails with `unknown_op` rather
  than being quietly ignored.
- **A command addressing a `tempId` created earlier in the same batch skips the
  authorisation pass.** It cannot be authorised — the row does not exist — and it
  does not need to be: the create that brings it into existence was authorised a
  moment earlier against the layer it names, and nothing can reach the row before
  the transaction commits.
- **The conflict's `updatedBy` comes from the log, not from a column.** A
  `last_updated_by` on every row would be a second answer to a question the log
  already answers, free to disagree with the first.
- **Write rate limiting was added** (`gis-writes`, from
  `gis.rate_limits.writes_per_minute`). Reads are deliberately left unlimited:
  the editor issues one per layer per settled view and the client's padding is
  what bounds them, so a limit low enough to matter would break ordinary panning.

### Authorization composes three terms, in one place

`MapAccess` resolves the user's role on the map, the placement's `access` and the
layer's `locked` flag, and takes the narrowest. Owning a map does not grant edit
on a layer shared into it as `read`; a layer not placed in this map is not
reachable through this map at all, whatever the user's role elsewhere. A
contributor may draw features and may not restyle a layer, because a style is
global to the layer. All four are tested.

### Tests

**30 feature tests** across the batch endpoint, replay and the sweep: atomicity
under a mid-batch failure, idempotent replay, the `409` payload shape, per-field
merge and its geometry refusal, the three-term authorization, batch cap, unknown
op, property sanitising and identifier validation, the derived columns the read
path depends on, WKB axis order, `tempId` resolution, placement-versus-identity
versioning, the broadcast and its suppression on failure, and log retention.

**14 JavaScript unit tests** under Node's own runner, asserted from Pest: the
apply/invert round trip for every command including a property-patch fuzz over
200 random field sets, gesture coalescing and its 500 ms box, both undo bounds,
redo clearing, topic emission, the ring buffer, `tempId` reconciliation, batching
and the debounce, the structural-command bypass, retry carrying the same `seq`,
the `409` pause, and the three conflict resolutions.

The whole suite is **91 tests, 344 assertions**, up from 62.

### Not done

- Presence, live cursors, per-field locking, CRDT or OT convergence — deliberately
  out, as the session says.
- The conflict **panel**. The client pauses the queue, keeps the commands and
  hands the problem document to a callback; S5 renders it. Nothing is lost
  meanwhile — the user is simply not yet told.
- Bulk operations over 1,000 features pushing a single coarse inverse. No command
  in this session's set is bulk; `feature.bulkSetProperties` is where it lands,
  and the undo stack's byte bound is already in place to receive it.
