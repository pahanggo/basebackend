<?php

namespace Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowTimer extends Model
{
    protected $connection = 'workflow';

    protected $fillable = ['workflow_instance_token_id', 'edge_id', 'stage', 'fire_at', 'fired_at'];

    protected $casts = [
        'fire_at' => 'datetime',
        'fired_at' => 'datetime',
    ];

    public function token(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstanceToken::class, 'workflow_instance_token_id');
    }

    public function isDue(): bool
    {
        return $this->fired_at === null && $this->fire_at->isPast();
    }
}
