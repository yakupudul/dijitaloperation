<?php

namespace App\Services\Collection;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Throwable;

/**
 * Fail explicitly when production collection queue infrastructure is unavailable.
 */
final class CollectionQueueGate
{
    public function assertReady(): void
    {
        $connection = (string) config('moxdop-collection.queue_connection', 'redis');
        $queue = (string) config('moxdop-collection.queue', 'collection');

        if ($connection === 'sync') {
            throw new RuntimeException('Collection engine rejects QUEUE sync connection for production collection.');
        }

        if (! config('moxdop-collection.require_queue_connection', true)) {
            return;
        }

        $connections = Config::get('queue.connections', []);
        if (! isset($connections[$connection])) {
            throw new RuntimeException("Collection queue connection [{$connection}] is not configured.");
        }

        // Configuration presence is not readiness. Touch the real queue backend so a
        // dead Redis socket/DNS/auth/database fails before a CollectionRun is created.
        try {
            Queue::connection($connection)->size($queue);
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Collection queue [{$connection}:{$queue}] is unreachable: {$e->getMessage()}",
                previous: $e,
            );
        }
    }
}
