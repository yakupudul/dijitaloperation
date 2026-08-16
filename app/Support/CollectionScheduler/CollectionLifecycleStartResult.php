<?php

namespace App\Support\CollectionScheduler;

use App\Enums\Collection\CollectionLifecycleIntent;
use App\Models\Collection\CollectionRun;

/**
 * Result of executing a collection lifecycle plan.
 */
final class CollectionLifecycleStartResult
{
    /**
     * @param  list<array<string, mixed>>  $decisions
     */
    public function __construct(
        public readonly string $outcome,
        public readonly string $message,
        public readonly ?CollectionLifecycleIntent $intent = null,
        public readonly ?CollectionRun $collectionRun = null,
        public readonly bool $reusedExisting = false,
        public readonly ?ImmutableCollectionLifecyclePlan $plan = null,
        public readonly array $decisions = [],
        public readonly ?string $blockReason = null,
    ) {}
}
