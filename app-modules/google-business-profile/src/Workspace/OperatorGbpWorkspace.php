<?php

namespace MoxDop\GoogleBusinessProfile\Workspace;

use App\Contracts\GbpOperatorWorkspace as GbpOperatorWorkspaceContract;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Support\Reality\UnavailableWorkspaceShells;
use MoxDop\GoogleBusinessProfile\Collection\GbpLocationBoundCollector;

/**
 * Truthful operator presenter for the GBP capabilities that are actually collected today.
 * Unsupported review/performance/local-rank surfaces remain explicitly unavailable.
 */
final class OperatorGbpWorkspace implements GbpOperatorWorkspaceContract
{
    /** @return array<string, mixed> */
    public function for(DigitalAsset $asset): array
    {
        $data = UnavailableWorkspaceShells::gbp((string) $asset->id);
        $binding = CoreAssetBinding::query()
            ->with('externalResource.integration')
            ->where('digital_asset_id', $asset->id)
            ->where('capability', GbpLocationBoundCollector::CAPABILITY)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();

        $evidence = null;
        $lastRun = null;
        if ($binding instanceof CoreAssetBinding) {
            $evidence = Evidence::query()
                ->where('digital_asset_id', $asset->id)
                ->where('source_module', GbpLocationBoundCollector::MODULE_ID)
                ->where('type', GbpLocationBoundCollector::EVIDENCE_TYPE)
                ->whereHas('run', fn ($query) => $query
                    ->where('status', 'completed')
                    ->where('core_asset_binding_id', $binding->id))
                ->latest('observed_at')
                ->latest('id')
                ->first();

            $lastRun = Run::query()
                ->where('digital_asset_id', $asset->id)
                ->where('module_id', GbpLocationBoundCollector::MODULE_ID)
                ->where('core_asset_binding_id', $binding->id)
                ->latest('id')
                ->first();
        }

        $payload = is_array($evidence?->payload) ? $evidence->payload : [];
        $ok = ($payload['ok'] ?? false) === true;
        $bound = $binding !== null && $binding->status === CoreAssetBinding::STATUS_ACTIVE;
        $resource = $binding?->externalResource;
        $address = $this->addressLine($payload['storefront_address'] ?? null);
        $title = $this->string($payload['title'] ?? null) ?: ($resource?->display_name ?: $asset->name);
        $website = $this->string($payload['website_uri'] ?? null);
        $phone = $this->string($payload['primary_phone'] ?? null);
        $category = $this->string($payload['primary_category'] ?? null);
        $lastRefresh = $evidence?->observed_at?->diffForHumans() ?? $lastRun?->finished_at?->diffForHumans();

        $data['migration_mode'] = $ok ? 'real' : ($bound ? 'configured' : 'unavailable');
        $data['identity'] = array_merge($data['identity'] ?? [], [
            'eyebrow' => 'Google Business Profile',
            'title' => $title ?: 'Google Business Profile',
            'brand' => $asset->brand?->name ?? '—',
            'brand_id' => $asset->brand_id,
            'brand_name' => $asset->brand?->name ?? '—',
            'status_key' => $ok ? 'connected' : ($bound ? 'needs_collection' : 'not_configured'),
            'location_line' => $address ?: '—',
            'last_refresh' => $lastRefresh,
        ]);

        $fields = [
            $this->field('business_name', $title),
            $this->field('primary_category', $category),
            $this->field('website', $website),
            $this->field('primary_phone', $phone),
            $this->field('address', $address),
        ];
        $present = count(array_filter($fields, fn (array $field): bool => $field['state'] === 'present'));

        $data['profile_coverage'] = [
            'present' => $present,
            'total_reviewed' => count($fields),
            'need_attention' => $ok ? count($fields) - $present : 0,
            'unavailable' => $ok ? 0 : count($fields),
            'groups' => [],
        ];

        $data['profile'] = array_merge($data['profile'] ?? [], [
            'fields' => $fields,
            'categories' => [
                'primary' => $category ?: '—',
                'additional' => [],
                'offering_map' => [],
            ],
            'services' => [],
            'location' => array_merge($data['profile']['location'] ?? [], [
                'address' => $address ?: '—',
                'lat' => null,
                'lng' => null,
                'website_location_page' => $website ?: '—',
            ]),
        ]);

        $data['connection'] = [
            'bound' => $bound,
            'binding_id' => $binding?->id,
            'resource_id' => $resource?->id,
            'resource_name' => $resource?->display_name ?: $resource?->external_id,
            'external_id' => $resource?->external_id,
            'integration_name' => $resource?->integration?->name,
            'last_run_status' => $lastRun?->status,
            'last_run_human' => $lastRun?->finished_at?->diffForHumans() ?? $lastRun?->started_at?->diffForHumans(),
            'last_error' => data_get($lastRun?->metadata, 'safe_error'),
        ];

        $data['unsupported_live_capabilities'] = [
            'reviews',
            'performance',
            'local_visibility',
            'media',
        ];

        return $data;
    }

    /** @return array{key:string,value:string,state:string} */
    private function field(string $key, ?string $value): array
    {
        $present = filled($value);

        return [
            'key' => $key,
            'value' => $present ? (string) $value : '—',
            'state' => $present ? 'present' : 'missing',
        ];
    }

    private function addressLine(mixed $address): ?string
    {
        if (! is_array($address)) {
            return null;
        }

        $parts = array_merge(
            is_array($address['address_lines'] ?? null) ? $address['address_lines'] : [],
            array_filter([
                $this->string($address['locality'] ?? null),
                $this->string($address['administrative_area'] ?? null),
                $this->string($address['postal_code'] ?? null),
                $this->string($address['region_code'] ?? null),
            ]),
        );

        $parts = array_values(array_unique(array_filter(array_map(
            fn ($part) => is_string($part) ? trim($part) : null,
            $parts,
        ))));

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
