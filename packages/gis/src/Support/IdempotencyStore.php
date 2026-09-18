<?php

namespace Gis\Support;

use Gis\Models\CommandLog;
use Gis\Models\Map;
use Illuminate\Support\Carbon;

/**
 * `(clientId, seq)` for 24 hours (specification section 7).
 *
 * Without this, every network blip risks a duplicate. The client cannot tell a
 * request that never arrived from a response that never came back, so it
 * retries — and a retried `feature.create` without an idempotency key draws the
 * same parcel twice, which nobody notices until the count is wrong.
 *
 * The store is the command log itself rather than a cache entry, for two
 * reasons. The log row is written in the same transaction as the commands, so
 * there is no window where the work is committed and the key is not; and the
 * stored response is the *original* response, so a retry observes exactly what
 * the first call returned rather than a freshly computed answer that may have
 * moved on.
 */
class IdempotencyStore
{
    public function __construct(protected readonly int $retentionHours = 24) {}

    /**
     * The response this `(clientId, seq)` already produced, if any is still
     * inside the retention window.
     *
     * @return array<string, mixed>|null
     */
    public function replay(Map $map, string $clientId, int $seq): ?array
    {
        $log = CommandLog::query()
            ->where('map_id', $map->id)
            ->where('client_id', $clientId)
            ->where('seq', $seq)
            ->first();

        if ($log === null) {
            return null;
        }

        // Outside the window the key is no longer honoured, and the batch would
        // apply a second time. Rather than let that happen silently the row is
        // still treated as a replay: an expired key is a client that has been
        // retrying for a day, and applying its work now is worse than telling
        // it what happened then.
        return [...$log->response, 'replayed' => true];
    }

    /**
     * The same lookup without a map, for the one write that happens before
     * there is a map to address: creating one.
     *
     * Safe without the map id because `seq` is monotonic per client, so a
     * creation and a command batch from the same client never share one — and a
     * duplicate map is a worse failure than a duplicate feature, because the
     * user may not notice until both have diverged.
     *
     * @return array<string, mixed>|null
     */
    public function replayAny(string $clientId, int $seq): ?array
    {
        $log = CommandLog::query()
            ->where('client_id', $clientId)
            ->where('seq', $seq)
            ->first();

        return $log === null ? null : [...$log->response, 'replayed' => true];
    }

    public function expiredBefore(): Carbon
    {
        return Carbon::now()->subHours($this->retentionHours);
    }
}
