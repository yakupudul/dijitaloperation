<?php

namespace App\Services\Advisor\Gbp;

use App\Jobs\DraftGbpProfileJob;
use App\Models\AdvisorPlan;
use App\Models\DigitalAsset;
use App\Services\Advisor\AdvisorChannel;

final class GbpAdvisorChannel implements AdvisorChannel
{
    public function __construct(
        private readonly GbpAdvisorInputCollector $collector,
        private readonly GbpAdvisorRuleEngine $rules,
    ) {}

    public function channel(): string
    {
        return AdvisorPlan::CHANNEL_GBP;
    }

    public function assetType(): string
    {
        return 'google_business_profile';
    }

    public function bindingCapability(): string
    {
        return 'google_business_profile';
    }

    public function label(): string
    {
        return 'İşletme Profili';
    }

    public function assetUrl(int $assetId): string
    {
        return route('operator.gbp', ['assetId' => $assetId, 'tab' => 'advisor']);
    }

    public function draftRules(): array
    {
        return ['profile-gaps'];
    }

    public function dispatchDraft(int $itemId): void
    {
        dispatch(new DraftGbpProfileJob($itemId))
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
            'location' => ($input['location'] ?? null) !== null,
            'services' => count($input['services']['labels'] ?? []),
            'attributes' => count($input['attributes']['set'] ?? []),
            'performance' => (bool) ($input['performance']['available'] ?? false),
            'keywords' => count($input['keywords']['items'] ?? []),
            'reviews' => (int) ($input['reviews']['total'] ?? 0),
            'photos' => (int) ($input['media']['photos'] ?? 0),
            'website_pages' => count($input['website']['pages'] ?? []),
        ];
    }
}
