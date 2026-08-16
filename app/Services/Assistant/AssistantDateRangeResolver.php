<?php

namespace App\Services\Assistant;

use App\Support\Assistant\Dto\AssistantDateRange;
use Carbon\CarbonImmutable;

/**
 * Deterministic conversational date resolution (Prompt 56).
 * Model does not calculate database timestamps.
 */
final class AssistantDateRangeResolver
{
    /**
     * @var list<string>
     */
    public const array SUPPORTED_TOKENS = [
        'today',
        'yesterday',
        'last_7_days',
        'last_30_days',
        'this_month',
        'last_month',
    ];

    public function resolve(string $token, ?string $timezone = null, ?CarbonImmutable $now = null): AssistantDateRange
    {
        $tz = $timezone ?: 'UTC';
        $now = ($now ?? CarbonImmutable::now($tz))->timezone($tz);
        $normalized = strtolower(trim($token));

        [$start, $end] = match ($normalized) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            'last_7_days' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            'last_30_days' => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
            'this_month' => [$now->startOfMonth()->startOfDay(), $now->endOfDay()],
            'last_month' => [
                $now->subMonthNoOverflow()->startOfMonth()->startOfDay(),
                $now->subMonthNoOverflow()->endOfMonth()->endOfDay(),
            ],
            default => throw new \InvalidArgumentException('Unsupported date period token: '.$token),
        };

        return new AssistantDateRange(
            token: $normalized,
            startDate: $start->toDateString(),
            endDate: $end->toDateString(),
            timezone: $tz,
            inclusiveEnd: true,
        );
    }

    public function supports(string $token): bool
    {
        return in_array(strtolower(trim($token)), self::SUPPORTED_TOKENS, true);
    }
}
