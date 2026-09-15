# Using the workflow designer

This is a walkthrough of the visual designer (`/app/workflows/definitions/{id}/design`)
for a human operator — what each button does, how the canvas behaves, and
what each panel/modal is for. If you're editing a graph as a JSON file
instead (faster for large changes, or for an AI agent), see
`docs/authoring-as-code.md`. Both edit the exact same
`workflow_definition_versions.graph` — nothing here is "more real" than a
hand-edited file, and vice versa.

## The canvas

The canvas is a Drawflow board: states are rectangles, fork/join nodes are
circles. The currently-selected node or connection turns green; everything
else is your theme's primary color. States, forks, and joins are placed
wherever you drop them; a node with no saved position yet is auto-arranged
into a grid so it's never invisible off-screen.

- **Add a node**: the toolbar's **+ State** button adds a single state node.
  **Fork / Join** adds a linked pair at once — draw your parallel-branch
  edges out of the fork, and back into the join.
- **Move a node**: click and drag it. Positions snap to a small grid so
  nodes line up cleanly.
- **Connect two nodes**: drag from one node's edge to another to create a
  connection (an edge/transition). Connections route orthogonally (right
  angles, not straight diagonal lines) and automatically detour around
  other nodes/connections in the way. Two nodes can be joined by as many
  separate connections as you need — each keeps its own identity, name,
  and settings, and detouring connections are drawn in separate lanes
  (with their labels staggered) so overlapping ones stay tellable apart.
- **Select a node or connection**: click it. The inspector panel on the
  right updates to show its settings (see below). A selected connection
  also gets two small drag handles on its endpoints — drag either one to
  reconnect that end to a different node without deleting and redrawing
  the whole connection.
- **Delete a node or connection**: select it, then use the **Delete**
  button at the bottom of its inspector panel, and confirm the prompt.
  Deleting a node also deletes every connection attached to it (the
  prompt tells you how many); deleting a connection takes its actor rule,
  preconditions, actions and inputs with it. Neither can be undone from
  inside the designer — save/publish state is your only safety net.
  Right-clicking on the canvas does nothing here — the designer
  deliberately disables Drawflow's own right-click delete in favor of that
  explicit button, so you can't lose a node by an accidental right-click.
  (Right-click still works normally inside the inspector panel's own
  fields.)
- **Duplicate a node**: select it and use **Clone node** at the bottom of
  the node inspector. The copy lands next to the original with the same
  field policy, header/footer views, and row actions — its id and any
  connections are not copied.
- **Keyboard shortcuts**: Ctrl/Cmd+S (save draft — same as clicking the
  save-draft button). It's the only one; it works even while you're typing
  in an inspector field.
- **Pan and zoom**: drag on empty canvas space to pan; scroll/pinch to
  zoom, or use the zoom controls (bottom-right: zoom in, zoom out, reset
  view). The **minimap** (bottom-right, above the zoom controls) shows
  your whole graph at a glance and where your current viewport sits
  within it.
- **Fullscreen**: the toolbar's fullscreen button expands the canvas to
  fill the browser window — useful for a large graph.
- **Inspector panel toggle**: the columns icon on the toolbar shows/hides
  the right-hand inspector panel, if you want the full canvas width for a
  moment. This is remembered the next time you open the designer.

## The floating toolbar

Top-left of the canvas, left to right:

| Button | Does |
| --- | --- |
| Fullscreen (expand icon) | Expands the designer to fill the window |
| × (close) | Back to the workflow definitions list |
| **+ State** | Adds a new state node |
| **Fork / Join** | Adds a linked fork+join node pair |
| Gear icon | Opens **Workflow settings** (see below) |
| Save icon | **Save draft** — persists your changes without affecting what's currently live |
| Cloud-upload icon (green) | **Publish** — makes this version live immediately, after a confirmation prompt |
| **Test** (flask icon) | Opens **Test with a sample record** — a dry run (see below) |
| Columns icon | Show/hide the inspector panel |

Below the toolbar, a small caption always tells you whether you're editing
an unpublished draft, or a specific published version (and whether that
version is the one currently live).

Saving a draft or publishing never reloads the page — your pan/zoom
position, selection, and any open modal all survive either action.

## The node inspector

Click a state, fork, or join node to see its settings on the right:

- **Name** — the label shown on the node itself.
- **Type** — state, fork, or join (changing this on an existing node is
  rarely what you want — usually you'd add a new node of the right type
  instead).
- **Field policy** (state nodes only) — a summary of how many fields are
  configured, plus an **Edit field policy** button opening the field
  policy modal (below). This controls what the target model's own
  create/edit form shows, hides, or makes read-only while a record sits in
  this state — and, for a field left not-readonly, what can be edited
  inline on the show-workflow page itself.
- **Form header/footer** (state nodes only) — optional Blade view names
  rendered above/below the record's fields on the show-workflow page while
  it's in this state (e.g. a custom instructions banner).
