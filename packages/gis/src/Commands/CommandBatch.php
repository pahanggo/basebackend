<?php

namespace Gis\Commands;

use Gis\Events\MapCommandsApplied;
use Gis\Models\CommandEffect;
use Gis\Models\CommandLog;
use Gis\Models\Map;
use Gis\Support\FieldMerge;
use Gis\Support\IdempotencyStore;
use Gis\Support\MapAccess;
use Illuminate\Support\Facades\DB;

/**
 * Applies one batch, with the three properties the whole design rests on.
 *
 * 1. **Atomic.** Every command applies or none does, in one transaction. The
 *    client never reasons about partial application, which is what lets it
 *    apply optimistically and roll back whole.
 * 2. **Idempotent.** `(clientId, seq)` is remembered, so a retry after a
 *    timeout replays the original response rather than applying twice.
 * 3. **Symmetrical with undo.** The command objects the client pushed onto its
 *    undo stack are the objects that arrive here. One vocabulary, one
 *    serialisation, no translation layer.
 *
 * Not a singleton and not bound in the container: Octane keeps singletons
 * across requests and this holds per-request state.
 */
class CommandBatch
{
    public function __construct(
        protected readonly IdempotencyStore $idempotency = new IdempotencyStore,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $commands
     * @return array<string, mixed>
     *
     * @throws CommandFailed
     */
    public function apply(MapAccess $access, int $userId, string $clientId, int $seq, array $commands): array
    {
        $limit = (int) config('gis.write.max_batch');

        if (count($commands) > $limit) {
            throw CommandFailed::batchTooLarge(count($commands), $limit);
        }

        $replayed = $this->idempotency->replay($access->map, $clientId, $seq);

        if ($replayed !== null) {
            return $replayed;
        }

        $response = DB::connection(config('gis.connection'))->transaction(
            fn () => $this->run($access, $userId, $clientId, $seq, $commands),
        );

        // After the commit, never inside it: a broadcast from inside a
        // transaction can reach a listener that then reads state the
        // transaction has not committed yet.
        MapCommandsApplied::dispatch(
            $access->map->id,
            $response['mapVersion'],
            $clientId,
            $response['applied'],
        );

        return $response;
    }

    /**
     * @param  array<int, array<string, mixed>>  $commands
     * @return array<string, mixed>
     */
    protected function run(MapAccess $access, int $userId, string $clientId, int $seq, array $commands): array
    {
        // Serialises batches against this map, which is what makes the map
        // version a reliable monotonic sequence — two concurrent batches would
        // otherwise both read version 31 and both try to write 32.
        $map = Map::query()->lockForUpdate()->findOrFail($access->map->id);

        $context = new CommandContext($map, $access, $userId, new FieldMerge);

        $resolved = array_map(CommandRegistry::make(...), array_values($commands));

        // Authorisation is a pass of its own, before anything is applied: one
        // batch may mix commands of differing sensitivity, and a batch holding
        // any unauthorised command is rejected whole (specification section 20).
        $pending = [];

        foreach ($resolved as $command) {
            $tempId = $command->payload()['tempId'] ?? null;

            if (is_string($tempId)) {
                $pending[$tempId] = true;
            }

            // A command addressing a row an earlier command in this batch
            // creates cannot be authorised yet — the row does not exist. It
            // does not need to be: the create that brings it into existence was
            // authorised a moment ago, against the layer it names, and nothing
            // else can reach the row before the transaction commits.
            if (isset($pending[$command->pendingTarget()])) {
                continue;
            }

            if (! $command->authorize($context)) {
                throw CommandFailed::unauthorized($command::op());
            }
        }

        foreach ($resolved as $command) {
            $command->apply($context);
        }

        // Conflicts are collected rather than thrown at the first one, so the
        // client gets every conflicting row in one response and can resolve
        // them together. The batch rolls back either way.
        if ($context->hasConflicts()) {
            throw new VersionConflict($context->conflicts());
        }

        $mapVersion = $map->version + 1;
        $map->forceFill(['version' => $mapVersion])->save();

        $applied = array_map(fn (Effect $effect) => $effect->toResponse(), $context->effects());

        $response = ['mapVersion' => $mapVersion, 'seq' => $seq, 'applied' => $applied];

        $this->log($map, $mapVersion, $clientId, $seq, $userId, $commands, $response, $context->effects());

        return $response;
    }

    /**
     * @param  array<int, array<string, mixed>>  $commands
     * @param  array<string, mixed>  $response
     * @param  array<int, Effect>  $effects
     */
    protected function log(Map $map, int $mapVersion, string $clientId, int $seq, int $userId, array $commands, array $response, array $effects): void
    {
        $log = CommandLog::query()->create([
            'map_id' => $map->id,
            'map_version' => $mapVersion,
            'client_id' => $clientId,
            'seq' => $seq,
            'user_id' => $userId,
            'commands' => array_values($commands),
            'response' => $response,
            'created_at' => now(),
        ]);

        if ($effects === []) {
            return;
        }

        CommandEffect::query()->insert(array_map(fn (Effect $effect) => [
            'log_id' => $log->id,
            'map_id' => $map->id,
            'entity' => $effect->entity,
            'entity_id' => $effect->id,
            'version' => $effect->version,
            'fields' => json_encode($effect->fields, JSON_THROW_ON_ERROR),
        ], $effects));
    }
}
