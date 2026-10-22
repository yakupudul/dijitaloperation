<?php

namespace App\Console\Commands\Goals;

use App\Models\Brand;
use App\Models\BrandTarget;
use App\Services\Goals\BrandTargets;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/** Marka amaçları (Aşama 5): each operational brand's months are read again once a day and its goal proposed. */
final class RefreshBrandTargetsCommand extends Command
{
    protected $signature = 'moxdop:targets:refresh {--brand= : Only this brand id} {--all : Also brands read in the last 20 hours}';

    protected $description = 'Read each brand\'s monthly demand and propose its "ayda N talep" goal';

    public function handle(BrandTargets $targets): int
    {
        $done = 0;
        $fresh = $this->option('all') || $this->option('brand') ? [] : BrandTarget::query()->get(['brand_id', 'basis'])
            ->filter(fn (BrandTarget $t): bool => ($at = data_get($t->basis, 'read_at')) !== null && CarbonImmutable::parse((string) $at)->gt(now()->subHours(20)))
            ->pluck('brand_id')->all();
        Brand::query()->whereNotIn('id', $fresh)->operational()->when($this->option('brand'), fn ($q, $id) => $q->whereKey((int) $id))->orderBy('id')
            ->each(function (Brand $brand) use ($targets, &$done): void {
                try {
                    $targets->refresh($brand);
                    $done++;
                } catch (Throwable $error) {
                    report($error);
                    $this->warn($brand->name.': '.$error->getMessage());
                }
            });
        $this->info($done.' marka okundu.');

        return self::SUCCESS;
    }
}
