<?php

namespace Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A token is a single active "position" within an instance. A linear workflow
 * has exactly one active token at a time; a fork spawns one token per branch
 * (sharing a fork_group_id); a join consumes all its expected sibling tokens.
 */
class WorkflowInstanceToken extends Model
{
    protected $connection = 'workflow';

    protected $fillable = [
        'workflow_instance_id',
        'node_id',
        'status',
        'fork_group_id',
        'consumed_at',
    ];

    protected $casts = [
        'consumed_at' => 'datetime',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function consume(): void
    {
        $this->update(['status' => 'consumed', 'consumed_at' => now()]);

        // A consumed token is no longer awaiting anyone's action — without this,
        // the "My Tasks" widget would keep surfacing it after it's already moved on.
        WorkflowInstancePendingActor::where('workflow_instance_token_id', $this->id)->delete();
    }
}
