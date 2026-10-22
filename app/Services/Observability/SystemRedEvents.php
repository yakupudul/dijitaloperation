<?php

namespace App\Services\Observability;

use App\Enums\Observability\OperationalAlertRuleType;
use App\Enums\Observability\OperationalAlertSeverity;
use App\Enums\Observability\OperationalSignalFamily;
use App\Models\AiTask;
use App\Services\AiTasks\AiTaskPriority;
use App\Services\Operations\AutoDeployStatus;
use App\Support\SchemaState;
use Throwable;

/**
 * Panel audit D6 / T4 (2026-10-21): red system events that had no alert at all. Each one is an operational alert for
 * the operator ("Senin işin" on Bugün and Sistem) and, being critical, a phone / browser push once per opening:
 *  - the AI queue (Claude over MCP) has a task waiting longer than AI_QUEUE_HOURS;
 *  - the automatic deploy stopped (tests failed or the deploy itself failed).
 */
final class SystemRedEvents
{
    public const string AI_QUEUE_RULE = 'ai_queue_old';

    public const string DEPLOY_RULE = 'auto_deploy_stopped';

    public const int AI_QUEUE_HOURS = 12;

    public function evaluate(OperationalAlertLifecycleService $lifecycle): int
    {
        return $this->aiQueue($lifecycle) + $this->deploy($lifecycle);
    }

    private function aiQueue(OperationalAlertLifecycleService $lifecycle): int
    {
        if (! SchemaState::hasTable('ai_tasks')) {
            return 0;
        }
        $limit = now()->subHours(self::AI_QUEUE_HOURS);
        $old = AiTask::query()->where('status', AiTask::PENDING)->where('created_at', '<', $limit);
        $count = (clone $old)->count();
        if ($count === 0) {
            $lifecycle->resolveIfActive(self::AI_QUEUE_RULE, 'QUEUE', 'ai-queue');

            return 0;
        }
        $oldest = (clone $old)->oldest('created_at')->first();
        $hours = (int) floor(($oldest?->created_at?->diffInMinutes(now()) ?? 0) / 60);
        $urgent = (clone $old)->whereIn('operation', AiTaskPriority::URGENT)->count();
        $lifecycle->observeCondition(
            ruleKey: self::AI_QUEUE_RULE,
            ruleVersion: 1,
            ruleType: OperationalAlertRuleType::QueueBacklog,
            family: OperationalSignalFamily::AiProvider,
            severity: OperationalAlertSeverity::Critical,
            scopeType: 'QUEUE',
            scopeKey: 'ai-queue',
            title: 'AI kuyruğu birikti: en eski iş '.$hours.' saattir bekliyor',
            summary: sprintf('%d iş %d saatten uzun süredir Claude\'u bekliyor%s. Yazı takvimi ve Onarım masası bu kontrollere bağlı. Claude\'un kuyruk rutininin çalıştığını kontrol et (AI kuyruğu rutinleri).',
                $count, self::AI_QUEUE_HOURS, $urgent > 0 ? ' ('.$urgent.' tanesi yazı / onarım kontrolü)' : ''),
            observed: ['waiting' => $count, 'oldest_hours' => $hours, 'urgent' => $urgent, 'oldest_operation' => $oldest?->operation],
        );

        return 1;
    }

    private function deploy(OperationalAlertLifecycleService $lifecycle): int
    {
        try {
            $status = AutoDeployStatus::current();
        } catch (Throwable) {
            return 0;
        }
        if ($status === null) {
            return 0;
        }
        if (! in_array($status['state'], ['tests_failed', 'deploy_failed'], true)) {
            $lifecycle->resolveIfActive(self::DEPLOY_RULE, 'DEPLOY', 'auto-deploy');

            return 0;
        }
        $lifecycle->observeCondition(
            ruleKey: self::DEPLOY_RULE,
            ruleVersion: 1,
            ruleType: OperationalAlertRuleType::SchedulerLag,
            family: OperationalSignalFamily::Scheduler,
            severity: OperationalAlertSeverity::Critical,
            scopeType: 'DEPLOY',
            scopeKey: 'auto-deploy',
            title: 'Otomatik deploy durdu: '.$status['label'],
            summary: trim(($status['sha'] !== '' ? $status['sha'].' canlıya alınmadı. ' : '').mb_substr($status['message'], 0, 300).' Ayrıntı: Ayarlar › Sürümler.'),
            observed: ['state' => $status['state'], 'sha' => $status['sha'], 'branch' => $status['branch']],
        );

        return 1;
    }
}
