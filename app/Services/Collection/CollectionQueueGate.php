<?php

namespace App\Services\Collection;

use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Fail explicitly when production collection queue infrastructure is unavailable.
 */
final class CollectionQueueGate
{
    public function assertReady(): void
    {
        $connection = (string) config('moxdop-collection.queue_connection', 'redis');

        if ($connection === 'sync') {
            throw new RuntimeException('Collection engine rejects QUEUE sync connection for production collection.');
        }

        if (! config('moxdop-collection.require_queue_connection', true)) {
            return;
        }

        if ($connection !== 'redis') {
            // database queue is acceptable for local/dev without Redis; still background.
            return;
        }

        $connections = Config::get('queue.connections', []);
        if (! isset($connections['redis'])) {
            throw new RuntimeException('Collection queue connection [redis] is not configured.');
        }
    }
}
