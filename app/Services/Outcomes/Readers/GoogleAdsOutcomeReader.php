<?php

namespace App\Services\Outcomes\Readers;

use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\GoogleAds\GoogleAdsScreen;
use App\Services\Outcomes\OutcomeMetricReader;
use Illuminate\Database\Query\Builder;

/**
 * Google Ads: cost, conversions, clicks and cost per conversion from `google_ads_campaign_daily` — of the campaign the
 * suggestion references (`action.campaign_id`, else `action.campaign` name), else of the whole account. A referenced
 * campaign that is not in the collected data → no scope ("veri yok").
 */
final class GoogleAdsOutcomeReader implements OutcomeMetricReader
{
    public function __construct(private readonly GoogleAdsScreen $screen) {}

    public function scope(Suggestion $suggestion): ?array
    {
        $asset = $suggestion->target_id !== null ? DigitalAsset::query()->find($suggestion->target_id) : null;
        $ctx = $asset !== null ? $this->screen->context($asset) : null;
        if ($ctx === null) {
            return null;
        }
        $action = (array) $suggestion->action;
        $id = trim((string) ($action['campaign_id'] ?? ''));
        $name = trim((string) ($action['campaign'] ?? ''));
        if ($id === '' && $name !== '') {
            foreach ($this->screen->names($ctx['scope'])['campaigns'] as $campaignId => $campaign) {
                if (mb_strtolower($campaign['name']) === mb_strtolower($name)) {
                    $id = (string) $campaignId;
                    break;
                }
            }
            if ($id === '') {
                return null;
            }
        }

        return ['asset_id' => (int) $asset->id, 'campaign_id' => $id !== '' ? $id : null, 'campaign' => $name !== '' ? $name : null];
    }

    public function lastDay(array $scope): ?string
    {
        $last = $this->rows($scope, '2000-01-01', '2999-12-31')?->max('reporting_date');

        return $last !== null ? substr((string) $last, 0, 10) : null;
    }

    public function read(array $scope, string $from, string $to): ?array
    {
        $row = $this->rows($scope, $from, $to)
            ?->selectRaw('COUNT(*) as n, SUM(cost_amount) as cost, SUM(conversions) as conversions, SUM(clicks) as clicks')->first();
        if ($row === null || (int) $row->n === 0) {
            return null;
        }
        $cost = round((float) $row->cost, 2);
        $conversions = (float) $row->conversions;

        return ['cost' => $cost, 'conversions' => $conversions, 'clicks' => (int) $row->clicks, 'cpa' => $conversions > 0 ? round($cost / $conversions, 2) : null];
    }

    /** @param  array<string, mixed>  $scope */
    private function rows(array $scope, string $from, string $to): ?Builder
    {
        $asset = DigitalAsset::query()->find($scope['asset_id'] ?? 0);
        $ctx = $asset !== null ? $this->screen->context($asset) : null;
        if ($ctx === null) {
            return null;
        }

        return $ctx['scope']->daily('google_ads_campaign_daily', $from, $to)
            ->when(filled($scope['campaign_id'] ?? null), fn ($q) => $q->where('campaign_id', (string) $scope['campaign_id']));
    }
}
