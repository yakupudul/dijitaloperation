<?php

namespace App\Console\Commands;

use App\Services\GoogleAds\GoogleAdsChanges;
use App\Services\Repair\SiteAudit;
use App\Services\Repair\WebHealthAudit;
use App\Services\Site\SiteHealthScore;
use Illuminate\Console\Command;

/**
 * moxdop:repair:audit — nightly SEO title / description check of every indexable WordPress page (no AI); affected
 * pages get a suggestion that is prepared overnight and approved on the Onarım masası. Also reads every active Google
 * Ads account's settings and prepares the setting changes of ADR-081 (read-only; writes wait for approval). Then the
 * technical site check (WebHealthAudit: Search Console index / sitemaps, broken links, speed, headers, index bloat,
 * GA4 conversion path, AI answer engines) and the site score of Aşama 7 (SiteHealthScore).
 */
final class RepairAuditCommand extends Command
{
    protected $signature = 'moxdop:repair:audit';

    protected $description = 'Check every page for missing, short, long or duplicate SEO titles and descriptions.';

    public function handle(SiteAudit $audit, GoogleAdsChanges $ads, WebHealthAudit $health, SiteHealthScore $scores): int
    {
        $done = $audit->run();
        $this->line(sprintf('%d site denetlendi: %d sayfa için düzeltme açıldı, %d düzeltme sorun kalmadığı için kapandı.', $done['sites'], $done['opened'], $done['closed']));
        $web = $health->run();
        $this->line(sprintf('Teknik site denetimi: %d site, %d yeni ya da yeniden açılan iş, %d iş sorun kalmadığı için kapandı.', $web['sites'], $web['opened'], $web['closed']));
        // Aşama 7: each site's 0-100 score in four layers, after the night's findings are up to date.
        $scored = $scores->storeAll();
        $this->line(sprintf('Site skoru: %d site puanlandı.', $scored['sites']));
        $changes = $ads->auditAll();
        $this->line(sprintf('%d Google Ads hesabı denetlendi: %d hazır değişiklik%s.', $changes['accounts'], $changes['prepared'], $changes['failed'] > 0 ? ', '.$changes['failed'].' hesap okunamadı' : ''));

        return self::SUCCESS;
    }
}
