<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\ClaudeNote;
use App\Models\DigitalAsset;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Gbp\GbpCategoryCatalog;
use Illuminate\Console\Command;
use Throwable;

/**
 * moxdop:gbp:coverage — how many categories and services each Business Profile of a sector carries on Google right
 * now (read live, read-only), so profiles with few or none stand out (yakup, 2026-10-13: "diş sağlığı sektöründe olan
 * ve hizmet kategorileri ve hizmetleri tam girilmemiş veya hiç girilmemiş işletmeleri listele"). Printed and kept as a
 * system Claude note (read over MCP).
 */
final class GbpCoverageCommand extends Command
{
    protected $signature = 'moxdop:gbp:coverage {--like-brand= : Brand id whose sector is listed}';

    protected $description = 'List each Business Profile of a sector with its live category and service counts (read-only).';

    public function handle(GbpDesk $desk, GbpCategoryCatalog $catalog): int
    {
        $sectorId = Brand::query()->whereKey((int) $this->option('like-brand'))->value('sector_id');
        if ($sectorId === null) {
            $this->error('Markanın sektörü bulunamadı.');

            return self::FAILURE;
        }
        $lines = [];
        foreach ($desk->locations()->filter(fn (DigitalAsset $l): bool => (int) $l->brand?->sector_id === (int) $sectorId) as $location) {
            $name = (string) $location->brand?->name.' · '.GbpDesk::shortName((string) $location->name).' (#'.$location->id.')';
            try {
                [$integration, $locationName] = $catalog->location((int) $location->id);
                $current = $catalog->current($integration, $locationName);
                $primary = (string) data_get($current['categories'], 'primaryCategory.displayName', '');
                $additional = count((array) data_get($current['categories'], 'additionalCategories', []));
                $lines[] = $name.': birincil '.($primary !== '' ? $primary : 'YOK').', '.$additional.' ek kategori, '.count($current['serviceItems']).' hizmet';
            } catch (Throwable $exception) {
                $lines[] = $name.': okunamadı ('.mb_substr($exception->getMessage(), 0, 120).')';
            }
        }
        $text = 'İşletme Profili kategori ve hizmet sayıları ('.now()->format('Y-m-d H:i').'):'."\n".implode("\n", $lines);
        foreach (explode("\n", $text) as $line) {
            $this->line($line);
        }
        ClaudeNote::query()->create(['brand_id' => null, 'kind' => 'observation', 'text' => $text, 'status' => ClaudeNote::OPEN]);

        return self::SUCCESS;
    }
}
