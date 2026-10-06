<?php

namespace App\Console\Commands\Production;

use App\Services\Assistant\PushNotifier;
use App\Services\Operations\AutoDeployStatus;
use Illuminate\Console\Command;

/**
 * deploy/staging/auto-deploy.sh calls this after a deploy or a stop, so the phone hears about it: a stop (tests failed,
 * deploy failed, branch not built on the live release) is 'high', a successful deploy 'info'.
 */
final class AutoDeployReportCommand extends Command
{
    protected $signature = 'moxdop:auto-deploy:report {state : deployed | tests_failed | deploy_failed | blocked} {--branch=} {--sha=} {--reason=}';

    protected $description = 'Otomatik deploy sonucunu telefona bildirir (deploy/staging/auto-deploy.sh çağırır).';

    public function handle(PushNotifier $notifier): int
    {
        $state = (string) $this->argument('state');
        if (! isset(AutoDeployStatus::LABELS[$state])) {
            $this->error('Bilinmeyen durum: '.$state);

            return self::FAILURE;
        }
        $sha = substr((string) $this->option('sha'), 0, 8);
        $problem = in_array($state, AutoDeployStatus::PROBLEMS, true);
        $title = $problem ? 'Otomatik deploy: '.AutoDeployStatus::LABELS[$state] : 'Otomatik deploy: '.$sha.' canlıda';
        $body = trim((string) $this->option('reason')).($this->option('branch') ? ' (dal: '.$this->option('branch').')' : '');
        $notifier->send('auto-deploy:'.$state.':'.$sha, $title, $body, $problem ? 'high' : 'info', route('operator.settings.improvements'), 24);
        $this->line($title);

        return self::SUCCESS;
    }
}
