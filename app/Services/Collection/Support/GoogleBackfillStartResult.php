<?php

namespace App\Services\Collection\Support;

use App\Models\Collection\CollectionRun;

final class GoogleBackfillStartResult
{
    /**
     * @param  list<CollectionRun>  $collectionRuns
     */
    public function __construct(
        public readonly string $outcome,
        public readonly string $message,
        public readonly ?CollectionRun $collectionRun,
        public readonly GoogleBackfillPreflightResult $preflight,
        public readonly bool $reusedExisting = false,
        public readonly array $collectionRuns = [],
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
            'collection_run_uuids' => array_values(array_filter(array_map(
                static fn (CollectionRun $run): ?string => $run->uuid,
                $this->collectionRuns !== [] ? $this->collectionRuns : array_filter([$this->collectionRun]),
            ))),
            'preflight' => $this->preflight->toArray(),
        ];
    }
}
