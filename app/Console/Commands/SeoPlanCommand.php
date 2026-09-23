<?php

namespace App\Console\Commands;

use App\Models\DigitalAsset;
use App\Services\SeoTasks\SeoPlanRunner;
use Illuminate\Console\Command;

/**
 * moxdop:seo:plan — queue SEO plan runs.
 *   --asset=ID   one website
 *   --all        every active website
 *   --scheduled  weekly scheduler: only websites with an active Search Console binding
 *   --sync       run inline instead of queueing (debug / UAT)
 */
final class SeoPlanCommand extends Command
{
    protected $signature = 'moxdop:seo:plan {--asset=} {--all} {--scheduled} {--sync}';

    protected $description = 'Queue SEO task plan runs for one, all, or all Search-Console-connected websites.';

    public function handle(SeoPlanRunner $runner): int
    {
        if (! (bool) config('moxdop-seo-tasks.enabled', true)) {
            $this->warn('SEO Görevleri devre dışı (SEO_TASKS_ENABLED=false).');

            return self::SUCCESS;
        }

        $assetId = $this->option('asset');
        if (is_string($assetId) && $assetId !== '') {
            $site = DigitalAsset::query()->findOrFail((int) $assetId);
            $plan = $runner->queue($site, null, 'manual');
            $this->info(sprintf('Plan #%d kuyruğa alındı (site #%d, durum: %s).', $plan->id, $site->id, $plan->status));
            if ($this->option('sync')) {
                $plan = $runner->run($plan->id);
                $this->info('Tamamlandı: '.($plan->summary_text ?? $plan->status));
            }

            return self::SUCCESS;
        }

        if ($this->option('scheduled')) {
            if (! (bool) config('moxdop-seo-tasks.schedule.enabled', true)) {
                $this->line('Zamanlayıcı kapalı (SEO_TASKS_SCHEDULE_ENABLED=false).');

                return self::SUCCESS;
            }
            $plans = $runner->queueAll(null, onlyConnected: true, trigger: 'scheduled', limit: (int) config('moxdop-seo-tasks.schedule.sites_per_tick', 25));
            $this->info(sprintf('%d site için haftalık plan kuyruğa alındı.', $plans->count()));

            return self::SUCCESS;
        }

        if ($this->option('all')) {
            $plans = $runner->queueAll(null, onlyConnected: false, trigger: 'bulk');
            $this->info(sprintf('%d site için plan kuyruğa alındı.', $plans->count()));
            if ($this->option('sync')) {
                foreach ($plans as $plan) {
                    $done = $runner->run($plan->id);
                    $this->line(sprintf('  site #%d → %s', $done->digital_asset_id, $done->summary_text ?? $done->status));
                }
            }

            return self::SUCCESS;
        }

        $this->error('--asset=ID, --all veya --scheduled verin.');

        return self::INVALID;
    }
}
