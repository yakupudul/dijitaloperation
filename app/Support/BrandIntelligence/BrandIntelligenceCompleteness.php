<?php

namespace App\Support\BrandIntelligence;

use App\Models\BrandIntelligenceContext;

/**
 * Completeness of factual context areas — not a quality/health score.
 */
final class BrandIntelligenceCompleteness
{
    public const int TOTAL_AREAS = 8;

    /**
     * @return array{completed: int, total: int, areas: array<string, bool>, label: string}
     */
    public static function for(?BrandIntelligenceContext $context): array
    {
        $areas = [
            'business' => self::hasText($context?->business_summary) || filled($context?->business_model),
            'offerings' => self::hasList($context?->products_services),
            'priority_offerings' => self::hasStringList($context?->priority_offerings),
            'audiences' => self::hasList($context?->target_audiences),
            'markets' => self::hasList($context?->target_markets),
            'goals' => self::hasList($context?->business_goals) || self::hasList($context?->conversion_goals),
            'competition' => self::hasList($context?->known_competitors),
            'positioning_constraints' => self::hasText($context?->positioning)
                || self::hasStringList($context?->differentiators)
                || self::hasText($context?->important_constraints),
        ];

        $completed = count(array_filter($areas));

        return [
            'completed' => $completed,
            'total' => self::TOTAL_AREAS,
            'areas' => $areas,
            'label' => $completed.' of '.self::TOTAL_AREAS.' key areas completed',
        ];
    }

    private static function hasText(?string $value): bool
    {
        return filled($value);
    }

    private static function hasList(mixed $value): bool
    {
        if (! is_array($value) || $value === []) {
            return false;
        }

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach ($row as $cell) {
                if (is_string($cell) && trim($cell) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    private static function hasStringList(mixed $value): bool
    {
        if (! is_array($value) || $value === []) {
            return false;
        }

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                return true;
            }
        }

        return false;
    }
}
