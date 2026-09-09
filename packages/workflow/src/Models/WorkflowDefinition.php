<?php

namespace Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowDefinition extends Model
{
    protected $connection = 'workflow';

    protected $fillable = ['name', 'slug', 'description', 'model', 'published_version_id'];

    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowDefinitionVersion::class);
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinitionVersion::class, 'published_version_id');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class);
    }
}
