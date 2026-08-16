<?php

namespace App\Services\IntelligenceEvaluation;

use App\Models\IntelligenceEvaluationBaseline;
use App\Models\IntelligenceEvaluationRun;

/**
 * Per-dimension regression comparison — no aggregate magic score.
 */
final class IntelligenceEvaluationRegressionComparer
{
    /**
     * @return array{
     *     baseline_key: string,
     *     regressions: list<array{dimension: string, baseline_fail: int, new_fail: int, safety: bool}>,
     *     single_ai_score: null,
     *     automatic_action: null
     * }
     */
    public function compare(IntelligenceEvaluationBaseline $baseline, IntelligenceEvaluationRun $newRun): array
    {
        $baselineCounts = $baseline->dimension_snapshot['counts'] ?? [];
        $newCounts = $newRun->dimension_summary['counts'] ?? [];
        $regressions = [];

        $dimensions = array_unique(array_merge(array_keys($baselineCounts), array_keys($newCounts)));
        foreach ($dimensions as $dimension) {
            $baseFail = (int) ($baselineCounts[$dimension]['fail'] ?? 0);
            $newFail = (int) ($newCounts[$dimension]['fail'] ?? 0);
            if ($newFail > $baseFail) {
                $regressions[] = [
                    'dimension' => $dimension,
                    'baseline_fail' => $baseFail,
                    'new_fail' => $newFail,
                    'safety' => $dimension === 'safety',
                ];
            }
        }

        return [
            'baseline_key' => $baseline->baseline_key,
            'regressions' => $regressions,
            'single_ai_score' => null,
            'automatic_action' => null,
        ];
    }
}
