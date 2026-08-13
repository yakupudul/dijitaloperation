<?php

namespace App\Services\Integrations;

use App\Services\Integrations\Google\GoogleIntegrationReadModel;
use App\Support\Demo\GlobalOperatingFixtures;
use App\Support\Integrations\ProviderRegistry;

/**
 * Frozen `/app/integrations` hub projection.
 *
 * Google card is backed by canonical CoreIntegration state.
 * Other provider cards remain Demo fixtures until their convergence milestones.
 */
final class OperatorIntegrationsHubQuery
{
    public function __construct(
        private readonly GoogleIntegrationReadModel $google = new GoogleIntegrationReadModel,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function groups(): array
    {
        $groups = GlobalOperatingFixtures::integrationsHub();

        foreach ($groups as &$group) {
            if (($group['group'] ?? '') !== 'Platforms & Data') {
                continue;
            }

            $providers = [];
            foreach ($group['providers'] as $provider) {
                if (($provider['id'] ?? '') === ProviderRegistry::GOOGLE) {
                    $providers[] = $this->google->hubCard();

                    continue;
                }

                $provider['provenance'] = $provider['provenance'] ?? 'demo';
                $providers[] = $provider;
            }
            $group['providers'] = $providers;
        }
        unset($group);

        return $groups;
    }
}
