<?php

namespace App\Console\Commands;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Services\Demand\CompetitorPageComparator;
use Illuminate\Console\Command;
use Throwable;

/**
 * moxdop:demand:compare — weekly our-page-vs-competitors comparison for brands with area SERP checks
 * (public page fetch + rules; no paid calls, no AI). Feeds SEO Görevleri competitor-gap tasks.
 */
final class DemandCompareCommand extends Command
{
    protected $signature = 'moxdop:demand:compare {--brand= : Only this brand id}';

    protected $description = 'Compare each service page with the pages that outrank it in the area SERP checks.';

    public function handle(CompetitorPageComparator $comparator): int
    {
        $brands = Brand::query()->where('demand_serp_enabled', true)
            ->whereHas('customer', fn ($query) => $query->where('status', CustomerStatus::Active->value))
            ->when($this->option('brand'), fn ($query, $id) => $query->whereKey((int) $id))
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
