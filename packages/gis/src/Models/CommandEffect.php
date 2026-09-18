<?php

namespace Gis\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one command did to one row, at field granularity.
 *
 * This is the table the conflict merge reads, and it is the only reason the
 * command log is not simply a blob: "which fields of feature 1201 changed after
 * version 4" has to be an indexed query, not a JSON scan.
 *
 * @property int $log_id
 * @property int $map_id
 * @property string $entity  feature | layer | placement | measurement | map
 * @property int $entity_id
 * @property int $version
 * @property array<int, string> $fields
 */
class CommandEffect extends GisModel
{
    protected $table = 'gis_command_effects';

    public $timestamps = false;

    protected $fillable = ['log_id', 'map_id', 'entity', 'entity_id', 'version', 'fields'];

    protected $casts = [
        'log_id' => 'integer',
        'map_id' => 'integer',
        'entity_id' => 'integer',
        'version' => 'integer',
        'fields' => 'array',
    ];

    public function log(): BelongsTo
    {
        return $this->belongsTo(CommandLog::class, 'log_id');
    }
}
