<?php

namespace App\Console\Commands;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Services\Demand\AreaSerpChecker;
use Illuminate\Console\Command;
use Throwable;

/**
 * moxdop:demand:serp — weekly area SERP checks for brands that opted in (paid, capped per brand per month,
 * results reused for 28 days). Passive customers are skipped.
 */
final class DemandSerpCommand extends Command
{
    protected $signature = 'moxdop:demand:serp {--brand= : Only this brand id}';

    protected $description = 'Check the brand services\' most valuable queries in Google at the brand\'s service areas (opt-in, capped).';

    public function handle(AreaSerpChecker $checker): int
    {
        $brands = Brand::query()->where('demand_serp_enabled', true)
            ->whereHas('customer', fn ($query) => $query->where('status', CustomerStatus::Active->value))
            ->when($this->option('brand'), fn ($query, $id) => $query->whereKey((int) $id))
            ->orderBy('id')->get();
        foreach ($brands as $brand) {
            try {
                $s = $checker->run($brand);
                $this->line(sprintf('%s: %d plan, %d yeni, %d yeniden kullanıldı, %d bütçe nedeniyle atlandı, %d hata, %.4f USD, %d rakip adayı.',
                    $brand->name, $s['planned'], $s['checked'], $s['reused'], $s['skipped_budget'], $s['failed'], $s['spent_usd'], $s['competitors']));
            } catch (Throwable $exception) {
                report($exception);
                $this->error($brand->name.': '.$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
