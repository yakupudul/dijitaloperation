<?php

namespace App\Services\Advisor\MetaAds;

use App\Models\AdvisorPlan;
use App\Models\DigitalAsset;
use App\Services\Advisor\AdvisorChannel;

/**
 * Meta Ads advisor channel (Faz 4). Rules are being added; until then a run reports that it has no data.
 */
final class MetaAdsAdvisorChannel implements AdvisorChannel
{
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
        return route('operator.assets');
    }

    public function draftRules(): array
    {
        return [];
    }

    public function dispatchDraft(int $itemId): void {}

    public function collect(DigitalAsset $asset): array
    {
        return ['asset' => ['id' => $asset->id], 'bound' => false, 'binding_reason' => 'rules_not_ready'];
    }

    public function evaluate(array $input): array
    {
        return ['items' => [], 'silenced' => ['not_bound'], 'summary' => ['reason' => 'not_bound']];
    }

    public function sources(array $input): array
    {
        return [];
    }
}
