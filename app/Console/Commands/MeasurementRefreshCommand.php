<?php

namespace App\Console\Commands;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Services\Measurement\BrandConversionDictionary;
use Illuminate\Console\Command;
use Throwable;

/**
 * moxdop:measurement:refresh — daily conversion dictionary discovery for active customers' brands
 * (stored data only: no provider calls, no AI).
 */
final class MeasurementRefreshCommand extends Command
{
    protected $signature = 'moxdop:measurement:refresh {--brand= : Only this brand id}';

    protected $description = 'Discover each brand\'s conversion signals (GA4, Google Ads, Meta, Business Profile) from stored data.';

    public function handle(BrandConversionDictionary $dictionary): int
    {
        $brands = Brand::query()
            ->whereHas('customer', fn ($query) => $query->where('status', CustomerStatus::Active->value))
            ->when($this->option('brand'), fn ($query, $id) => $query->whereKey((int) $id))
            ->orderBy('id')
            ->get();
        foreach ($brands as $brand) {
            try {
                $stats = $dictionary->discover($brand);
                $this->line(sprintf('%s: %d dönüşüm sinyali, %d yeni.', $brand->name, $stats['found'], $stats['created']));
            } catch (Throwable $exception) {
                report($exception);
                $this->error($brand->name.': '.$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
