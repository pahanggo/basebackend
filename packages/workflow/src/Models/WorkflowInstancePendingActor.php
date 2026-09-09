<?php

namespace Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Denormalized row: "this actor can act on this token's edge right now."
 * Recomputed whenever a token enters a node, so the "My Tasks" widget is a
 * cheap indexed query instead of evaluating actor_rules live per instance.
 */
class WorkflowInstancePendingActor extends Model
{
    protected $connection = 'workflow';

    public $timestamps = false;

    protected $fillable = [
        'workflow_instance_id',
        'workflow_instance_token_id',
        'edge_id',
        'actor_type',
        'actor_id',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstanceToken::class, 'workflow_instance_token_id');
    }
}
