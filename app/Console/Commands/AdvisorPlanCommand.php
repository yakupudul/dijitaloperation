<?php

namespace App\Console\Commands;

use App\Services\Advisor\AdvisorPlanRunner;
use App\Support\Console\ConsoleScope;
use App\Support\Console\ConsoleScopeException;
use Illuminate\Console\Command;

/**
 * moxdop:advisor:plan — queue channel advisor runs (Faz 3: Google Ads).
 *   --asset=ID   one Google Ads asset
 *   --all        every active Google Ads asset
 *   --scheduled  weekly scheduler: only connected accounts
 *   --sync       run inline instead of queueing (debug / UAT)
 */
final class AdvisorPlanCommand extends Command
{
    protected $signature = 'moxdop:advisor:plan {--asset=} {--all} {--scheduled} {--sync}';

    protected $description = 'Queue Google Ads advisor runs for one, all, or all connected ad accounts.';

    public function handle(AdvisorPlanRunner $runner): int
    {
        if (! (bool) config('moxdop-advisor.enabled', true)) {
            $this->warn('Danışman devre dışı (ADVISOR_ENABLED=false).');

            return self::SUCCESS;
        }
        $assetId = $this->option('asset');
        if (is_string($assetId) && $assetId !== '') {
            try {
                $asset = ConsoleScope::asset($assetId);
            } catch (ConsoleScopeException $exception) {
                $this->error($exception->getMessage());

                return self::INVALID;
            }
            $plan = $runner->queue($asset, null, 'manual');
            $this->info(sprintf('Danışman #%d kuyruğa alındı (varlık #%d).', $plan->id, $plan->digital_asset_id));
            if ($this->option('sync')) {
                $this->info('Tamamlandı: '.($runner->run($plan->id)->summary_text ?? ''));
            }

            return self::SUCCESS;
        }
        if ($this->option('scheduled') && ! (bool) config('moxdop-advisor.schedule.enabled', true)) {
            $this->line('Zamanlayıcı kapalı (ADVISOR_SCHEDULE_ENABLED=false).');

            return self::SUCCESS;
        }
        if ($this->option('scheduled') || $this->option('all')) {
            $plans = $runner->queueAll(null, onlyConnected: true, trigger: $this->option('scheduled') ? 'scheduled' : 'bulk');
            $this->info(sprintf('%d reklam hesabı için danışman kuyruğa alındı.', $plans->count()));
            if ($this->option('sync')) {
                foreach ($plans as $plan) {
                    $this->line(sprintf('  varlık #%d → %s', $plan->digital_asset_id, $runner->run($plan->id)->summary_text ?? ''));
                }
            }

            return self::SUCCESS;
        }
        $this->error('--asset=ID, --all veya --scheduled verin.');

        return self::INVALID;
    }
}
