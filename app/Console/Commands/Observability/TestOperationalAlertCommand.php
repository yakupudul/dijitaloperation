<?php

namespace App\Console\Commands\Observability;

use App\Enums\Observability\OperationalAlertRuleType;
use App\Enums\Observability\OperationalAlertSeverity;
use App\Enums\Observability\OperationalSignalFamily;
use App\Services\Observability\OperationalAlertLifecycleService;
use App\Services\Observability\OperationalAlertNotifier;
use Illuminate\Console\Command;

/**
 * Safe test alert delivery — does not affect provider metrics.
 */
final class TestOperationalAlertCommand extends Command
{
    protected $signature = 'moxdop:ops:test-alert {--title=TEST operational alert}';

    protected $description = 'Open a clearly marked TEST operational alert (Prompt 66)';

    public function handle(
        OperationalAlertLifecycleService $lifecycle,
        OperationalAlertNotifier $notifier,
    ): int {
        $alert = $lifecycle->observeCondition(
            ruleKey: 'test_operational_alert',
            ruleVersion: 1,
            ruleType: OperationalAlertRuleType::QueueBacklog,
            family: OperationalSignalFamily::Queue,
            severity: OperationalAlertSeverity::Info,
            scopeType: 'SYSTEM',
            scopeKey: 'test:alert',
            title: '[TEST] '.(string) $this->option('title'),
            summary: 'TEST alert — does not indicate real provider/queue failure.',
            observed: ['test' => true],
        );

        $recipients = $notifier->recipientIds();
        $this->info('alert_id='.$alert->id.' recipients='.count($recipients).' notified='.($alert->notification_emitted ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
