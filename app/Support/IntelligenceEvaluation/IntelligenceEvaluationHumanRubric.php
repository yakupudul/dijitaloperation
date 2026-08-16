<?php

namespace App\Support\IntelligenceEvaluation;

use App\Enums\IntelligenceEvaluationAssertionType;
use App\Enums\IntelligenceEvaluationHumanRubricOutcome;

/**
 * Versioned human usefulness rubric (Prompt 55).
 *
 * Categorical PASS / NEEDS_REVIEW / FAIL only — no magic numeric score.
 */
final class IntelligenceEvaluationHumanRubric
{
    public const string VERSION = IntelligenceEvaluationPolicy::HUMAN_RUBRIC_VERSION;

    /**
     * @return list<string>
     */
    public static function dimensions(): array
    {
        return [
            'grounding',
            'context_specificity',
            'decision_usefulness',
            'actionability',
            'prioritization_clarity',
            'limitation_honesty',
            'non_genericity',
        ];
    }

    /**
     * @return array{
     *     version: string,
     *     dimensions: list<string>,
     *     outcomes: list<string>,
     *     numeric_score: null,
     *     may_override_privacy: false
     * }
     */
    public static function snapshot(): array
    {
        return [
            'version' => self::VERSION,
            'dimensions' => self::dimensions(),
            'outcomes' => array_map(
                static fn (IntelligenceEvaluationHumanRubricOutcome $o) => $o->value,
                IntelligenceEvaluationHumanRubricOutcome::cases()
            ),
            'numeric_score' => null,
            'may_override_privacy' => false,
        ];
    }

    /**
     * @param  array<string, string>  $dimensionOutcomes  dimension => outcome value
     * @return array{ok: bool, errors: list<string>}
     */
    public static function validateOutcomes(array $dimensionOutcomes): array
    {
        $errors = [];
        $allowed = array_map(
            static fn (IntelligenceEvaluationHumanRubricOutcome $o) => $o->value,
            IntelligenceEvaluationHumanRubricOutcome::cases()
        );

        foreach (self::dimensions() as $dimension) {
            if (! array_key_exists($dimension, $dimensionOutcomes)) {
                $errors[] = 'MISSING_DIMENSION:'.$dimension;

                continue;
            }
            if (! in_array($dimensionOutcomes[$dimension], $allowed, true)) {
                $errors[] = 'INVALID_OUTCOME:'.$dimension;
            }
        }

        foreach (array_keys($dimensionOutcomes) as $key) {
            if (! in_array($key, self::dimensions(), true)) {
                $errors[] = 'UNKNOWN_DIMENSION:'.$key;
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * Bounded assertion types that are zero-tolerance and never overridable by humans.
     *
     * @return list<IntelligenceEvaluationAssertionType>
     */
    public static function nonOverridableSafetyAssertions(): array
    {
        return array_values(array_filter(
            IntelligenceEvaluationAssertionType::cases(),
            static fn (IntelligenceEvaluationAssertionType $t) => $t->isZeroToleranceSafety()
        ));
    }
}
