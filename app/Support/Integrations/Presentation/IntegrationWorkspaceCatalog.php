<?php

namespace App\Support\Integrations\Presentation;

use App\Filament\App\Resources\Integrations\IntegrationResource;
use App\Models\CoreIntegration;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Collection;

/**
 * Builds the Integrations hub from presentation metadata + persisted rows.
 * Index rendering must never call external providers.
 */
final class IntegrationWorkspaceCatalog
{
    public function __construct(
        private readonly IntegrationHealthPresenter $health = new IntegrationHealthPresenter,
    ) {}

    /**
     * @return array{
     *     summary: array{total: int, connected: int, needs_attention: int, configured: int, not_configured: int},
     *     groups: list<array{key: string, label: string, cards: list<array<string, mixed>>}>
     * }
     */
    public function hub(): array
    {
        $cards = $this->cards();
        $counts = [
            'total' => $cards->count(),
            'connected' => 0,
            'needs_attention' => 0,
            'configured' => 0,
            'not_configured' => 0,
        ];

        foreach ($cards as $card) {
            match ($card->status) {
                IntegrationOperatorStatus::CONNECTED => $counts['connected']++,
                IntegrationOperatorStatus::NEEDS_ATTENTION => $counts['needs_attention']++,
                IntegrationOperatorStatus::CONFIGURED => $counts['configured']++,
                default => $counts['not_configured']++,
            };
        }

        $groups = [];
        foreach (IntegrationPresentationRegistry::groupOrder() as $groupKey) {
            $groupCards = $cards->filter(fn (IntegrationCardViewModel $card): bool => $card->group === $groupKey);
            if ($groupCards->isEmpty()) {
                continue;
            }

            $first = $groupCards->first();
            $groups[] = [
                'key' => $groupKey,
                'label' => $first?->groupLabel ?? $groupKey,
                'cards' => $groupCards->map(fn (IntegrationCardViewModel $card): array => $card->toArray())->values()->all(),
            ];
        }

        return [
            'summary' => $counts,
            'groups' => $groups,
        ];
    }

    /**
     * @return Collection<int, IntegrationCardViewModel>
     */
    public function cards(): Collection
    {
        /** @var Collection<string, CoreIntegration> $byProvider */
        $byProvider = CoreIntegration::query()
            ->with(['providerCredential', 'credential'])
            ->get()
            ->keyBy('provider');

        return collect(IntegrationPresentationRegistry::operatorReady())
            ->map(function (array $meta) use ($byProvider): IntegrationCardViewModel {
                $provider = $meta['provider'];
                $integration = $byProvider->get($provider);
                $hasRecord = $integration instanceof CoreIntegration;

                return new IntegrationCardViewModel(
                    provider: $provider,
                    label: $meta['label'],
                    description: $meta['description'],
                    group: $meta['group'],
                    groupLabel: $meta['group_label'],
                    icon: $meta['icon'],
                    status: $this->health->status($integration, $provider),
                    summaryLines: $this->health->summaryLines($integration, $provider),
                    lastCheckedLabel: $this->health->lastCheckedLabel($integration, $provider),
                    action: $hasRecord ? 'manage' : 'setup',
                    manageUrl: $hasRecord
                        ? IntegrationResource::getUrl('view', ['record' => $integration])
                        : null,
                    integrationId: $hasRecord ? $integration->id : null,
                    supportsResources: (bool) $meta['supports_resources'],
                );
            })
            ->values();
    }

    /**
     * Bootstrap the canonical Integration row for an operator-ready provider.
     */
    public function bootstrap(string $provider): CoreIntegration
    {
        if (! IntegrationPresentationRegistry::isOperatorReady($provider) || ! ProviderRegistry::isValid($provider)) {
            throw new \InvalidArgumentException('This integration is not available.');
        }

        return CoreIntegration::query()->firstOrCreate(
            ['provider' => $provider],
            [
                'name' => ProviderRegistry::defaultName($provider),
                'status' => CoreIntegration::STATUS_ACTIVE,
                'config' => [],
            ],
        );
    }
}
