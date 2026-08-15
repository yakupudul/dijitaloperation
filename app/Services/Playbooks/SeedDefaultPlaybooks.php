<?php

namespace App\Services\Playbooks;

use App\Enums\PlaybookApplicabilityMode;
use App\Models\Playbook;
use App\Models\ServiceDefinition;
use App\Models\User;
use App\Support\Playbooks\DefaultPlaybookCatalog;
use Illuminate\Support\Facades\Route;

/**
 * Idempotent default Playbook seed. Never overwrites operator-edited revisions.
 */
final class SeedDefaultPlaybooks
{
    public function __construct(
        private readonly PlaybookService $playbooks,
    ) {}

    /**
     * @return array{created: int, skipped: int, keys: list<string>}
     */
    public function seed(?User $actor = null): array
    {
        $created = 0;
        $skipped = 0;
        $keys = [];

        foreach (DefaultPlaybookCatalog::definitions() as $definition) {
            $key = (string) $definition['stable_key'];
            $keys[] = $key;

            $existing = Playbook::query()->where('stable_key', $key)->first();
            if ($existing instanceof Playbook) {
                $skipped++;

                continue;
            }

            $serviceIds = ServiceDefinition::query()
                ->whereIn('code', $definition['service_codes'] ?? [])
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $references = [];
            foreach ($definition['references'] ?? [] as $ref) {
                if (($ref['kind'] ?? '') === 'internal_route') {
                    $routeName = (string) ($ref['route_name'] ?? '');
                    if ($routeName === '' || ! Route::has($routeName)) {
                        continue;
                    }
                    $references[] = $ref;
                } elseif (($ref['kind'] ?? '') === 'external_url' && ! empty($ref['url'])) {
                    $references[] = $ref;
                }
            }

            $this->playbooks->create([
                'stable_key' => $key,
                'title' => $definition['title'],
                'summary' => $definition['summary'] ?? null,
                'knowledge' => $definition['knowledge'] ?? null,
                'cadence' => $definition['cadence'] ?? null,
                'service_applicability_mode' => PlaybookApplicabilityMode::Explicit->value,
                'asset_applicability_mode' => PlaybookApplicabilityMode::Explicit->value,
                'execution_scope_mode' => PlaybookApplicabilityMode::Explicit->value,
                'service_definition_ids' => $serviceIds,
                'asset_types' => $definition['asset_types'] ?? [],
                'execution_scopes' => $definition['execution_scopes'] ?? ['digital_asset'],
                'instructions' => $definition['instructions'] ?? [],
                'references' => $references,
            ], $actor, 'seed:playbook:'.$key, systemBootstrap: true);

            $created++;
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'keys' => $keys,
        ];
    }
}
