<?php

namespace App\Console\Commands;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Services\Compliance\ComplianceAuditor;
use App\Services\Compliance\SectorPackRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * moxdop:compliance:scan — daily sector-pack compliance audit for active customers' brands (stored data only).
 */
final class ComplianceScanCommand extends Command
{
    protected $signature = 'moxdop:compliance:scan {--brand= : Only this brand id}';

    protected $description = 'Check AI drafts, live Meta ads, website pages and Business Profile content against sector pack rules.';

    public function handle(ComplianceAuditor $auditor, SectorPackRegistry $packs): int
    {
        $packs->syncDefaults();
        $brands = Brand::query()
            ->whereHas('customer', fn ($query) => $query->where('status', CustomerStatus::Active->value))
            ->when($this->option('brand'), fn ($query, $id) => $query->whereKey((int) $id))
            ->orderBy('id')->get();
        foreach ($brands as $brand) {
            if ($packs->forBrand($brand) === []) {
                continue;
            }
            try {
                $stats = $auditor->scan($brand);
                $this->line(sprintf('%s: %d içerik, %d açık bulgu (%d yeni, %d çözüldü).', $brand->name, $stats['subjects'], $stats['open'], $stats['new'], $stats['resolved']));
            } catch (Throwable $exception) {
                report($exception);
                $this->error($brand->name.': '.$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
