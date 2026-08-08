<?php

namespace App\Services\Findings;

use App\Contracts\Findings\EvaluatesBoundEvidence;
use LogicException;

final class BoundEvidenceRuleRegistry
{
    /** @var list<EvaluatesBoundEvidence> */
    private array $evaluators = [];

    public function register(EvaluatesBoundEvidence $evaluator): void
    {
        foreach ($this->evaluators as $existing) {
            if ($existing::class === $evaluator::class) {
                throw new LogicException('Bound evidence evaluator already registered: '.$evaluator::class);
            }
        }

        $this->evaluators[] = $evaluator;
    }

    /**
     * @return list<EvaluatesBoundEvidence>
     */
    public function all(): array
    {
        return $this->evaluators;
    }
}
