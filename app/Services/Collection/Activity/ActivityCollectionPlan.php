<?php

namespace App\Services\Collection\Activity;

use App\Enums\Collection\ActivityTier;

/**
 * What activity-aware planning allows for one provider account in this planning pass.
 *
 * - full: every dataset (active tier); structure snapshots still pass the change gate.
 * - light: account/property-level daily totals only, continuing from their coverage (idle tier).
 * - check: account/property-level daily totals for the last `checkDays` days only (dormant / paused).
 *
 * `due` is false while an idle / dormant account already had its weekly pass.
 */
final readonly class ActivityCollectionPlan
{
    public const string MODE_FULL = 'full';

    public const string MODE_LIGHT = 'light';

    public const string MODE_CHECK = 'check';

    /** @param list<string> $lightFamilies */
    public function __construct(
        public int $externalResourceId,
        public string $provider,
        public ActivityTier $tier,
        public string $mode,
        public bool $due,
        public bool $paused,
        public ?string $backfillFrom,
        public int $checkDays,
        public array $lightFamilies,
        public ?string $nextDueAt = null,
    ) {}

    public function isFull(): bool
    {
        return $this->mode === self::MODE_FULL;
    }

    /** Whether a request family may be planned in this mode (structure gating is separate). */
    public function allowsFamily(string $familyId): bool
    {
        return $this->isFull() || in_array($familyId, $this->lightFamilies, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tier' => $this->tier->value,
            'mode' => $this->mode,
            'due' => $this->due,
            'operator_paused' => $this->paused,
            'backfill_from' => $this->backfillFrom,
            'check_days' => $this->checkDays,
            'next_due_at' => $this->nextDueAt,
        ];
    }
}
