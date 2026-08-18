<?php

namespace App\Services\DataPool\Freshness\Support;

use App\Enums\DataPool\FreshnessState;

final class FreshnessEvaluation
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly FreshnessState $state,
        public readonly bool $collectionDue,
        public readonly bool $reprocessDue,
        public readonly string $reason,
        public readonly array $details = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'collection_due' => $this->collectionDue,
            'reprocess_due' => $this->reprocessDue,
            'reason' => $this->reason,
            'details' => $this->details,
        ];
    }
}
