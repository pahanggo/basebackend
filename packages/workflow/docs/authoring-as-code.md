# Authoring a workflow as code

The visual designer (`/app/workflows/definitions/{id}/design`) is the normal
way a human builds a workflow. But a workflow definition is *just JSON* —
`workflow_definition_versions.graph` — so anyone (or anything) that can read
and write a JSON file can author or edit a workflow without opening a
browser at all. This is faster for a human doing a large edit, and it's the
recommended path for an AI coding agent: driving the Drawflow canvas via
browser automation (clicking, dragging nodes, filling one field at a time)
is slow and brittle compared to writing a file.

## The three commands

```bash
# Read an existing definition's graph out to a file
php artisan workflow:export purchase-request-demo
# → storage/app/workflow-exports/purchase-request-demo.json
php artisan workflow:export purchase-request-demo --path=graph.json --draft

# Structurally sanity-check a graph file before importing it — no database
# needed, safe to run repeatedly while iterating
php artisan workflow:validate graph.json

# Write a graph file back as a new draft (or published) version
php artisan workflow:import graph.json
php artisan workflow:import graph.json --publish
php artisan workflow:import graph.json --force   # skip validation
```

## The recommended loop

1. **Editing an existing workflow**: `workflow:export {slug}` it, edit the
   JSON file directly, `workflow:validate` it, then `workflow:import` it
   back (as a draft first if you want to review it in the designer before
   publishing, or straight to `--publish` if you're confident).
2. **Building a new workflow from scratch**: write the JSON file by hand
   (see the shape reference below), `workflow:validate` it, then
   `workflow:import --publish` it.
3. **Before ever touching a browser**, you can also dry-run a transition
   through an unpublished graph via `Workflow\Support\WorkflowSimulator`
   (the same engine backing the designer's own "Test with a sample record"
   button) — see the "Testing without the browser" section below.

## The graph JSON shape

```json
{
  "definition": {
    "slug": "purchase-request-demo",
    "name": "Purchase Request",
    "description": "Optional.",
    "model": "WorkflowDemo\\PurchaseRequest\\Models\\PurchaseRequest"
  },
  "graph": {
    "start": "draft",
    "display_name": "Purchase Request",
    "operation_settings": { "...": "see below" },
    "visibility_rules": [ "...": "see below" ],
    "nodes": [ "...": "see below" ],
    "edges": [ "...": "see below" ]
  }
}
```

`definition` only matters for `workflow:import` (it's how a brand-new
definition gets created, or an existing one found by `slug`) — `workflow:export`
always includes it too, so a plain export/edit/import round-trip never has
to think about it.

### `nodes`

```json
{ "id": "pending_review", "name": "Pending review", "type": "state" }
```

- `type` is `state`, `fork`, or `join`. A `state` node can also carry:
  - `field_policy`: ordered list of `{field, visible, label, type, readonly, custom_field_definition}` — overrides the target model's Backpack form while a record sits here. `custom_field_definition` is a literal PHP-array-as-string (parsed by `Workflow\Support\CustomFieldDefinitionParser`, never `eval`'d), e.g. `"[\n\"prefix\" => \"$\",\n]"` for a money field's prefix.
  - `header_view` / `footer_view`: Blade view names, rendered above/below the show-workflow page's field list.
  - `row_actions`: `{show, update, delete}`, each optional `{enabled, actor_rule}` — overrides the definition-level `operation_settings` just for records sitting in this node. A node override always wins outright over the definition-level setting, even to *re-enable* something the definition disabled.
- `fork` nodes: every outgoing edge fires at once, each spawning its own token.
- `join` nodes: wait for every expected incoming token before consuming them and emitting one outgoing token. **A join with no incoming edges can never fire** — `workflow:validate` catches this.

### `edges`

```json
{
  "id": "submit", "name": "Submit for review", "from": "draft", "to": "pending_review",
  "trigger": "manual",
  "actor_rule": { "roles": ["employee"], "permissions": [], "users": [], "model_callback": "", "match": "any" },
  "surfaces": ["record_button"],
  "requires_confirmation": false,
  "inputs": [{ "name": "note", "type": "textarea", "required": true, "store_as": "note", "show_in_timeline": true }],
  "actions": [{ "type": "send_notification", "to": "actor", "notification": "App\\Notifications\\SomeNotification" }],
  "preconditions": { "op": "and", "children": [{ "type": "field_equals", "field": "status", "value": "ready" }] }
}
```

