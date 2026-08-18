<?php

namespace App\Events\Collection;

use App\Models\Collection\CollectionRun;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Minimal invalidation signal — UI must reconcile from DB.
 * Reverb optional; polling remains authoritative fallback.
 */
class CollectionRunChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $collectionRunId,
        public readonly string $collectionRunUuid,
        public readonly string $status,
        public readonly ?int $customerId = null,
    ) {}

    public static function fromRun(CollectionRun $run): self
    {
        return new self(
            collectionRunId: (int) $run->id,
            collectionRunUuid: (string) $run->uuid,
            status: $run->status->value,
            customerId: $run->customer_id !== null ? (int) $run->customer_id : null,
        );
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('collection-runs.'.$this->collectionRunUuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'collection.run.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'uuid' => $this->collectionRunUuid,
            'status' => $this->status,
            // Intentionally minimal — no credentials, payloads, or full run graphs.
        ];
    }
}
