<?php

namespace Workflow\Models;

use Backpack\CRUD\app\Models\Traits\CrudTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WorkflowDefinition extends Model
{
    use CrudTrait;

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

    /**
     * The unpublished draft row, if one exists — there's at most one per
     * definition; a version number is only assigned on publish.
     */
    public function draftVersion(): ?WorkflowDefinitionVersion
    {
        return $this->versions()->whereNull('version')->first();
    }

    /**
     * What the designer resumes editing from: the draft if one is in
     * progress, otherwise whatever was last published.
     */
    public function latestVersion(): ?WorkflowDefinitionVersion
    {
        return $this->draftVersion() ?? $this->publishedVersion;
    }

    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class);
    }

    /**
     * The target model's human-facing name — used before any instance
     * exists to pull it from an effective version's graph (see
     * WorkflowDefinitionVersion::displayName()). Falls back to the
     * humanized model class name when there's no version at all yet.
     */
    public function displayName(): string
    {
        return $this->latestVersion()?->displayName() ?? Str::headline(class_basename($this->model));
    }
}
