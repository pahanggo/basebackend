<?php

namespace Gis\Commands;

/**
 * What one command did: the row it touched, the version that row now carries,
 * and the fields it wrote.
 *
 * The field list is not bookkeeping. It is what makes the next client's stale
 * command mergeable instead of conflicting, so a command that under-reports
 * what it wrote will silently lose someone else's edit.
 */
class Effect
{
    /**
     * @param  string  $entity  feature | layer | placement | measurement | map
     * @param  array<int, string>  $fields  written field names, `properties.x` for one property
     * @param  string|null  $tempId  echoed back so the client can map its optimistic id
     */
    public function __construct(
        public readonly string $entity,
        public readonly int $id,
        public readonly int $version,
        public readonly array $fields,
        public readonly ?string $tempId = null,
        public readonly bool $merged = false,
    ) {}

    public function asMerged(): self
    {
        return new self($this->entity, $this->id, $this->version, $this->fields, $this->tempId, true);
    }

    /** @return array<string, mixed> */
    public function toResponse(): array
    {
        $entry = ['entity' => $this->entity, 'id' => $this->id, 'version' => $this->version];

        if ($this->tempId !== null) {
            $entry['tempId'] = $this->tempId;
        }

        if ($this->merged) {
            // The client applied its own edit optimistically and is now told
            // the server folded it into someone else's. Without the notice the
            // merge is invisible, which is the one thing a merge must not be.
            $entry['merged'] = true;
            $entry['fields'] = $this->fields;
        }

        return $entry;
    }
}
