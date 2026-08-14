<?php

namespace App\Services\Findings;

use App\Support\Evidence\Dto\CanonicalEvidenceDto;
use App\Support\Findings\FindingRule;

/**
 * Typed condition evaluation. No eval(), no stored PHP/SQL expressions.
 */
final class FindingConditionEvaluator
{
    /**
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @return array{result: bool|null, operands: array<string, mixed>}
     */
    public function activation(FindingRule $rule, array $evidence): array
    {
        return $this->evaluateGroup($rule->activationCombiner, $rule->activationConditions, $evidence);
    }

    /**
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @return array{result: bool|null, operands: array<string, mixed>}
     */
    public function clear(FindingRule $rule, array $evidence): array
    {
        if ($rule->clearConditions === []) {
            return ['result' => null, 'operands' => []];
        }

        return $this->evaluateGroup($rule->clearCombiner, $rule->clearConditions, $evidence);
    }

    /**
     * @param  list<array<string, mixed>>  $conditions
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @return array{result: bool|null, operands: array<string, mixed>}
     */
    private function evaluateGroup(string $combiner, array $conditions, array $evidence): array
    {
        $operands = $this->snapshotOperands($conditions, $evidence);
        $results = [];
        foreach ($conditions as $index => $condition) {
            $results[] = $this->evaluateOne($condition, $evidence);
        }

        if (in_array(null, $results, true)) {
            return ['result' => null, 'operands' => $operands];
        }

        $bools = array_map(static fn (mixed $value): bool => (bool) $value, $results);
        $combined = strtoupper($combiner) === 'ANY'
            ? in_array(true, $bools, true)
            : ! in_array(false, $bools, true);

        return ['result' => $combined, 'operands' => $operands];
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  list<CanonicalEvidenceDto>  $evidence
     */
    private function evaluateOne(array $condition, array $evidence): ?bool
    {
        $type = (string) ($condition['type'] ?? '');
        $negate = (bool) ($condition['negate'] ?? false);
        $payload = $this->mergedPayload($evidence);

        $result = match ($type) {
            'VALUE_LT' => $this->compareNumeric($payload, $condition, static fn (float $left, float $right): bool => $left < $right),
            'VALUE_LTE' => $this->compareNumeric($payload, $condition, static fn (float $left, float $right): bool => $left <= $right),
            'VALUE_GT' => $this->compareNumeric($payload, $condition, static fn (float $left, float $right): bool => $left > $right),
            'VALUE_GTE' => $this->compareNumeric($payload, $condition, static fn (float $left, float $right): bool => $left >= $right),
            'VALUE_EQUALS' => $this->compareNumeric($payload, $condition, static fn (float $left, float $right): bool => $left === $right),
            'VALUE_BETWEEN' => $this->between($payload, $condition, false),
            'VALUE_OUTSIDE' => $this->between($payload, $condition, true),
            'STATE_EQUALS' => $this->stateEquals($payload, $condition, true),
            'STATE_NOT_EQUALS' => $this->stateEquals($payload, $condition, false),
            'BOOLEAN_IS' => $this->booleanIs($payload, $condition),
            'ABSENCE_CONFIRMED' => $this->presence($payload, $condition, false),
            'PRESENCE_CONFIRMED' => $this->presence($payload, $condition, true),
            'ABS_DECREASE_GTE' => $this->absDelta($payload, $condition, true),
            'ABS_INCREASE_GTE' => $this->absDelta($payload, $condition, false),
            default => null,
        };

        if ($result === null) {
            return null;
        }

        return $negate ? ! $result : $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $condition
     * @param  callable(float, float): bool  $compare
     */
    private function compareNumeric(array $payload, array $condition, callable $compare): ?bool
    {
        $left = $this->numeric(data_get($payload, (string) ($condition['path'] ?? '')));
        $right = $this->numeric($condition['value'] ?? null);
        if ($left === null || $right === null) {
            return null;
        }

        return $compare($left, $right);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $condition
     */
    private function between(array $payload, array $condition, bool $outside): ?bool
    {
        $value = $this->numeric(data_get($payload, (string) ($condition['path'] ?? '')));
        $min = $this->numeric($condition['min'] ?? null);
        $max = $this->numeric($condition['max'] ?? null);
        if ($value === null || $min === null || $max === null) {
            return null;
        }

        $inside = $value >= $min && $value <= $max;

        return $outside ? ! $inside : $inside;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $condition
     */
    private function stateEquals(array $payload, array $condition, bool $equals): ?bool
    {
        $actual = data_get($payload, (string) ($condition['path'] ?? ''));
        if ($actual === null) {
            return null;
        }

        $matched = (string) $actual === (string) ($condition['value'] ?? '');

        return $equals ? $matched : ! $matched;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $condition
     */
    private function booleanIs(array $payload, array $condition): ?bool
    {
        $actual = data_get($payload, (string) ($condition['path'] ?? ''));
        if (! is_bool($actual)) {
            return null;
        }

        return $actual === (bool) ($condition['value'] ?? true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $condition
     */
    private function presence(array $payload, array $condition, bool $present): ?bool
    {
        $actual = data_get($payload, (string) ($condition['path'] ?? ''));
        if ($actual === null) {
            return $present ? false : true;
        }
        if (is_bool($actual)) {
            return $present ? $actual : ! $actual;
        }
        if (is_string($actual)) {
            $filled = trim($actual) !== '';

            return $present ? $filled : ! $filled;
        }

        return $present;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $condition
     */
    private function absDelta(array $payload, array $condition, bool $decrease): ?bool
    {
        $current = $this->numeric(data_get($payload, (string) ($condition['current_path'] ?? '')));
        $previous = $this->numeric(data_get($payload, (string) ($condition['previous_path'] ?? '')));
        $threshold = $this->numeric($condition['value'] ?? null);
        if ($current === null || $previous === null || $threshold === null) {
            return null;
        }

        $delta = $decrease ? ($previous - $current) : ($current - $previous);

        return $delta >= $threshold;
    }

    /**
     * @param  list<array<string, mixed>>  $conditions
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @return array<string, mixed>
     */
    public function snapshotOperands(array $conditions, array $evidence): array
    {
        $payload = $this->mergedPayload($evidence);
        $operands = [];
        foreach ($conditions as $condition) {
            foreach (['path', 'current_path', 'previous_path'] as $key) {
                $path = (string) ($condition[$key] ?? '');
                if ($path === '') {
                    continue;
                }
                $operands[$path] = data_get($payload, $path);
            }
        }

        return $operands;
    }

    /**
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @return array<string, mixed>
     */
    private function mergedPayload(array $evidence): array
    {
        $merged = [];
        foreach ($evidence as $row) {
            $merged = array_replace_recursive($merged, $row->payload);
        }

        return $merged;
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
