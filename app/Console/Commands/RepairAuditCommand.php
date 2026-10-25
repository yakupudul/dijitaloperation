<?php

namespace App\Console\Commands;

use App\Services\GoogleAds\GoogleAdsChanges;
use App\Services\Repair\SiteAudit;
use App\Services\Repair\WebHealthAudit;
use App\Services\Site\SiteHealthScore;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * moxdop:repair:audit — nightly SEO title / description check of every indexable WordPress page (no AI); affected
 * pages get a suggestion that is prepared overnight and approved on the Onarım masası. Also reads every active Google
 * Ads account's settings and prepares the setting changes of ADR-081 (read-only; writes wait for approval). Then the
 * technical site check (WebHealthAudit: Search Console index / sitemaps, broken links, speed, headers, index bloat,
 * GA4 conversion path, AI answer engines) and the site score of Aşama 7 (SiteHealthScore).
 *
 * Each step runs in its own PHP process (pool #50): the site check opens hundreds of HTTP connections, and on 23 and
 * 24 October one long process ran out of file handles ("Too many open files") and took the score and the Ads step
 * down with it. Now a step that dies is reported and the next one still runs.
 */
final class RepairAuditCommand extends Command
{
    public const array STEPS = ['titles', 'web-health', 'score', 'ads'];

    protected $signature = 'moxdop:repair:audit {--step= : Run one step in this process (titles | web-health | score | ads)}';

    protected $description = 'Check every page for missing, short, long or duplicate SEO titles and descriptions.';

    public function handle(): int
    {
        self::raiseFileLimit();
        $step = (string) $this->option('step');
        if ($step !== '') {
            if (! in_array($step, self::STEPS, true)) {
                $this->error('Bilinmeyen adım: '.$step);

                return self::INVALID;
            }

            return $this->runStep($step);
        }
        // Tests (and a run without the artisan binary) keep everything in one process.
        if (app()->runningUnitTests() || ! is_file(base_path('artisan'))) {
            $failed = 0;
            foreach (self::STEPS as $name) {
                try {
                    $this->runStep($name);
                } catch (Throwable $exception) {
                    report($exception);
                    $failed++;
                }
            }

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }
        $failed = [];
        foreach (self::STEPS as $name) {
            $process = new Process([PHP_BINARY, base_path('artisan'), 'moxdop:repair:audit', '--step='.$name], base_path(), null, null, 3600);
            $process->run(fn (string $type, string $out) => $this->output->write($out));
            if (! $process->isSuccessful()) {
                $failed[] = $name;
                report(new RuntimeException(sprintf('Gece site denetimi: "%s" adımı yarıda kaldı (çıkış kodu %s). %s', $name, (string) $process->getExitCode(), mb_substr(trim($process->getErrorOutput()), 0, 500))));
            }
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    private function runStep(string $step): int
    {
        switch ($step) {
            case 'titles':
                $done = app(SiteAudit::class)->run();
                $this->line(sprintf('%d site denetlendi: %d sayfa için düzeltme açıldı, %d düzeltme sorun kalmadığı için kapandı.', $done['sites'], $done['opened'], $done['closed']));
                break;
            case 'web-health':
                $web = app(WebHealthAudit::class)->run();
                $this->line(sprintf('Teknik site denetimi: %d site, %d yeni ya da yeniden açılan iş, %d iş sorun kalmadığı için kapandı.', $web['sites'], $web['opened'], $web['closed']));
                break;
            case 'score':
                // Aşama 7: each site's 0-100 score in four layers, after the night's findings are up to date.
                $scored = app(SiteHealthScore::class)->storeAll();
                $this->line(sprintf('Site skoru: %d site puanlandı.', $scored['sites']));
                break;
            case 'ads':
                $changes = app(GoogleAdsChanges::class)->auditAll();
                $this->line(sprintf('%d Google Ads hesabı denetlendi: %d hazır değişiklik%s.', $changes['accounts'], $changes['prepared'], $changes['failed'] > 0 ? ', '.$changes['failed'].' hesap okunamadı' : ''));
                break;
        }

        return self::SUCCESS;
    }

    /** The soft open-files limit up to the hard one (often 1024 soft / 4096+ hard for cron users). */
    private static function raiseFileLimit(): void
    {
        if (! function_exists('posix_getrlimit') || ! function_exists('posix_setrlimit') || ! defined('POSIX_RLIMIT_NOFILE')) {
            return;
        }
        $limits = posix_getrlimit();
        $hard = $limits['hard openfiles'] ?? null;
        $soft = $limits['soft openfiles'] ?? null;
        if (! is_numeric($soft)) {
            return;
        }
        $want = is_numeric($hard) ? min((int) $hard, 8192) : 8192;
        if ($want > (int) $soft) {
            @posix_setrlimit(POSIX_RLIMIT_NOFILE, $want, is_numeric($hard) ? (int) $hard : POSIX_RLIMIT_INFINITY);
        }
    }
}