- **Row actions while a record is in this state** (state nodes only) — lets
  you override the definition-level Show/Update/Delete settings (see
  Workflow settings below) just for records sitting here — e.g. hide
  Delete once a request reaches "approved," even if Delete is otherwise
  allowed definition-wide. Tick "Override Show/Update/Delete" to reveal a
  visibility checkbox and a "who" picker for that specific action; leave a
  row unchecked to fall back to the definition-level default.
- **Incoming / outgoing transitions** — every connection touching this
  node, listed by name. Click a row to jump straight to that connection
  (it gets selected and highlighted on the canvas, and the panel swaps to
  its inspector) — easier than hunting for the right line once several
  edges converge on one node.
- **Clone node** — duplicates this node next to itself, after a
  confirmation prompt (see the canvas section above).
- **Delete node** — removes this node (and any connections touching it),
  after a confirmation prompt.

## The edge (connection) inspector

Click a connection to see its settings:

- **Name** — the label shown on the connection, and the default text for
  its transition button if no separate button label is set.
- **Trigger** — how this transition fires:
  - `manual` — a person clicks a button.
  - `automatic` — fires the instant its preconditions pass, with no button
    at all. This is how conditional branching is expressed: draw two or
    more `automatic` edges out of the same node, each with a different
    precondition, and only the one whose condition is true fires.
  - `webhook` — fired by an external system hitting a signed URL. See
    `docs/webhooks.md`.
  - `timer` — fires when its scheduled time is due.
- For a **manual** trigger, you additionally get:
  - **Who can trigger this** — a picker covering roles, permissions,
    specific named users, or a "Callbacks" group of the target model's own
    `callbackFunction*` methods (for a rule no role/permission can express,
    e.g. "only this record's own requester"). Leave it empty to allow
    anyone. The **match** setting (any/all) controls whether multiple
    selected roles/permissions are OR'd or AND'd together.
  - **Requires confirmation** — shows a plain yes/no "are you sure?" prompt
    before the transition fires.
  - **Surfaces** — where this transition can be triggered from: a
    per-record button, a bulk action (acting on several selected rows at
    once), or both.
  - **Button label** — overrides the connection's own name just for the
    button text; leave blank to reuse the name.
- **Preconditions** — a tree of AND/OR groups and conditions (nested up to
  two levels deep) that must all pass before this transition can fire —
  enforced even for a manual edge a person is clicking, since these are
  data-integrity checks, not authorization checks. **+ Condition** adds a
  leaf (field comparison or a model callback); **+ Group** (only available
  one level deep) adds a nested AND/OR group.
- **Actions** — things that happen when this transition fires: sending an
  email or notification, starting a timer, or calling an external webhook.
  Add as many as you need with **+ Add action**.
- **Transition-time inputs** (manual triggers only) — fields captured at
  the moment someone triggers this transition (e.g. a required "reason for
  rejection" textarea). Each input can be marked required, mapped onto a
  real column on the model via "store as," and optionally shown on the
  record's workflow timeline.
- **Connected nodes** — this connection's From and To node. Click either
  to jump to it (selects it on the canvas and swaps the panel to its node
  inspector) — the mirror image of the node inspector's transitions list.
- **Delete connection** — removes this transition, after a confirmation
  prompt.

## Workflow settings (gear icon)

A modal covering definition-wide settings, independent of any single node:

- **Name** — the display name shown on the show-workflow page header (e.g.
  "Purchase Request #17"). Defaults to the model's class name.
- **Start state** — which node a brand-new record enters on creation.
- **Actions** — enable/disable Create, Show, Update, and Delete for the
  target model's own Backpack CRUD, each with its own "who can" picker.
  Leaving one enabled with no picker filled in allows anyone with the base
  permission; disabling one blocks it outright regardless of role. A
  node's own row-actions override (above) always wins over these settings
  for Show/Update/Delete on records sitting in that node.
- **List visibility** — controls which records an actor even *sees* in the
  list at all (separate from being able to act on one they can already
  see). Rules are ordered top to bottom — the first one whose "who"
  matches the current actor wins, and its "Sees" setting decides what they
  get: all records, only their own (by an owner-field match), or a custom
  rule via a model method. Use the up/down arrows to reorder rules, and
  the trash icon to remove one. Once any rule exists, an actor matching
  none of them sees nothing — leave the list empty to leave it
  unrestricted (today's default).

## The field policy modal

Opened from a state node's **Edit field policy** button. One row per field
on the target model (and its related models, discovered automatically) —
policy only overrides these discovered fields, there's no manual add/remove:

| Column | Meaning |
| --- | --- |
| (drag handle) | Drag to reorder — this is the order fields appear in the form/show page |
| Column | Read-only, informational — the underlying `table.column` |
| Show | Whether this field is visible at all while a record sits in this state |
| Label | Overrides the field's label |
| Type | Overrides which Backpack field type renders it |
| Readonly | Whether the field can only be viewed here, or edited. Unticking it makes the field a **real, editable input on the show-workflow page** while a record sits in this state — saved straight from that page with its own Save button, completely independently of the model's own Update operation (you don't have to enable Update, or show the Edit button, to let someone fix a field here). Each save is logged on the record's workflow timeline as an "Edited fields" entry showing who changed what, from what, to what. Note that a field belonging to a *related* model (a dotted `relation.column` name) always stays view-only. |
| Custom field definition | A PHP-array-literal escape hatch for anything the other columns don't cover (e.g. a money field's currency prefix, select options, a tab). Click the expand icon to grow the textarea for a longer definition. Validated on Save — an invalid entry is highlighted in red with the parse error as its tooltip, and blocks saving until fixed. |

