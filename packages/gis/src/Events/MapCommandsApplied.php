<?php

namespace Gis\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A batch landed on a map.
 *
 * In v1 this carries conflict notices and nothing else: another client's edit
 * arriving tells a client its own pending commands may now be stale, which is
 * the difference between finding out at save time and finding out immediately.
 *
 * It is here rather than in v2 because real-time co-editing is expected within
 * a year (specification section 23). A websocket server stood up only for this
 * would not be worth its operational surface; one that presence and live
 * cursors will need anyway is far cheaper to introduce now, before there is a
 * feature depending on it (section 5).
 *
 * **What ships is the event and its channel, not the server.** The deployment's
 * broadcast driver is `log`, and `laravel/reverb` is not installed — that is a
 * dependency and an operational decision for the deployment, not something this
 * package installs on its behalf. Switching to Reverb changes
 * `BROADCAST_DRIVER` and nothing in this file.
 */
class MapCommandsApplied implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $applied
     */
    public function __construct(
        public readonly int $mapId,
        public readonly int $mapVersion,
        public readonly string $clientId,
        public readonly array $applied,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("map.{$this->mapId}");
    }

    public function broadcastAs(): string
    {
        return 'commands.applied';
    }

    /**
     * The originating `clientId` travels with the event so the client that sent
     * the batch can ignore its own echo. Without it, every editor applies its
     * own work twice — once optimistically, once off the wire.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'mapVersion' => $this->mapVersion,
            'clientId' => $this->clientId,
            'applied' => $this->applied,
        ];
    }
}
