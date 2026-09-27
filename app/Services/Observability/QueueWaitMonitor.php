<?php

namespace App\Services\Observability;

use App\Enums\Observability\OperationalAlertRuleType;
use App\Enums\Observability\OperationalAlertSeverity;
use App\Enums\Observability\OperationalSignalFamily;
use Laravel\Horizon\WaitTimeCalculator;
use Throwable;

/**
 * Horizon queue wait ("time to clear" per redis queue, from Horizon's own job-runtime metrics and queue sizes)
 * against per-queue thresholds (moxdop-observability.queue.wait_alert_seconds). Only meaningful when the default
 * queue driver is redis; otherwise it reports nothing and changes nothing.
 */
final class QueueWaitMonitor
{
    public const string RULE_KEY = 'queue_wait_high';

    public function enabled(): bool
    {
        return config('queue.connections.'.config('queue.default').'.driver') === 'redis';
    }

    /** @return array<string, int> queue name => alert threshold in seconds */
    public function thresholds(): array
    {
        return array_map('intval', array_filter(
            (array) config('moxdop-observability.queue.wait_alert_seconds', []),
            fn ($seconds): bool => is_numeric($seconds) && (int) $seconds > 0,
        ));
    }

    /**
     * Current waits per queue, largest first. Null when the driver is not redis or Horizon/Redis cannot be read.
     *
     * @return list<array{queue: string, connection: string, wait_seconds: int, threshold_seconds: ?int, over: bool}>|null
     */
    public function waits(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $raw = app(WaitTimeCalculator::class)->calculate();
        } catch (Throwable) {
            return null;
        }

        $thresholds = $this->thresholds();
        $rows = [];
        foreach ($raw as $key => $seconds) {
            [$connection, $queue] = str_contains((string) $key, ':') ? explode(':', (string) $key, 2) : ['redis', (string) $key];
            $wait = (int) round((float) $seconds);
            $threshold = $thresholds[$queue] ?? null;
            $rows[] = [
                'queue' => $queue,
                'connection' => $connection,
                'wait_seconds' => $wait,
                'threshold_seconds' => $threshold,
                'over' => $threshold !== null && $wait > $threshold,
            ];
        }
        usort($rows, fn (array $a, array $b): int => $b['wait_seconds'] <=> $a['wait_seconds']);

        return $rows;
    }

    /**
     * Opens / refreshes one alert per queue over its threshold and resolves the others. No-op (0) when the driver
     * is not redis or the waits cannot be read, so a Redis outage never auto-resolves an open wait alert.
     */
    public function evaluate(OperationalAlertLifecycleService $lifecycle): int
    {
        $waits = $this->waits();
        if ($waits === null) {
            return 0;
        }

        $opened = 0;
        $byQueue = collect($waits)->keyBy('queue');
        foreach ($this->thresholds() as $queue => $threshold) {
            $row = $byQueue->get($queue);
            $scope = 'queue-wait:'.$queue;

            if (is_array($row) && $row['over']) {
                $lifecycle->observeCondition(
                    ruleKey: self::RULE_KEY,
                    ruleVersion: 1,
                    ruleType: OperationalAlertRuleType::QueueBacklog,
                    family: OperationalSignalFamily::Queue,
                    severity: OperationalAlertSeverity::Warning,
                    scopeType: 'QUEUE',
                    scopeKey: $scope,
                    title: 'Kuyruk bekleme süresi yüksek: '.$queue,
                    summary: sprintf('"%s" kuyruğundaki işlerin bitmesi ~%d dakika sürecek (eşik %d dakika). İşçi sayısını ve Arka plan işleri sayfasını kontrol edin.',
                        $queue, (int) ceil($row['wait_seconds'] / 60), (int) ceil($threshold / 60)),
                    observed: [
                        'queue' => $queue,
                        'connection' => $row['connection'],
                        'wait_seconds' => $row['wait_seconds'],
                        'threshold_seconds' => $threshold,
                    ],
                );
                $opened++;

                continue;
            }

            $lifecycle->resolveIfActive(self::RULE_KEY, 'QUEUE', $scope);
        }

        return $opened;
    }
}
