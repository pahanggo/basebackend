<?php

namespace Workflow\Actions;

use Workflow\Models\WorkflowTimer;
use Workflow\Registries\WorkflowActionType;
use Workflow\Support\DurationParser;

/**
 * Schedules one or more timer entries against the current token. `params`
 * supports either a single `{after: '3d', edge: '...'}` or a `stages` array
 * for SLA escalation chains: [{after: '24h', ...}, {after: '48h', ...}].
 * A scheduled command (see the "timers:process" console command) scans
 * workflow_timers for due entries and fires them.
 */
class StartTimer implements WorkflowActionType
{
    public function execute(array $params, array $context): void
    {
        foreach ($this->stages($params) as $stage) {
            WorkflowTimer::create([
                'workflow_instance_token_id' => $context['token']->id,
                'edge_id' => $stage['edge'],
                'stage' => $stage['stage'] ?? null,
                'fire_at' => now()->addSeconds(DurationParser::toSeconds($stage['after'])),
            ]);
        }
    }

    public function preview(array $params, array $context): string
    {
        $stages = $this->stages($params);

        return sprintf('Would schedule %d timer stage(s): %s', count($stages), collect($stages)->pluck('after')->implode(', '));
    }

    /**
     * @return array<int, array{after: string, edge: string, stage?: string}>
     */
    protected function stages(array $params): array
    {
        if (isset($params['stages'])) {
            return $params['stages'];
        }

        return [['after' => $params['after'], 'edge' => $params['edge'], 'stage' => $params['stage'] ?? null]];
    }
}
