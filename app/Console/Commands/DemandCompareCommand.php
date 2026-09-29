<?php

namespace App\Console\Commands;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Services\Demand\CompetitorPageComparator;
use App\Support\Console\ConsoleScope;
use App\Support\Console\ConsoleScopeException;
use Illuminate\Console\Command;
use Throwable;

/**
 * moxdop:demand:compare — weekly our-page-vs-competitors comparison for brands with area SERP checks
 * (public page fetch + rules; no paid calls, no AI). Feeds SEO Görevleri competitor-gap tasks.
 */
final class DemandCompareCommand extends Command
{
    protected $signature = 'moxdop:demand:compare {--brand= : Marka id veya adının bir parçası (ör. Panorama)}';

    protected $description = 'Compare each service page with the pages that outrank it in the area SERP checks.';

    public function handle(CompetitorPageComparator $comparator): int
    {
        try {
            $brandId = $this->option('brand') !== null ? ConsoleScope::brand((string) $this->option('brand'))->id : null;
        } catch (ConsoleScopeException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
        $brands = Brand::query()->where('demand_serp_enabled', true)
            ->whereHas('customer', fn ($query) => $query->where('status', CustomerStatus::Active->value))
            ->when($brandId, fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')->get();
        foreach ($brands as $brand) {
            try {
                $s = $comparator->run($brand);
                $this->line(sprintf('%s: %d hizmet, %d karşılaştırma, %d eksik, %d sayfa çekildi.', $brand->name, $s['services'], $s['compared'], $s['gaps'], $s['fetched']));
            } catch (Throwable $exception) {
                report($exception);
                $this->error($brand->name.': '.$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
