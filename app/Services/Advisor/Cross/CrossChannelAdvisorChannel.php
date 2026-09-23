<?php

namespace App\Services\Advisor\Cross;

use App\Models\AdvisorPlan;
use App\Models\DigitalAsset;
use App\Services\Advisor\AdvisorChannel;

/**
 * Cross-channel suggestions live on the brand's website asset (it is where the resulting work lands).
 */
final class CrossChannelAdvisorChannel implements AdvisorChannel
{
    public function __construct(
        private readonly CrossChannelInputCollector $collector,
        private readonly CrossChannelRuleEngine $rules,
    ) {}

    public function channel(): string
    {
        return AdvisorPlan::CHANNEL_CROSS;
    }

    public function assetType(): string
    {
        return 'website';
    }

    public function bindingCapability(): string
    {
        return 'search_console';
    }

    public function label(): string
    {
        return 'Kanallar arası';
    }

    public function assetUrl(int $assetId): string
    {
        return route('operator.ads_advisor', ['adv_channel' => AdvisorPlan::CHANNEL_CROSS, 'adv_asset' => $assetId]);
    }

    public function draftRules(): array
    {
        return [];
    }

    public function dispatchDraft(int $itemId): void {}

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
            'ads_terms' => count($input['ads_terms'] ?? []),
            'gbp_keywords' => count($input['gbp_keywords'] ?? []),
            'gsc_queries' => count($input['gsc_queries'] ?? []),
            'pages' => count($input['pages'] ?? []),
        ];
    }
}
