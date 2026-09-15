<?php

namespace Workflow\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Workflow\HasWorkflow;

/**
 * Builds the "who did it / time spent / time recorded" audit trail shown on
 * a record's show page and its show-workflow page (see
 * packages/workflow/src/resources/views/inc/workflow_timeline.blade.php, shared by both) —
 * one row per workflow_instance_history entry for the record's current
 * workflow instance, in the order they actually happened.
 */
class WorkflowTimeline
{
    /**
     * @return array<int, array{
     *     is_edit: bool, from_label: string, to_label: string, edge_label: string,
     *     trigger: string, actor: string, recorded_at: \Illuminate\Support\Carbon,
     *     time_spent: ?string, inputs: array<int, array{label: string, value: mixed}>,
     * }>
     */
    public function build(Model $workflowable): array
    {
        if (! in_array(HasWorkflow::class, class_uses_recursive($workflowable), true)) {
            return [];
        }

        $instance = $workflowable->workflowInstance();

        if (! $instance) {
            return [];
        }

        $version = $instance->effectiveVersion();

        // Fetched oldest-first regardless of display order — time_spent for
        // each step is "how long since the previous one", which only makes
        // sense computed while walking forward chronologically. Reversed
        // just before returning so callers (and the "show only the last 3"
        // UI) see newest-first.
        $entries = $instance->history()->orderBy('created_at')->orderBy('id')->get();

        $timeline = [];
        $previousAt = $instance->created_at;

        foreach ($entries as $entry) {
            $isEdit = $entry->edge_id === null;
            $edge = $isEdit ? null : $version?->edge($entry->edge_id);

            $timeline[] = [
                'is_edit' => $isEdit,
                'from_label' => $version?->node($entry->from_node_id)['name'] ?? $entry->from_node_id,
                'to_label' => $version?->node($entry->to_node_id)['name'] ?? $entry->to_node_id,
                'edge_label' => $isEdit ? 'Edited fields' : ($edge['name'] ?? $entry->edge_id),
                'trigger' => $entry->trigger,
                'actor' => $this->describeActor($entry->actor_type, $entry->actor_id),
                'recorded_at' => $entry->created_at,
                // An edit doesn't move the record anywhere, so "time spent
                // in the previous state" doesn't apply to it — and it must
                // not consume this step's timestamp as the next step's
                // "previous" baseline either, or a real transition's own
                // duration would be measured from the edit instead of from
                // whatever state change actually preceded it.
                'time_spent' => $isEdit ? null : $this->describeDuration($previousAt, $entry->created_at),
                'inputs' => $isEdit
                    ? $this->describeEditedFields($entry->inputs)
                    : $this->describeInputs($entry->inputs, $edge['inputs'] ?? []),
            ];

            if (! $isEdit) {
                $previousAt = $entry->created_at;
            }
        }

        return array_reverse($timeline);
    }

    /**
     * The transition's captured inputs (see the edge's own `inputs` schema
     * and TransitionEngine::hasRequiredInputs()/applyStoreAs()) with a
     * humanized label per field — e.g. `hod_remarks` becomes "Hod Remarks" —
     * rather than the raw field key. Only inputs whose schema entry
     * explicitly sets `show_in_timeline` to true are included — the edge
     * inspector's per-input "Show in timeline" switch defaults to off, so an
     * input has to be deliberately opted in before it shows here (matches
     * the switch's own default state for a new/never-touched input).
     *
     * @param  array<int, array>  $inputSchema  the edge's declared `inputs`
     * @return array<int, array{label: string, value: mixed}>
     */
    protected function describeInputs(?array $inputs, array $inputSchema = []): array
    {
        $schemaByName = collect($inputSchema)->keyBy('name');

        return collect($inputs ?? [])
            ->filter(fn ($value, string $field) => ($schemaByName->get($field)['show_in_timeline'] ?? false) === true)
            ->map(fn ($value, string $field) => [
                'label' => Str::headline(str_replace('.', ' ', $field)),
                'value' => $value,
            ])
            ->values()
            ->all();
    }

    /**
     * An inline-edit history row's `inputs` is keyed by field name to
     * {label, from, to} (see WorkflowInlineUpdateController::recordEditHistory())
     * rather than an edge's flat captured-value shape — always shown (no
     * show_in_timeline opt-in, unlike a transition's own inputs), since an
     * edit step's whole reason for existing on the timeline is showing what
     * changed.
     *
     * @param  array<string, array{label: string, from: mixed, to: mixed}>|null  $changed
     * @return array<int, array{label: string, value: mixed}>
     */
    protected function describeEditedFields(?array $changed): array
    {
        return collect($changed ?? [])
            ->map(fn (array $change) => [
                'label' => $change['label'],
                'value' => $this->formatScalar($change['from']).' → '.$this->formatScalar($change['to']),
            ])
            ->values()
            ->all();
    }

    protected function formatScalar(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return is_scalar($value) ? (string) $value : json_encode($value);
    }

    protected function describeActor(?string $actorType, mixed $actorId): string
    {
        if (! $actorType) {
            return 'System';
        }

        if (! class_exists($actorType)) {
            return class_basename($actorType)." #{$actorId}";
        }

        $actor = $actorType::find($actorId);

        if (! $actor) {
            return class_basename($actorType)." #{$actorId} (deleted)";
        }

        return $actor->name ?? $actor->email ?? class_basename($actorType)." #{$actorId}";
    }

    /**
     * A compact "2 days 3 hours" style duration — how long the record sat
     * in its previous state before this transition fired — rather than
     * diffForHumans()'s ago/from-now phrasing, which reads oddly for a
     * duration between two past timestamps instead of "now" and a
     * timestamp.
     */
    protected function describeDuration(\Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to): string
    {
        return $from->diffAsCarbonInterval($to)->cascade()->forHumans(['short' => true]);
    }
}
