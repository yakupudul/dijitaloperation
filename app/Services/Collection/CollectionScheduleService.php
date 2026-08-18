<?php

namespace App\Services\Collection;

use App\Enums\CollectionScheduleStatus;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringMisfirePolicy;
use App\Models\Brand;
use App\Models\CollectionSchedule;
use App\Models\DigitalAsset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Brand/Asset-scoped recurring collection schedule configuration (Prompt 61/62).
 * Creating an Active schedule is the explicit operator enablement for the
 * automatic Backfill → Incremental → Late Repair lifecycle.
 */
final class CollectionScheduleService
{
    /**
     * @param  array{
     *     frequency?: string,
     *     interval?: int,
     *     timezone?: string,
     *     local_time?: string,
     * }  $input
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     */
    public function create(
        DigitalAsset $asset,
        array $input,
        ?User $actor = null,
        array $authorizedCustomerIds = [],
        array $authorizedBrandIds = [],
    ): CollectionSchedule {
        $brand = Brand::query()->findOrFail($asset->brand_id);
        $this->assertAuthorized($brand, $authorizedCustomerIds, $authorizedBrandIds);

        $frequency = RecurringFrequency::tryFrom((string) ($input['frequency'] ?? 'daily'))
            ?? RecurringFrequency::Daily;
        if (! in_array($frequency, [RecurringFrequency::Hourly, RecurringFrequency::Daily], true)) {
            throw ValidationException::withMessages(['frequency' => 'UNSUPPORTED_FREQUENCY']);
        }

        $timezone = (string) ($input['timezone'] ?? 'Europe/Istanbul');
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['timezone' => 'INVALID_TIMEZONE']);
        }

        return DB::transaction(function () use ($asset, $brand, $input, $actor, $frequency, $timezone): CollectionSchedule {
            return CollectionSchedule::query()->create([
                'customer_id' => (int) $brand->customer_id,
                'brand_id' => (int) $brand->id,
                'digital_asset_id' => (int) $asset->id,
                'frequency' => $frequency,
                'interval' => max(1, (int) ($input['interval'] ?? 1)),
                'timezone' => $timezone,
                'local_time' => (string) ($input['local_time'] ?? '06:00'),
                'misfire_policy' => RecurringMisfirePolicy::CatchUpBounded,
                'status' => CollectionScheduleStatus::Active,
                'created_by' => $actor?->id,
            ]);
        });
    }

    public function pause(CollectionSchedule $schedule): void
    {
        $schedule->status = CollectionScheduleStatus::Paused;
        $schedule->save();
    }

    public function resume(CollectionSchedule $schedule): void
    {
        $schedule->status = CollectionScheduleStatus::Active;
        $schedule->save();
    }

    public function archive(CollectionSchedule $schedule): void
    {
        $schedule->status = CollectionScheduleStatus::Archived;
        $schedule->save();
    }

    /**
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     */
    private function assertAuthorized(Brand $brand, array $authorizedCustomerIds, array $authorizedBrandIds): void
    {
        if ($authorizedBrandIds !== [] && ! in_array((int) $brand->id, array_map('intval', $authorizedBrandIds), true)) {
            throw ValidationException::withMessages(['brand' => 'UNAUTHORIZED_BRAND']);
        }
        if ($authorizedCustomerIds !== [] && ! in_array((int) $brand->customer_id, array_map('intval', $authorizedCustomerIds), true)) {
            throw ValidationException::withMessages(['customer' => 'UNAUTHORIZED_CUSTOMER']);
        }
    }
}
