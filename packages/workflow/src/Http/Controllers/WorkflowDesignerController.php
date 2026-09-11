<?php

namespace Workflow\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Workflow\Models\WorkflowDefinition;

/**
 * The canvas/inspector editor — deliberately a standalone controller/view,
 * not a Backpack CRUD or custom field, since it's a full-page interactive
 * experience that doesn't fit a form-field container. See the "Package
 * structure & distribution" and "Backpack-facing surface" sections of the
 * architecture plan.
 *
 * Saving is two distinct flows, so a developer can iterate on a draft
 * without disturbing whatever is currently live:
 * - "Save draft" repeatedly updates the SAME unpublished draft row (there's
 *   at most one per definition) — no version number is assigned yet, and
 *   the definition's published_version_id is never touched.
 * - "Publish" promotes that draft into a real, immutable, numbered version
 *   (assigning the next version number and a published_at timestamp) and
 *   makes it the published one, in one step. Existing instances stay pinned
 *   to whichever version they started on either way.
 */
class WorkflowDesignerController
{
    public function edit(WorkflowDefinition $workflowDefinition): View
    {
        // Resume from the in-progress draft if there is one, otherwise from
        // whatever was last published, so a draft is never lost.
        $version = $workflowDefinition->latestVersion();
        $graph = $version?->graph ?? ['start' => null, 'nodes' => [], 'edges' => []];

        return view('workflow::designer.edit', [
            'definition' => $workflowDefinition,
            'graph' => $graph,
            'latestVersion' => $version,
        ]);
    }

    public function update(Request $request, WorkflowDefinition $workflowDefinition): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'graph' => 'required|json',
            'action' => 'required|in:draft,publish',
        ]);

        $graph = json_decode($validated['graph'], true, flags: JSON_THROW_ON_ERROR);
        $publish = $validated['action'] === 'publish';

        // Draft saves reuse the same row (upsert), so version numbers only
        // ever advance when something is actually published.
        $version = $workflowDefinition->draftVersion()
            ?? $workflowDefinition->versions()->make(['published_at' => null]);
        $version->graph = $graph;

        $message = 'Saved draft — not yet published; whatever is currently live is unaffected.';

        if ($publish) {
            $version->version = ($workflowDefinition->versions()->max('version') ?? 0) + 1;
            $version->published_at = now();
            $version->save();

            $workflowDefinition->update(['published_version_id' => $version->id]);
            $message = "Saved and published version {$version->version}. In-flight instances on earlier versions are unaffected.";
        } else {
            $version->save();
        }

        // "Save draft" is fired via AJAX so the canvas/selection state isn't
        // lost to a full page reload — Publish still does a normal form
        // submit/redirect, since navigating back to a freshly-published
        // version is the point there.
        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'version' => $version->version,
            ]);
        }

        \Alert::success($message)->flash();

        return redirect()->route('workflow.designer.edit', $workflowDefinition);
    }
}
