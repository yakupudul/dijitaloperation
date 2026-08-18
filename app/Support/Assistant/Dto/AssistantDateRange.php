<?php

namespace App\Support\Assistant\Dto;

/**
 * Deterministic date range after server resolution (Prompt 56).
 */
final class AssistantDateRange
{
    public function __construct(
        public readonly string $token,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly string $timezone,
        public readonly bool $inclusiveEnd = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'timezone' => $this->timezone,
            'inclusive_end' => $this->inclusiveEnd,
        ];
    }
}
