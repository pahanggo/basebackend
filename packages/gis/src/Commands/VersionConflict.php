<?php

namespace Gis\Commands;

/**
 * A stale command whose fields the server has also changed.
 *
 * The whole batch aborts and nothing is applied, and the payload carries enough
 * server state for the client to resolve without a second round trip
 * (specification section 7).
 *
 * Conflicts that touch *different* fields never reach here — they are merged
 * and applied, which is the machinery co-editing needs and the reason it is
 * built in v1 rather than deferred (section 16).
 */
class VersionConflict extends CommandFailed
{
    /**
     * @param  array<int, array<string, mixed>>  $conflicts
     */
    public function __construct(array $conflicts)
    {
        parent::__construct(
            'version_conflict',
            409,
            'Version conflict',
            'Someone else changed the same fields. Nothing was applied.',
            ['conflicts' => array_values($conflicts)],
        );
    }
}
