<?php

namespace Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowInstanceHistory extends Model
{
    protected $connection = 'workflow';

    // Eloquent's pluralizer would guess "workflow_instance_histories"; the
    // migration deliberately keeps this table singular (it's a log, not a
    // collection of distinct "history" entities).
    protected $table = 'workflow_instance_history';

    public $timestamps = false;

    protected $fillable = [
        'workflow_instance_id',
        'workflow_instance_token_id',
        'from_node_id',
        'to_node_id',
        'edge_id',
        'trigger',
        'actor_type',
        'actor_id',
        'inputs',
        'created_at',
    ];

    protected $casts = [
        'inputs' => 'array',
        'created_at' => 'datetime',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }
}