**Import from** (bottom-left) copies another node's entire field policy
onto this one in a single action, after a confirmation prompt (since it
replaces every row already here).

### Field type reference (the "Type" column)

The Type dropdown offers every field type this app's own CRUD forms can
render — the same ones you'd pick from in a plain (non-workflow) Backpack
form. Grouped by what they're for:

| Type(s) | Use for |
| --- | --- |
| `text`, `textarea`, `email`, `url`, `number`, `password`, `hidden` | Plain scalar input |
| `wysiwyg`, `ckeditor`, `tinymce`, `summernote`, `easymde`, `simplemde` | Rich/markdown text editors |
| `checkbox`, `boolean`, `switch` | A single true/false value — `switch` is this app's own pill-style toggle (see `custom_field_definition` below for its `color`/`onLabel`/`offLabel`/`size` options) |
| `date`, `date_only`, `date_picker`, `datetime`, `datetime_picker`, `time`, `time_range`, `date_range`, `month`, `week` | Date/time input — `date_only` is this app's own bootstrap-datepicker wrapper storing a plain `Y-m-d` |
| `select`, `select_from_array`, `select_grouped`, `select_multiple`, `select_and_order`, `select2`, `select2_from_array`, `select2_from_ajax`, `select2_from_ajax_multiple`, `select2_multiple`, `select2_grouped`, `select2_nested`, `radio`, `enum`, `checklist`, `checklist_dependency`, `dependent_select` | Choosing from a fixed or ajax-loaded list of options — `dependent_select` reloads its options when another field changes |
| `relationship`, `browse`, `browse_multiple`, `model_picker` | Picking a related model's record(s) — `model_picker` is this app's own picker for the workflow engine's own polymorphic references |
| `tags` | A free-form JSON array of tag strings |
| `upload`, `upload_multiple`, `ajax_upload`, `ajax_multi_upload`, `image`, `base64_image`, `video` | File/image uploads — the `ajax_*` variants upload immediately and submit only the stored path/array, subject to this app's `RestrictFileUploads` middleware |
| `money`, `phone`, `identity` | App-specific formatted fields — `money` (currency-formatted, prefix set via `custom_field_definition`), `phone` (Malaysian-first e164/national/display), `identity` (MyKad/passport masked + validated) |
| `color`, `color_picker`, `icon_picker` | Picking a color or icon |
| `address_google`, `latlng_picker` | Google Places address lookup / Leaflet map coordinate picker |
| `slug` | Auto-slugifies from another field (set the source field via `custom_field_definition`'s `target` option) |
| `repeatable`, `table` | A repeatable group of sub-fields |
| `page_or_link`, `custom_html`, `view` | Non-input display content dropped into the form |

Whatever you pick here just changes how the field *renders* while a record
sits in this state — it doesn't change the field's real definition on the
model's own CrudController, so switching `type` here is really "temporarily
present this field differently," not "redefine the field." Anything a
type's own options need beyond what the Show/Label/Readonly columns cover
(a select's `options`, a repeatable's sub-fields, a money field's `prefix`,
tabs/wrappers, etc.) goes in that row's **Custom field definition** as a
literal PHP array — e.g. `['options' => ['a' => 'A', 'b' => 'B']]` for a
select, or `['prefix' => 'RM']` for money.

## Test with a sample record (flask icon)

A dry run: walk a real record through the graph exactly as currently
drawn on the canvas — including any unsaved changes — without persisting
anything or firing a single real email, notification, webhook, or timer.

1. Pick a **Sample record** to test with.
2. Optionally pick **Simulate as** a specific user — otherwise transitions
   are checked against you, which often makes everything look
   "unavailable" unless you personally hold every role the graph
   references.
3. The panel shows the record's current node and every outgoing edge from
   it, each marked available or unavailable (with a reason: actor not
   permitted, or a precondition not met).
4. Click **Fire** on an available edge to simulate it. The **Trail**
   section below logs the resulting node(s) reached (a fork can land on
   more than one at once) and a "Would ..." preview of each action that
   would have fired for real.
5. **Reset** clears the trail and starts over from the graph's start node.

## Publishing vs. saving a draft

**Save draft** persists your in-progress edits without making them live —
anyone acting on this workflow right now keeps using whatever version is
currently published. **Publish** makes your edits live immediately (after
a confirmation dialog, since it's a one-way door): every future transition
check reads the newly-published graph. In-flight records that are already
mid-workflow keep running on the version they started on, unless this
model has versioning disabled (the modal's own confirmation text tells you
which applies), in which case in-flight records switch to the new version
immediately too.