- `trigger`: `manual` (a person clicks it — via `record_button` and/or `bulk_action`, per `surfaces`), `automatic` (fires the instant its `preconditions` pass — how conditional branching is expressed, not a separate mechanism), `webhook` (fired by an external system via a signed URL — see `docs/webhooks.md`), or `timer` (fired when its scheduled entry is due).
- `actor_rule` (manual edges only): `roles`/`permissions`/`users` are OR'd or AND'd together per `match` (`any`/`all`); `model_callback` names a method on the target model, called as `$model->{method}($actor)` — the escape hatch for anything a role/permission can't express (e.g. "only the record's own requester" — see `callbackFunctionIsRequester()` in the Purchase Request demo). Omit `actor_rule` entirely (or leave every sub-key empty) to allow anyone.
- `inputs`: captured into `workflow_instance_history.inputs` always; `store_as` additionally maps a value onto a real model column. `show_in_timeline` (default `false`) opts an input into showing on the record's workflow timeline.
- `actions`: `send_email`, `send_notification`, `start_timer`, `call_webhook` — see `config('workflow.actions')` for the full registry, extendable from your own app's service provider.
- `preconditions`: a tree, `{op: 'and'|'or', children: [...]}` down to leaves like `{type: 'field_equals', field: '...', value: '...'}` — always enforced, even for a `manual` edge a person is clicking (it's a data-integrity gate, not an authorization one).

### `operation_settings` (definition-level; the settings modal's gear icon)

```json
{
  "create": { "enabled": true, "actor_rule": { "roles": ["employee"] } },
  "show": { "enabled": true },
  "update": { "enabled": false },
  "delete": { "enabled": false }
}
```

Gates the target model's own Backpack create/show/update/delete operations,
independent of which node a record is on. A node's own `row_actions` (see
above) overrides this per-node for show/update/delete; `create` has no
per-node override since there's no node yet for a record that doesn't exist.
Omitting an operation entirely leaves it unrestricted.

### `visibility_rules` (definition-level; who sees which records in the list)

```json
[
  { "actor_rule": { "roles": ["finance", "ceo"] }, "scope": "all" },
  { "actor_rule": { "roles": ["employee"] }, "scope": "owner", "owner_field": "requester_id" },
  { "scope": "model_callback", "model_callback": "visibleToDepartment" }
]
```

An ORDERED list — first `actor_rule` match wins. `scope` is `all` (no
restriction), `owner` (`owner_field` on the model must equal the actor's
id), or `model_callback` (`$model->{method}($query, $actor)` — the escape
hatch for anything domain-specific, e.g. "same department as me"). No rules
at all leaves the list unrestricted (backward compatible); once any rule
exists, an actor matching none of them **sees nothing** — deny by default.
A rule with no `actor_rule` at all matches anyone, useful as a catch-all.

## Testing without the browser

Once imported (even as an unpublished draft — no need to publish first),
you can dry-run the engine directly:

```php
use Workflow\Support\TransitionEngine;

$record = YourModel::find($id); // must already have an active instance
$token = $record->workflowInstance()->activeTokens()->first();

// $actor omitted or null runs as "system" — no actor_rule check,
// preconditions still enforced, same as a timer/webhook trigger.
app(TransitionEngine::class)->transition($token, 'submit', ['note' => 'test'], $actor = null, skipActorCheck: true);
```

Or use `Workflow\Support\WorkflowSimulator` (the same class backing the
designer's own "Test with a sample record" button) to check what's
available from a node, or preview firing an edge, without persisting
anything or firing real actions — see `WorkflowSimulateController` for the
exact call shape if you want to drive it the same way the UI does.

## Guardrail: what `workflow:validate` does and doesn't catch

It checks structure only, with no database access: every edge's `from`/`to`
references a real node id, no duplicate node/edge ids, every node has a
valid `type`, every edge has a valid `trigger`, `start` references a real
node, and every `join` has at least one incoming edge. It does **not**
check that a role/permission actually exists, that a `field_policy` field is
real, or that a `model_callback` method exists on the target model — those
need a real database (and, for the callback case, a real record) to verify,
which is what `WorkflowSimulator` and a real `transitionTo()`/import
`--publish` are for. Structural validation catches the most common
hand-authoring mistakes cheaply; it's not a substitute for actually trying
the workflow.
