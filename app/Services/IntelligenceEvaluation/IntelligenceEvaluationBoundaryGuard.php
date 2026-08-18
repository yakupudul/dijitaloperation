<?php

namespace App\Services\IntelligenceEvaluation;

use App\Support\IntelligenceEvaluation\IntelligenceEvaluationPolicy;
use Illuminate\Support\Facades\File;

/**
 * Hard boundary guard — evaluation never auto-tunes or exports training data.
 */
final class IntelligenceEvaluationBoundaryGuard
{
    public function assertNoAutoTuningSideEffects(): void
    {
        $policy = IntelligenceEvaluationPolicy::snapshot();
        if ($policy['auto_tuning'] || $policy['auto_skill_edit'] || $policy['auto_agent_edit']
            || $policy['auto_retrieval_edit'] || $policy['auto_route_switch']
            || $policy['auto_model_promotion'] || $policy['fine_tuning']
            || $policy['training_export']) {
            throw new \RuntimeException('Evaluation policy violated auto-tuning / training boundaries.');
        }

        if (class_exists('App\\Services\\IntelligenceEvaluation\\IntelligenceEvaluationV2')) {
            throw new \RuntimeException('IntelligenceEvaluationV2 is forbidden.');
        }
    }

    public function assertNoTrainingExportApi(): void
    {
        $dir = app_path('Services/IntelligenceEvaluation');
        foreach (File::files($dir) as $file) {
            if ($file->getFilename() === 'IntelligenceEvaluationBoundaryGuard.php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $stripped = preg_replace('#/\*.*?\*/#s', '', $code) ?? $code;
            $stripped = preg_replace('#//.*$#m', '', $stripped) ?? $stripped;
            if (preg_match('/->fineTunes?\s*\(|::fineTunes?\s*\(|createEmbedding\s*\(|->exportTraining\s*\(|jsonl_training_export|pgvector_/i', $stripped)) {
                throw new \RuntimeException('Forbidden training/embedding API in evaluation services.');
            }
        }
    }
}
