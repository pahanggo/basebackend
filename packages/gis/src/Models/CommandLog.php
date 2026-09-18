<?php

namespace Gis\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One applied batch.
 *
 * @property int $map_id
 * @property int $map_version
 * @property string $client_id
 * @property int $seq
 * @property int $user_id
 * @property array<int, array<string, mixed>> $commands
 * @property array<string, mixed> $response
 */
class CommandLog extends GisModel
{
    protected $table = 'gis_command_log';

    public $timestamps = false;

    protected $fillable = [
        'map_id', 'map_version', 'client_id', 'seq', 'user_id', 'commands', 'response', 'created_at',
    ];

    protected $casts = [
        'map_id' => 'integer',
        'map_version' => 'integer',
        'seq' => 'integer',
        'user_id' => 'integer',
        'commands' => 'array',
        'response' => 'array',
        'created_at' => 'datetime',
    ];

    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class, 'map_id');
    }

    public function effects(): HasMany
    {
        return $this->hasMany(CommandEffect::class, 'log_id');
    }
}
