<?php

namespace App\Models\KitchenSink;

use Backpack\CRUD\app\Models\Traits\CrudTrait;
use Illuminate\Database\Eloquent\Model;

class KitchenSinkTag extends Model
{
    use CrudTrait;

    protected $connection = 'kitchensink';

    protected $table = 'kitchen_sink_tags';

    protected $guarded = ['id'];
}
