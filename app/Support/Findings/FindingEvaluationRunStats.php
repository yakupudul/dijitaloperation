<?php

namespace App\Support\Findings;

final class FindingEvaluationRunStats
{
    /**
     * @param  array<string, int>  $blockReasons
     */
    public function __construct(
        public int $rulesConsidered = 0,
        public int $rulesEligible = 0,
        public int $rulesBlocked = 0,
        public int $conditionsTrue = 0,
        public int $conditionsFalse = 0,
        public int $findingsCreated = 0,
        public int $findingsReused = 0,
        public int $findingsReopened = 0,
        public int $findingsResolved = 0,
        public int $evaluationsReused = 0,
        public int $errors = 0,
        public array $blockReasons = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rules_considered' => $this->rulesConsidered,
            'rules_eligible' => $this->rulesEligible,
            'rules_blocked' => $this->rulesBlocked,
            'conditions_true' => $this->conditionsTrue,
            'conditions_false' => $this->conditionsFalse,
            'findings_created' => $this->findingsCreated,
            'findings_reused' => $this->findingsReused,
            'findings_reopened' => $this->findingsReopened,
            'findings_resolved' => $this->findingsResolved,
            'evaluations_reused' => $this->evaluationsReused,
            'errors' => $this->errors,
            'block_reasons' => $this->blockReasons,
        ];
    }

    public function recordBlock(string $reason): void
    {
        $this->rulesBlocked++;
        $this->blockReasons[$reason] = ($this->blockReasons[$reason] ?? 0) + 1;
    }
}
