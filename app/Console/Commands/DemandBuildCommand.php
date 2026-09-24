<?php

namespace App\Console\Commands;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Services\Demand\BrandDemandBuilder;
use Illuminate\Console\Command;
use Throwable;

/**
 * moxdop:demand:build — weekly brand demand table for active customers' brands (no provider calls, no AI).
 */
final class DemandBuildCommand extends Command
{
    protected $signature = 'moxdop:demand:build {--brand= : Only this brand id}';

    protected $description = 'Rebuild the brand demand table (queries → service, area, branded, value) from stored data.';

    public function handle(BrandDemandBuilder $builder): int
    {
        $brands = Brand::query()
            ->whereHas('customer', fn ($query) => $query->where('status', CustomerStatus::Active->value))
            ->when($this->option('brand'), fn ($query, $id) => $query->whereKey((int) $id))
            ->orderBy('id')
            ->get();
        foreach ($brands as $brand) {
            try {
                $stats = $builder->build($brand);
                $this->line(sprintf('%s: %d sorgu, %d hizmete atandı, %d markalı, %d bölge içi, %d bölge dışı.',
                    $brand->name, $stats['queries'], $stats['assigned'], $stats['branded'], $stats['in_area'], $stats['out_of_area']));
            } catch (Throwable $exception) {
                report($exception);
                $this->error($brand->name.': '.$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
