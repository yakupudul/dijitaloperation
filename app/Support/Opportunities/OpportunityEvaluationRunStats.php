<?php

namespace App\Support\Opportunities;

final class OpportunityEvaluationRunStats
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
        public int $opportunitiesCreated = 0,
        public int $opportunitiesReused = 0,
        public int $opportunitiesReopened = 0,
        public int $opportunitiesClosed = 0,
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
            'opportunities_created' => $this->opportunitiesCreated,
            'opportunities_reused' => $this->opportunitiesReused,
            'opportunities_reopened' => $this->opportunitiesReopened,
            'opportunities_closed' => $this->opportunitiesClosed,
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
