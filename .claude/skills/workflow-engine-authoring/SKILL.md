---
name: workflow-engine-authoring
description: >
  Use this skill whenever creating, editing, or debugging a workflow built with this app's
  workflow engine (packages/workflow) — a Backpack model `use HasWorkflow`, its
  workflow_definitions/workflow_definition_versions graph, states/edges/actor_rule/preconditions,
  or a downstream demo like packages/workflow-demo-purchase-request. Use it even if the user
  just asks to "add a state" or "change who can approve this" — don't drive the visual designer
  (/app/workflows/definitions/{id}/design) via browser automation for this; it's slow and
  brittle compared to editing the graph as a JSON file.
compatibility: This app's own packages/workflow engine only — not a general BPM/workflow concept.
---

## The core idea

A workflow definition is just JSON (`workflow_definition_versions.graph`).
The visual designer is one way to write that JSON; export/edit/import is
another, and it's the fast one. Prefer it over opening a browser for
anything beyond a quick visual sanity check.

```bash
php artisan workflow:export {slug}                 # → storage/app/workflow-exports/{slug}.json
php artisan workflow:validate {file}                # structural check, no database needed
php artisan workflow:import {file} [--publish] [--force]
```

Full graph JSON shape reference, worked examples, and the exact
model_callback call signatures: **`packages/workflow/docs/authoring-as-code.md`**.
Read it before hand-writing a graph from scratch — don't guess the shape.

## Workflow for a change request

1. `php artisan workflow:export {slug}` the existing definition (or start a
   new JSON file from the shape reference if there's no existing one yet).
2. Edit the JSON file directly — add/remove nodes and edges, change an
   `actor_rule`, add a `field_policy` entry, whatever was asked for.
3. `php artisan workflow:validate {file}` — fix anything it flags before
   moving on. It only catches structural mistakes (dangling edge
   references, duplicate ids, an unreachable `join`, a missing `start`) —
   see the doc's own "what it does and doesn't catch" section.
4. `php artisan workflow:import {file}` as a draft first if the change is
   nontrivial (lets a human review it in the designer before it goes live),
   or straight to `--publish` for something small/obviously correct.
5. Prove the actual behavior with a real test — either a Pest feature test
   (this codebase's own convention: see any `tests/Feature/Workflow/*Test.php`
   for the HasWorkflow-fixture pattern) driving `TransitionEngine::transition()`
   directly, or `Workflow\Support\WorkflowSimulator` for a dry run with no
   persisted side effects. Don't consider a workflow change done just
   because `workflow:validate` passed — that's structural, not behavioral.

## Inbound webhooks

A `trigger: 'webhook'` edge is fired via a signed URL, not a person
clicking a button. See **`packages/workflow/docs/webhooks.md`** for the
full contract (`HasWorkflow::signedWebhookUrl()`, the request/response
shape, status codes).

## Gotchas

- `workflow:import` re-imports into the SAME draft row every time (matching
  the designer's own "Save draft" behavior) — it never piles up extra
  unpublished versions. Only `--publish` assigns a new version number.
- A brand-new definition (a slug that doesn't exist yet) needs `model` in
  the JSON's `definition` block, or `workflow:import` refuses it.
- `actor_rule`'s `model_callback` and `visibility_rules`' `model_callback`
  have DIFFERENT call signatures — `$model->{method}($actor)` for the
  former, `$model->{method}($query, $actor)` for the latter (it's building
  a query scope, not just answering yes/no). Get this backwards and the
  method will silently receive the wrong arguments.
- Never hand-edit `workflow_definition_versions` rows directly in a
  database client — always go through `workflow:import` (or the designer),
  since publishing has real side effects (`published_version_id`, version
  numbering) that a raw UPDATE would skip.
- If asked to test something live in the browser anyway (e.g. to confirm a
  UI regression), never mutate the user's real/shared draft — create a
  disposable, clearly-named (e.g. `-tmp` suffixed slug) definition for any
  throwaway testing, and clean it up afterward.
