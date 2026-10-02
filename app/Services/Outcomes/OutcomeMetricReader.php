<?php

namespace App\Services\Outcomes;

use App\Models\Suggestion;

/**
 * One channel's numbers for the outcome follow-up (Faz 9). A channel plugs in by adding one reader to
 * OutcomeTracker::READERS (and its metric rule to OutcomeTracker::RULES); the baseline, the 28 / 56-day measurement
 * and the verdict are shared.
 */
interface OutcomeMetricReader
{
    /**
     * What the suggestion is measured on (URL, ad account, campaign, profile), JSON-safe. Null = the suggestion
     * references nothing measurable ("veri yok").
     *
     * @return array<string, mixed>|null
     */
    public function scope(Suggestion $suggestion): ?array;

    /**
     * Last collected day (Y-m-d) of the scope's source, null when nothing is collected.
     *
     * @param  array<string, mixed>  $scope
     */
    public function lastDay(array $scope): ?string;

    /**
     * Sums of the window (both days inclusive). Null = no collected row in the window.
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, int|float|null>|null
     */
    public function read(array $scope, string $from, string $to): ?array;
}
