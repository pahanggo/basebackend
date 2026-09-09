<?php

namespace App\Models\KitchenSink;

use Backpack\CRUD\app\Models\Traits\CrudTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KitchenSinkGroup extends Model
{
    use CrudTrait;

    protected $connection = 'kitchensink';

    protected $table = 'kitchen_sink_groups';

    protected $guarded = ['id'];

    public function categories(): HasMany
    {
        return $this->hasMany(KitchenSinkCategory::class);
    }
}
