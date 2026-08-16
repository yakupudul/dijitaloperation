<?php

namespace App\Support\CollectionScheduler;

/**
 * Dataset-specific latest-safe reporting frontier (Prompt 62).
 * Never equals "today" unless policy explicitly permits the open period.
 */
final class LatestSafeReportingWindow
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $latestSafeDate,
        public readonly ?string $providerLocalReportingDate,
        public readonly string $timezone,
        public readonly int $policyVersion,
        public readonly ?string $reason = null,
    ) {}

    public function isAvailable(): bool
    {
        return $this->status === 'AVAILABLE' && is_string($this->latestSafeDate);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'latest_safe_date' => $this->latestSafeDate,
            'provider_local_reporting_date' => $this->providerLocalReportingDate,
            'timezone' => $this->timezone,
            'policy_version' => $this->policyVersion,
            'reason' => $this->reason,
        ];
    }
}
