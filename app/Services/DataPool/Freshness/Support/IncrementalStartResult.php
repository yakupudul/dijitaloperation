<?php

namespace App\Services\DataPool\Freshness\Support;

use App\Models\Collection\CollectionRun;

final class IncrementalStartResult
{
    /**
     * @param  list<array<string, mixed>>  $decisions
     * @param  list<CollectionRun>  $collectionRuns
     * @param  list<array{
     *   brand_id: ?int,
     *   outcome: string,
     *   message: string,
     *   collection_run_uuid: ?string,
     *   collection_run_id: ?int,
     *   reused_existing: bool
     * }>  $brandResults
     */
    public function __construct(
        public readonly string $outcome,
        public readonly string $message,
        public readonly ?CollectionRun $collectionRun = null,
        public readonly bool $reusedExisting = false,
        public readonly array $decisions = [],
        public readonly array $collectionRuns = [],
        public readonly array $brandResults = [],
    ) {}

    /**
     * @return list<CollectionRun>
     */
    public function allCollectionRuns(): array
    {
        if ($this->collectionRuns !== []) {
            return $this->collectionRuns;
        }

        return $this->collectionRun !== null ? [$this->collectionRun] : [];
    }

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
                $this->allCollectionRuns(),
            ))),
            'brand_results' => $this->brandResults,
            'decisions' => $this->decisions,
        ];
    }
}
