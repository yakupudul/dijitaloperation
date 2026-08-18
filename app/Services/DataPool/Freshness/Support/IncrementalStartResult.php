<?php

namespace App\Services\DataPool\Freshness\Support;

use App\Models\Collection\CollectionRun;

final class IncrementalStartResult
{
    /**
     * @param  list<array<string, mixed>>  $decisions
     */
    public function __construct(
        public readonly string $outcome,
        public readonly string $message,
        public readonly ?CollectionRun $collectionRun = null,
        public readonly bool $reusedExisting = false,
        public readonly array $decisions = [],
    ) {}
}
