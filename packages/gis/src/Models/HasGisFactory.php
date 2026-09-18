<?php

namespace Gis\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Factories for this package live in the package, not in the application's
 * `Database\Factories` namespace, so the default resolution has to be
 * redirected. Everything else about `HasFactory` is kept.
 *
 * @template TFactory of Factory
 */
trait HasGisFactory
{
    /** @use HasFactory<TFactory> */
    use HasFactory {
        newFactory as protected defaultNewFactory;
    }

    protected static function newFactory()
    {
        $factory = 'Gis\\Database\\Factories\\'.class_basename(static::class).'Factory';

        return class_exists($factory) ? $factory::new() : static::defaultNewFactory();
    }
}
