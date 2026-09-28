<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Services\Demand\BrandDemandBuilder;
use App\Support\ServiceScope;
use Illuminate\Console\Command;
use Throwable;

/**
 * moxdop:demand:build — weekly brand query hub for operational brands (ServiceScope): stored data only, no provider
 * calls, no AI, no paid calls.
 */
final class DemandBuildCommand extends Command
{
    protected $signature = 'moxdop:demand:build {--brand= : Only this brand id}';

    protected $description = 'Rebuild the brand query hub (queries → service, sector, branded, intent, relevance, value) from stored data.';

    public function handle(BrandDemandBuilder $builder, ServiceScope $scope): int
    {
        $brands = Brand::query()
            ->whereIn('id', $scope->operationalBrandIds())
            ->when($this->option('brand'), fn ($query, $id) => $query->whereKey((int) $id))
            ->orderBy('id')
            ->get();
        foreach ($brands as $brand) {
            try {
                $stats = $builder->build($brand);
                $this->line(sprintf('%s: %d sorgu, %d hizmete atandı, %d markalı, %d belirsiz, %d alakasız.',
                    $brand->name, $stats['queries'], $stats['assigned'], $stats['branded'], $stats['unclear'], $stats['irrelevant']));
            } catch (Throwable $exception) {
                report($exception);
                $this->error($brand->name.': '.$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
