<?php

namespace App\Services\Advisor\GoogleAds;

use App\Jobs\DraftGoogleAdsAdCopyJob;
use App\Models\AdvisorPlan;
use App\Models\DigitalAsset;
use App\Services\Advisor\AdvisorChannel;

final class GoogleAdsAdvisorChannel implements AdvisorChannel
{
    public function __construct(
        private readonly GoogleAdsAdvisorInputCollector $collector,
        private readonly GoogleAdsAdvisorRuleEngine $rules,
    ) {}

    public function channel(): string
    {
        return AdvisorPlan::CHANNEL_GOOGLE_ADS;
    }

    public function assetType(): string
    {
        return 'google_ads';
    }

    public function bindingCapability(): string
    {
        return 'google_ads';
    }

    public function label(): string
    {
        return 'Google Ads';
    }

    public function assetUrl(int $assetId): string
    {
        return route('operator.google-ads.overview', ['assetId' => $assetId, 'tab' => 'advisor']);
    }

    public function draftRules(): array
    {
        return ['weak-ad-strength'];
    }

    public function dispatchDraft(int $itemId): void
    {
        dispatch(new DraftGoogleAdsAdCopyJob($itemId))
            ->onConnection((string) config('moxdop-advisor.queue_connection', config('queue.default')))
            ->onQueue((string) config('moxdop-advisor.queue', 'default'));
    }

    public function collect(DigitalAsset $asset): array
    {
        return $this->collector->collect($asset);
    }

    public function evaluate(array $input): array
    {
        return $this->rules->evaluate($input);
    }

    public function sources(array $input): array
    {
        return [
            'campaigns' => count($input['campaigns']),
            'search_terms' => count($input['search_terms']),
            'negatives' => count($input['negatives']),
            'keywords' => count($input['keywords']),
            'quality_score' => count(array_filter($input['keywords'], static fn (array $k): bool => $k['quality_score'] !== null)),
            'ads' => count($input['ads']['items']),
            'landing_pages' => count($input['landing_pages']),
            'conversion_actions' => count($input['conversion_actions']['items']),
            'asset_library' => array_sum($input['asset_library']['counts']),
            'recommendations' => count($input['recommendations']['items']),
            'changes' => count($input['changes']['items']),
            'website_pages' => count($input['website']['pages']),
            'ga4' => $input['ga4']['available'],
        ];
    }
}
