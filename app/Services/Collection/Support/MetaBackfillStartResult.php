<?php

namespace App\Services\Collection\Support;

use App\Models\Collection\CollectionRun;

final class MetaBackfillStartResult
{
    public function __construct(
        public readonly string $outcome,
        public readonly string $message,
        public readonly ?CollectionRun $collectionRun,
        public readonly MetaBackfillPreflightResult $preflight,
        public readonly bool $reusedExisting = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'message' => $this->message,
            'reused_existing' => $this->reusedExisting,
            'collection_run_uuid' => $this->collectionRun?->uuid,
            'collection_run_id' => $this->collectionRun?->id,
            'preflight' => $this->preflight->toArray(),
        ];
    }
}
