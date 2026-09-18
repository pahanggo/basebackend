<?php

namespace Gis\Commands;

use RuntimeException;

/**
 * A command that cannot be applied, carrying everything an RFC 7807 problem
 * document needs.
 *
 * `$errorCode` is the stable part: clients branch on it, and it may not change.
 * The title and detail are for humans and may (specification section 7).
 */
class CommandFailed extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra  members merged into the problem document
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        public readonly string $title,
        string $detail = '',
        public readonly array $extra = [],
    ) {
        parent::__construct($detail === '' ? $title : $detail);
    }

    public static function unauthorized(string $op): self
    {
        return new self(
            'command_unauthorized',
            403,
            'Command not permitted',
            "You may not perform {$op} in this map.",
            ['op' => $op],
        );
    }

    public static function layerLocked(int $layerId): self
    {
        return new self(
            'layer_locked',
            409,
            'Layer locked',
            'That layer is locked against editing.',
            ['layerId' => $layerId],
        );
    }

    public static function unknownOp(string $op): self
    {
        return new self(
            'unknown_op',
            422,
            'Unknown command',
            "No handler is registered for {$op}.",
            ['op' => $op],
        );
    }

    public static function missingField(string $op, string $field): self
    {
        return new self(
            'command_invalid',
            422,
            'Command incomplete',
            "{$op} requires {$field}.",
            ['op' => $op, 'field' => $field],
        );
    }

    public static function notFound(string $entity, int $id): self
    {
        return new self(
            'command_invalid',
            422,
            'Target not found',
            "No {$entity} with id {$id} in this map.",
            ['entity' => $entity, 'entityId' => $id],
        );
    }

    public static function invalidGeometry(string $detail): self
    {
        return new self('geometry_invalid', 422, 'Geometry invalid', $detail);
    }

    public static function invalidProperty(string $detail): self
    {
        return new self('command_invalid', 422, 'Property invalid', $detail);
    }

    public static function vertexLimit(int $count, int $limit): self
    {
        return new self(
            'vertex_limit',
            422,
            'Too many vertices',
            "The geometry has {$count} vertices; the limit is {$limit}.",
            ['vertices' => $count, 'limit' => $limit],
        );
    }

    public static function batchTooLarge(int $count, int $limit): self
    {
        return new self(
            'batch_too_large',
            422,
            'Batch too large',
            "The batch holds {$count} commands; the limit is {$limit}.",
            ['count' => $count, 'limit' => $limit],
        );
    }
}
