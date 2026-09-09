<?php

namespace App\Models\KitchenSink;

use Backpack\CRUD\app\Models\Traits\CrudTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KitchenSinkCategory extends Model
{
    use CrudTrait;

    protected $connection = 'kitchensink';

    protected $table = 'kitchen_sink_categories';

    protected $guarded = ['id'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(KitchenSinkGroup::class, 'kitchen_sink_group_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
