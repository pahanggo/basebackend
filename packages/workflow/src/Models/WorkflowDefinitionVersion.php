<?php

namespace Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable snapshot of a definition's graph. Instances pin to a specific
 * version row so republishing a definition never alters in-flight instances.
 *
 * @property array $graph
 */
class WorkflowDefinitionVersion extends Model
{
    protected $connection = 'workflow';

    protected $fillable = ['workflow_definition_id', 'version', 'graph', 'published_at'];

    protected $casts = [
        'graph' => 'array',
        'published_at' => 'datetime',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    public function node(string $nodeId): ?array
    {
        return collect($this->graph['nodes'] ?? [])->firstWhere('id', $nodeId);
    }

    public function edge(string $edgeId): ?array
    {
        return collect($this->graph['edges'] ?? [])->firstWhere('id', $edgeId);
    }

    /**
     * @return array<int, array> Edges whose "from" is the given node id.
     */
    public function edgesFrom(string $nodeId): array
    {
        return collect($this->graph['edges'] ?? [])->where('from', $nodeId)->values()->all();
    }

    /**
     * How many distinct edges point into a node — for a join node, this is
     * the number of parallel branches it's expected to wait on.
     */
    public function incomingEdgeCount(string $nodeId): int
    {
        return collect($this->graph['edges'] ?? [])->where('to', $nodeId)->count();
    }

    /**
     * Every manual edge in the graph (from any node) whose `surfaces` list
     * declares the given surface — e.g. 'bulk_action' to populate the
     * WorkflowBulkTransitionOperation edge picker, independent of which node
     * any particular record currently sits on.
     *
     * @return array<int, array>
     */
    public function edgesWithSurface(string $surface): array
    {
        return collect($this->graph['edges'] ?? [])
            ->filter(fn (array $edge) => ($edge['trigger'] ?? 'manual') === 'manual'
                && in_array($surface, $edge['surfaces'] ?? ['record_button'], true))
            ->values()
            ->all();
    }
}
