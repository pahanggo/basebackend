<?php

namespace Gis\Commands;

use Gis\Models\CommandEffect;
use Gis\Models\Map;
use Gis\Support\FieldMerge;
use Gis\Support\MapAccess;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything a command needs to apply itself, and the place the batch's results
 * accumulate.
 *
 * One context per batch. It is not a service and is never bound in the
 * container: Octane keeps singletons between requests, and this holds the
 * acting user and a half-applied batch.
 */
class CommandContext
{
    /** @var array<int, Effect> */
    protected array $effects = [];

    /** @var array<int, array<string, mixed>> */
    protected array $conflicts = [];

    /** @var array<string, int> tempId => assigned id */
    protected array $resolved = [];

    public function __construct(
        public readonly Map $map,
        public readonly MapAccess $access,
        public readonly int $userId,
        public readonly FieldMerge $merge,
    ) {}

    public function record(Effect $effect): void
    {
        $this->effects[] = $effect;

        if ($effect->tempId !== null) {
            $this->resolved[$effect->tempId] = $effect->id;
        }
    }

    /**
     * A later command in the same batch may address a row an earlier one
     * created, by its `tempId`. Without this, creating a layer and placing a
     * feature in it would need two round trips and would not be atomic.
     */
    public function resolveTempId(string $tempId): ?int
    {
        return $this->resolved[$tempId] ?? null;
    }

    /** @return array<int, Effect> */
    public function effects(): array
    {
        return $this->effects;
    }

    /** @return array<int, array<string, mixed>> */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }

    /**
     * The optimistic-locking check, with the merge attempt built in.
     *
     * Returns true when the command may proceed, and true again — with
     * `$merged` set — when it is stale but touches fields nobody else has.
     * Returns false once the conflict has been recorded; the caller skips the
     * command and the batch rolls back whole, because a partly applied batch is
     * the one thing the client cannot reason about.
     *
     * @param  array<int, string>  $fields
     */
    public function guardVersion(string $entity, Model $row, int $claimed, array $fields, ?bool &$merged = null): bool
    {
        $current = (int) $row->getAttribute('version');
        $merged = false;

        if ($claimed === $current) {
            return true;
        }

        if ($claimed < $current && $this->merge->mayMerge($entity, (int) $row->getKey(), $claimed, $fields)) {
            $merged = true;

            return true;
        }

        $this->conflicts[] = $this->describeConflict($entity, $row, $claimed, $current);

        return false;
    }

    /**
     * Enough server state to resolve without a second round trip.
     *
     * @return array<string, mixed>
     */
    protected function describeConflict(string $entity, Model $row, int $claimed, int $current): array
    {
        $server = ['updatedAt' => $row->getAttribute('updated_at')?->toIso8601String()];

        foreach (['geom', 'properties', 'name', 'style', 'label', 'classification'] as $field) {
            if (! array_key_exists($field, $row->getAttributes())) {
                continue;
            }

            $value = $row->getAttribute($field);

            $server[$field] = $field === 'geom' && $value !== null
                ? \Gis\Casts\GeometryCast::toWkbBase64($value)
                : $value;
        }

        return [
            'entity' => $entity,
            'id' => (int) $row->getKey(),
            'yourVersion' => $claimed,
            'serverVersion' => $current,
            'server' => $server,
            'updatedBy' => $this->lastWriter($entity, (int) $row->getKey()),
        ];
    }

    /**
     * Who last wrote this row, from the command log.
     *
     * Not a column on the row itself: the log already knows, and a
     * `last_updated_by` column would be a second answer to the same question
     * that could disagree with the first.
     */
    protected function lastWriter(string $entity, int $id): ?int
    {
        $effect = CommandEffect::query()
            ->where('entity', $entity)
            ->where('entity_id', $id)
            ->orderByDesc('version')
            ->with('log:id,user_id')
            ->first();

        return $effect?->log?->user_id;
    }
}
