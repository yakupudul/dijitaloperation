<?php

namespace App\Services\Collection\Support;

use Carbon\CarbonImmutable;

/**
 * Explicit clock for collection planning — avoids scattering now() in planners.
 */
final class CollectionClock
{
    public function __construct(
        private readonly ?CarbonImmutable $fixedNow = null,
    ) {}

    public function today(string $timezone = 'UTC'): CarbonImmutable
    {
        return $this->now($timezone)->startOfDay();
    }

    public function now(string $timezone = 'UTC'): CarbonImmutable
    {
        if ($this->fixedNow !== null) {
            return $this->fixedNow->timezone($timezone);
        }

        return CarbonImmutable::now($timezone);
    }

    public function withFixedNow(CarbonImmutable $now): self
    {
        return new self($now);
    }
}
