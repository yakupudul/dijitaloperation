<?php

namespace App\Console\Commands;

use App\Jobs\Site\RefreshCompetitorsJob;
use App\Models\DigitalAsset;
use App\Services\Site\Backlinks\BacklinkVerifier;
use App\Services\Site\Competitors\CompetitorRefresher;
use App\Services\Site\Competitors\CompetitorTargets;
use App\Services\Site\Health\SiteExpiryChecker;
use Illuminate\Console\Command;

/**
 * Faz 4b site screen jobs: competitors (monthly), backlink re-check (weekly), SSL / domain expiry (daily).
 *
 *   moxdop:site competitors [--site=] [--sync]
 *   moxdop:site backlinks
 *   moxdop:site expiry [--site=] [--force]
 */
final class SiteScreenCommand extends Command
{
    protected $signature = 'moxdop:site {task : competitors | backlinks | expiry} {--site= : Website asset id} {--sync : Run now instead of queueing} {--force : Ignore the daily expiry cache}';

    protected $description = 'Site ekranı: rakipler (aylık), backlink doğrulama (haftalık), SSL / alan adı bitişi (günlük).';

    public function handle(CompetitorRefresher $refresher, CompetitorTargets $targets, BacklinkVerifier $verifier, SiteExpiryChecker $expiry): int
    {
        return match ((string) $this->argument('task')) {
            'competitors' => $this->competitors($refresher, $targets),
            'backlinks' => $this->backlinks($verifier),
            'expiry' => $this->expiry($expiry),
            default => $this->unknown(),
        };
    }

    private function competitors(CompetitorRefresher $refresher, CompetitorTargets $targets): int
    {
        $count = 0;
        foreach ($this->sites() as $site) {
            if ($site->brand === null || $targets->clusters($site->brand)->isEmpty()) {
                continue;
            }
            if ($this->option('sync')) {
                $result = $refresher->refresh($site);
                $this->line(sprintf('#%d %s: %s · %d sorgu · %d sayfa · %d hata', $site->id, $site->name, $result['status'], $result['serps'], $result['pages'], $result['errors']));
            } else {
                RefreshCompetitorsJob::dispatch((int) $site->id);
            }
            $count++;
        }
        $this->info($count.' site '.($this->option('sync') ? 'güncellendi.' : 'kuyruğa alındı.'));

        return self::SUCCESS;
    }

    private function backlinks(BacklinkVerifier $verifier): int
    {
        $stats = $verifier->verifyAll();
        $this->info(sprintf('%d kaynak kontrol edildi · %d doğrulandı · %d kaldırıldı', $stats['checked'], $stats['found'], $stats['removed']));

        return self::SUCCESS;
    }

    private function expiry(SiteExpiryChecker $checker): int
    {
        $checked = 0;
        foreach ($this->sites() as $site) {
            $checked += $checker->check($site, (bool) $this->option('force'))['checked'] ? 1 : 0;
        }
        $this->info($checked.' site kontrol edildi.');

        return self::SUCCESS;
    }

    /** @return iterable<DigitalAsset> */
    private function sites(): iterable
    {
        $query = DigitalAsset::query()->operational()->with('brand.customer')->where('type', 'website');
        if (filled($this->option('site'))) {
            $query->whereKey((int) $this->option('site'));
        }

        return $query->lazyById(100, 'digital_assets.id', 'id');
    }

    private function unknown(): int
    {
        $this->error('Görev: competitors | backlinks | expiry');

        return self::INVALID;
    }
}
