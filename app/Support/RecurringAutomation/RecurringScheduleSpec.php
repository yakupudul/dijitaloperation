<?php

namespace App\Support\RecurringAutomation;

use App\Enums\RecurringFrequency;
use App\Enums\RecurringMisfirePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Canonical recurrence specification for shared automation (Prompt 61).
 */
readonly class RecurringScheduleSpec
{
    /**
     * @param  list<int>|null  $weekdays  ISO-8601 weekdays 1 (Mon) .. 7 (Sun)
     */
    public function __construct(
        public string $timezone,
        public RecurringFrequency $frequency,
        public int $interval,
        public ?string $localTime = null,
        public ?array $weekdays = null,
        public ?int $dayOfMonth = null,
        public string $monthEndPolicy = 'day_of_month',
        public ?CarbonImmutable $startsAt = null,
        public ?CarbonImmutable $endsAt = null,
        public RecurringMisfirePolicy $misfirePolicy = RecurringMisfirePolicy::SkipMissed,
    ) {}

    /**
     * Stable fingerprint of execution-relevant fields (excludes starts/ends presentation noise that
     * does not change how a given occurrence is computed once scheduled).
     */
    public function fingerprint(): string
    {
        $payload = [
            'timezone' => $this->timezone,
            'frequency' => $this->frequency->value,
            'interval' => $this->interval,
            'local_time' => $this->localTime,
            'weekdays' => $this->weekdays === null ? null : array_values($this->weekdays),
            'day_of_month' => $this->dayOfMonth,
            'month_end_policy' => $this->monthEndPolicy,
            'starts_at' => $this->startsAt?->toIso8601String(),
            'ends_at' => $this->endsAt?->toIso8601String(),
            'misfire_policy' => $this->misfirePolicy->value,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function assertValid(): void
    {
        try {
            new \DateTimeZone($this->timezone);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['timezone' => 'INVALID_TIMEZONE']);
        }

        if ($this->interval < 1) {
            throw ValidationException::withMessages(['interval' => 'INVALID_INTERVAL']);
        }

        if (! in_array($this->monthEndPolicy, ['day_of_month', 'last_day_of_month'], true)) {
            throw ValidationException::withMessages(['month_end_policy' => 'INVALID_MONTH_END_POLICY']);
        }

        if ($this->localTime !== null && ! preg_match('/^\d{2}:\d{2}$/', $this->localTime)) {
            throw ValidationException::withMessages(['local_time' => 'INVALID_LOCAL_TIME']);
        }

        if (in_array($this->frequency, [
            RecurringFrequency::Daily,
            RecurringFrequency::Weekly,
            RecurringFrequency::Monthly,
        ], true) && $this->localTime === null) {
            throw ValidationException::withMessages(['local_time' => 'LOCAL_TIME_REQUIRED']);
        }

        if ($this->frequency === RecurringFrequency::Weekly) {
            if ($this->weekdays === null || $this->weekdays === []) {
                throw ValidationException::withMessages(['weekdays' => 'WEEKDAYS_REQUIRED']);
            }
            foreach ($this->weekdays as $day) {
                if (! is_int($day) || $day < 1 || $day > 7) {
                    throw ValidationException::withMessages(['weekdays' => 'INVALID_WEEKDAY']);
                }
            }
        }

        if ($this->frequency === RecurringFrequency::Monthly) {
            if ($this->monthEndPolicy === 'day_of_month') {
                if ($this->dayOfMonth === null || $this->dayOfMonth < 1 || $this->dayOfMonth > 31) {
                    throw ValidationException::withMessages(['day_of_month' => 'INVALID_DAY_OF_MONTH']);
                }
            }
        }

        if ($this->startsAt !== null && $this->endsAt !== null && $this->endsAt->lessThan($this->startsAt)) {
            throw ValidationException::withMessages(['ends_at' => 'ENDS_BEFORE_STARTS']);
        }
    }
}
