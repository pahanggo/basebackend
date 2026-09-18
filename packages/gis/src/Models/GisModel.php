<?php

namespace Gis\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base for every model in this package.
 *
 * The package's schema lives on its own connection, resolved per call rather
 * than held in a property, so a deployment can point it elsewhere in config
 * without touching a model.
 */
abstract class GisModel extends Model
{
    public function getConnectionName(): ?string
    {
        return config('gis.connection');
    }
}
