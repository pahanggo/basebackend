<?php

namespace Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowInstance extends Model
{
    protected $connection = 'workflow';

    protected $fillable = [
        'workflow_definition_id',
        'workflow_definition_version_id',
        'workflowable_type',
        'workflowable_id',
        'status',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinitionVersion::class, 'workflow_definition_version_id');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(WorkflowInstanceToken::class);
    }

    public function activeTokens(): HasMany
    {
        return $this->tokens()->where('status', 'active');
    }

    public function history(): HasMany
    {
        return $this->hasMany(WorkflowInstanceHistory::class);
    }

    /**
     * The workflow-enabled model this instance belongs to. Deliberately not an
     * Eloquent morphTo — the target model usually lives on a different database
     * connection than this instance row, which morphTo does not span cleanly.
     */
    public function workflowable(): ?\Illuminate\Database\Eloquent\Model
    {
        $class = $this->workflowable_type;

        if (! class_exists($class)) {
            return null;
        }

        return $class::find($this->workflowable_id);
    }
}
