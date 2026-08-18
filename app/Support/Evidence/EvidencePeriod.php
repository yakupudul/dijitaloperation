<?php

namespace App\Support\Evidence;

final class EvidencePeriod
{
    public function __construct(
        public readonly string $currentStart,
        public readonly string $currentEnd,
        public readonly string $previousStart,
        public readonly string $previousEnd,
        public readonly int $lengthDays,
    ) {}

    /**
     * @return array{current: array{start: string, end: string}, previous: array{start: string, end: string}, length_days: int}
     */
    public function toArray(): array
    {
        return [
            'current' => ['start' => $this->currentStart, 'end' => $this->currentEnd],
            'previous' => ['start' => $this->previousStart, 'end' => $this->previousEnd],
            'length_days' => $this->lengthDays,
        ];
    }
}
