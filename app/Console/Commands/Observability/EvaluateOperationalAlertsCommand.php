<?php

namespace App\Console\Commands\Observability;

use App\Services\Observability\OperationalAlertEvaluator;
use App\Services\Observability\OperationalHealthSnapshot;
use App\Services\Observability\WorkerHeartbeatService;
use Illuminate\Console\Command;

final class EvaluateOperationalAlertsCommand extends Command
{
    protected $signature = 'moxdop:ops:evaluate-alerts {--snapshot : Also print health snapshot JSON}';

    protected $description = 'Evaluate versioned operational alert rules (Prompt 66)';

    public function handle(
        OperationalAlertEvaluator $evaluator,
        WorkerHeartbeatService $workers,
        OperationalHealthSnapshot $snapshot,
    ): int {
        $workers->beatDispatcher('recurring', ['source' => 'evaluate-alerts']);
        $result = $evaluator->evaluate();
        $this->info('opened='.$result['opened'].' resolved='.$result['resolved']);

        if ($this->option('snapshot')) {
            $data = $snapshot->snapshot();
            unset($data['overall_score']); // still null; emphasize dimensions
            $this->line(json_encode([
                'dimensions' => array_map(
                    static fn (array $d): array => ['status' => $d['status'], 'message' => $d['message']],
                    $data['dimensions'],
                ),
                'open_alert_count' => $data['open_alert_count'],
            ], JSON_PRETTY_PRINT));
        }

        return self::SUCCESS;
    }
}
