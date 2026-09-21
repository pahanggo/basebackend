<?php

namespace Backpack\CRUD\app\Models\Traits\SpatieTranslatable;

use Cviebrock\EloquentSluggable\SluggableScopeHelpers as OriginalSluggableScopeHelpers;
use Illuminate\Database\Eloquent\Builder;

trait SluggableScopeHelpers
{
    use OriginalSluggableScopeHelpers;

    /**
     * Query scope for finding a model by its primary slug.
     */
    public function scopeWhereSlug(Builder $scope, string $slug): Builder
    {
        return $scope->where($this->getSlugKeyName().'->'.$this->getLocale(), $slug);
    }
}
