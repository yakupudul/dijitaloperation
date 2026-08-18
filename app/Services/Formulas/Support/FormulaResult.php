<?php

namespace App\Services\Formulas\Support;

/**
 * Typed formula outcome. `value` is never a silent zero substitute for missing/undefined —
 * callers must check `state` before treating `value` as measured.
 */
final class FormulaResult
{
    public const string STATE_VALUE = 'VALUE';

    public const string STATE_UNDEFINED_ZERO_DENOMINATOR = 'UNDEFINED_ZERO_DENOMINATOR';

    public const string STATE_UNDEFINED = 'UNDEFINED';

    public const string STATE_NOT_COLLECTED = 'NOT_COLLECTED';

    public const string STATE_UNDEFINED_RELATIVE_CHANGE = 'UNDEFINED_RELATIVE_CHANGE';

    private function __construct(
        public readonly ?float $value,
        public readonly string $state,
    ) {}

    public static function value(float $value): self
    {
        return new self($value, self::STATE_VALUE);
    }

    public static function state(string $state): self
    {
        return new self(null, $state);
    }

    public function isValue(): bool
    {
        return $this->state === self::STATE_VALUE;
    }

    /**
     * Fraction (0–1) → percentage display value rounded per RP_PERCENT_DISPLAY (1 decimal).
     * Returns null when not a measured value — never 0 as a substitute.
     */
    public function toPercentDisplay(int $decimals = 1): ?float
    {
        if (! $this->isValue() || $this->value === null) {
            return null;
        }

        return round($this->value * 100, $decimals);
    }

    /**
     * RP_NO_INTERMEDIATE — no rounding besides final display precision.
     */
    public function toDisplay(?int $decimals = null): ?float
    {
        if (! $this->isValue() || $this->value === null) {
            return null;
        }

        return $decimals === null ? $this->value : round($this->value, $decimals);
    }
}
