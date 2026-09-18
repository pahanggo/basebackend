<?php

namespace Gis\Commands;

/**
 * One mutation, server side.
 *
 * The client has a mirror of this object with the same `op` and the same
 * payload shape, because **the client's command vocabulary and the API's are
 * the same vocabulary** (specification section 7). A translation layer between
 * the two would mean the design has gone wrong; if you find yourself writing
 * one, the shapes have drifted and one of them is the bug.
 *
 * Adding an op is: write a handler, register it in `CommandRegistry`, and write
 * its client counterpart. Nothing else in the pipeline changes — the envelope,
 * idempotency, the version guard, the merge, the log and the broadcast are all
 * op-agnostic.
 */
abstract class Command
{
    /** @param array<string, mixed> $payload */
    public function __construct(protected readonly array $payload) {}

    /** The wire name, as it appears in the catalogue in specification section 7. */
    abstract public static function op(): string;

    /** Refuse before anything is applied; an unauthorised command rejects the whole batch. */
    abstract public function authorize(CommandContext $context): bool;

    /** Apply, recording one `Effect` per row touched. */
    abstract public function apply(CommandContext $context): void;

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    protected function has(string $field): bool
    {
        return array_key_exists($field, $this->payload) && $this->payload[$field] !== null;
    }

    protected function get(string $field, mixed $default = null): mixed
    {
        return $this->payload[$field] ?? $default;
    }

    protected function required(string $field): mixed
    {
        if (! $this->has($field)) {
            throw CommandFailed::missingField(static::op(), $field);
        }

        return $this->payload[$field];
    }

    protected function requiredInt(string $field): int
    {
        return (int) $this->required($field);
    }

    /**
     * The `tempId` this command targets, when it addresses a row that does not
     * exist yet because an earlier command in the same batch creates it.
     */
    public function pendingTarget(): ?string
    {
        $value = $this->payload['id'] ?? null;

        return is_string($value) && ! ctype_digit($value) ? $value : null;
    }

    /**
     * An id that may be a `tempId` for a row created earlier in this same batch.
     */
    protected function resolveId(CommandContext $context, string $field = 'id'): int
    {
        $value = $this->required($field);

        if (is_string($value) && ! ctype_digit($value)) {
            $resolved = $context->resolveTempId($value);

            if ($resolved === null) {
                throw CommandFailed::missingField(static::op(), $field);
            }

            return $resolved;
        }

        return (int) $value;
    }
}
