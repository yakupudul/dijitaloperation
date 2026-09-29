<?php

namespace App\Services\Outcomes\Readers;

use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\Meta\MetaScreen;
use App\Services\Outcomes\OutcomeMetricReader;

/** Meta: spend, results, cost per result and CTR of the suggestion's ad account. */
final class MetaOutcomeReader implements OutcomeMetricReader
{
    public function __construct(private readonly MetaScreen $screen) {}

    public function scope(Suggestion $suggestion): ?array
    {
        return $suggestion->target_id !== null ? ['asset_id' => (int) $suggestion->target_id] : null;
    }

    public function lastDay(array $scope): ?string
    {
        $account = $this->account($scope);
        $last = $account !== null ? $this->screen->q($account, 'meta_ad_daily')?->max('reporting_date') : null;

        return $last !== null ? substr((string) $last, 0, 10) : null;
    }

    public function read(array $scope, string $from, string $to): ?array
    {
        $account = $this->account($scope);
        $rows = $account !== null ? $this->screen->adPerformance($account, $from, $to) : [];
        if ($rows === []) {
            return null;
        }
        $totals = MetaScreen::totals($rows);

        return ['spend' => $totals['spend'], 'results' => $totals['results'], 'cpr' => $totals['cpr'], 'ctr' => $totals['ctr']];
    }

    /** @return array<string, mixed>|null */
    private function account(array $scope): ?array
    {
        $asset = DigitalAsset::query()->find($scope['asset_id'] ?? 0);

        return $asset !== null ? $this->screen->account($asset) : null;
    }
}
