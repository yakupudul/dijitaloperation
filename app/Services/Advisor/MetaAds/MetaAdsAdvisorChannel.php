<?php

namespace App\Services\Advisor\MetaAds;

use App\Jobs\DraftMetaAdsCreativeJob;
use App\Models\AdvisorPlan;
use App\Models\DigitalAsset;
use App\Services\Advisor\AdvisorChannel;

final class MetaAdsAdvisorChannel implements AdvisorChannel
{
    public function __construct(
        private readonly MetaAdsAdvisorInputCollector $collector,
        private readonly MetaAdsAdvisorRuleEngine $rules,
    ) {}

    public function channel(): string
    {
        return AdvisorPlan::CHANNEL_META_ADS;
    }

    public function assetType(): string
    {
        return 'meta_ads';
    }

    public function bindingCapability(): string
    {
        return 'meta_ads';
    }

    public function label(): string
    {
        return 'Meta Ads';
    }

    public function assetUrl(int $assetId): string
    {
        return route('operator.meta.overview', ['assetId' => $assetId, 'tab' => 'advisor']);
    }

    public function draftRules(): array
    {
        return ['creative-fatigue'];
    }

    public function dispatchDraft(int $itemId): void
    {
        dispatch(new DraftMetaAdsCreativeJob($itemId))
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
            'adsets' => count($input['adsets']),
            'ads' => count($input['ads']),
            'creatives' => count($input['creatives']),
            'result_level' => $input['actions_level'] !== 'none',
            'conversion_sources' => count($input['conversion_sources']['items']),
            'breakdowns' => count($input['breakdowns']),
            'hourly' => count($input['hourly']),
            'changes' => count($input['changes']['items']),
            'website_pages' => count($input['website']['pages']),
        ];
    }
}
