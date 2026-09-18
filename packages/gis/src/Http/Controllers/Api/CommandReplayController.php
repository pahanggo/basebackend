<?php

namespace Gis\Http\Controllers\Api;

use Gis\Http\ProblemResponse;
use Gis\Models\CommandLog;
use Gis\Models\Map;
use Gis\Support\IdempotencyStore;
use Gis\Support\MapAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Bring a client that fell behind forward, by replaying what it missed.
 *
 * This is the reason the command log is shaped the way it is rather than being
 * a write-only audit trail (specification section 16). A client whose websocket
 * dropped for a minute, or that was backgrounded on a phone, knows the map
 * version it last saw; replaying six commands is cheaper than re-reading a map
 * whose layers hold 1.4 million features, and it preserves the client's own
 * pending queue, which a re-read would silently invalidate.
 *
 * It answers **honestly when it cannot help**: if the log no longer reaches
 * back to the version the client holds, it says so and the client re-reads.
 * Replaying a partial history would leave the client believing it is current
 * when it is not, which is worse than the round trip it saves.
 */
class CommandReplayController extends Controller
{
    /** A client this far behind should re-read rather than replay. */
    protected const MAX_COMMANDS = 500;

    public function __invoke(Request $request, Map $map): JsonResponse
    {
        $access = MapAccess::resolve($map, (int) $request->user()->getKey());

        if ($access === null) {
            return ProblemResponse::make(
                'command_unauthorized',
                403,
                'No access to this map',
                'You are not a member of this map.',
            );
        }

        $since = max(0, (int) $request->query('since', '0'));

        $earliest = (int) CommandLog::query()->where('map_id', $map->id)->min('map_version');
        $pruned = CommandLog::query()
            ->where('map_id', $map->id)
            ->where('created_at', '<', (new IdempotencyStore)->expiredBefore())
            ->exists();

        // The client's version has to be one the log can start from: either it
        // is already current, or the entry immediately after it still exists.
        $reachable = $since >= $map->version
            || ($earliest > 0 && $since + 1 >= $earliest);

        if (! $reachable) {
            return new JsonResponse([
                'mapVersion' => $map->version,
                'canReplay' => false,
                'reason' => $pruned ? 'log_pruned' : 'log_incomplete',
            ]);
        }

        $logs = CommandLog::query()
            ->where('map_id', $map->id)
            ->where('map_version', '>', $since)
            ->orderBy('map_version')
            ->limit(self::MAX_COMMANDS + 1)
            ->get(['map_version', 'client_id', 'seq', 'user_id', 'commands', 'response']);

        if ($logs->count() > self::MAX_COMMANDS) {
            return new JsonResponse([
                'mapVersion' => $map->version,
                'canReplay' => false,
                'reason' => 'too_far_behind',
            ]);
        }

        return new JsonResponse([
            'mapVersion' => $map->version,
            'canReplay' => true,
            'batches' => $logs->map(fn (CommandLog $log) => [
                'mapVersion' => $log->map_version,
                'clientId' => $log->client_id,
                'seq' => $log->seq,
                'userId' => $log->user_id,
                'commands' => $log->commands,
                'applied' => $log->response['applied'] ?? [],
            ])->all(),
        ]);
    }
}
