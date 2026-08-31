<?php

namespace App\Support\IntelligenceCore;

use App\Enums\IntelligenceCore\IntelligenceSamplingState;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final class IntelligenceTimeContext
{
    public function __construct(
        public readonly string $sourceTimezone,
        public readonly ?string $reportingDate = null,
        public readonly ?string $periodStart = null,
        public readonly ?string $periodEnd = null,
        public readonly ?DateTimeInterface $observedAt = null,
        public readonly ?DateTimeInterface $retrievedAt = null,
        public readonly ?string $marketCode = null,
        public readonly ?string $languageCode = null,
        public readonly ?string $device = null,
        public readonly ?string $surface = null,
        public readonly ?string $model = null,
        public readonly IntelligenceSamplingState $samplingState = IntelligenceSamplingState::Unknown,
    ) {
        try {
            new DateTimeZone($this->sourceTimezone);
        } catch (\Throwable) {
            throw new InvalidArgumentException("Invalid source timezone [{$this->sourceTimezone}].");
        }

        if ($this->periodStart !== null && $this->periodEnd !== null && $this->periodStart > $this->periodEnd) {
            throw new InvalidArgumentException('Intelligence period start must not be after period end.');
        }
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'source_timezone' => $this->sourceTimezone,
            'reporting_date' => $this->reportingDate,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'observed_at' => $this->observedAt?->format(DATE_ATOM),
            'retrieved_at' => $this->retrievedAt?->format(DATE_ATOM),
            'market_code' => $this->marketCode,
            'language_code' => $this->languageCode,
            'device' => $this->device,
            'surface' => $this->surface,
            'model' => $this->model,
            'sampling_state' => $this->samplingState->value,
        ];
    }
}
