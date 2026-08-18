<?php

namespace App\Services\Gbp;

use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Support\Reality\UnavailableWorkspaceShells;
use MoxDop\GoogleBusinessProfile\Collection\GbpLocationBoundCollector;

/**
 * Truthful operator presenter for the GBP capabilities that are actually collected today.
 *
 * Unsupported review/performance/local-rank surfaces remain explicitly unavailable; this
 * presenter never invents metrics. As collectors are added they should replace those gaps.
 */
final class GbpOperatorWorkspace
{
    /** @return array<string, mixed> */
    public function for(DigitalAsset $asset): array
    {
        $data = UnavailableWorkspaceShells::gbp((string) $asset->id);
        $binding = CoreAssetBinding::query()
            ->with('externalResource.integration')
            ->where('digital_asset_id', $asset->id)
            ->where('capability', GbpLocationBoundCollector::CAPABILITY)
            ->first();

        $evidence = Evidence::query()
            ->where('digital_asset_id', $asset->id)
            ->where('source_module', GbpLocationBoundCollector::MODULE_ID)
            ->where('type', GbpLocationBoundCollector::EVIDENCE_TYPE)
            ->whereHas('run', fn ($query) => $query->where('status', 'completed'))
            ->latest('observed_at')
            ->latest('id')
            ->first();

        $lastRun = Run::query()
            ->where('digital_asset_id', $asset->id)
            ->where('module_id', GbpLocationBoundCollector::MODULE_ID)
            ->latest('id')
            ->first();

        $payload = is_array($evidence?->payload) ? $evidence->payload : [];
        $ok = ($payload['ok'] ?? false) === true;
        $bound = $binding !== null && $binding->status === CoreAssetBinding::STATUS_ACTIVE;
        $resource = $binding?->externalResource;
        $address = $this->addressLine($payload['storefront_address'] ?? null);
        $title = $this->string($payload['title'] ?? null) ?: ($resource?->display_name ?: $asset->name);
        $website = $this->string($payload['website_uri'] ?? null);
        $phone = $this->string($payload['primary_phone'] ?? null);
        $category = $this->string($payload['primary_category'] ?? null);
        $lastRefresh = $evidence?->observed_at?->diffForHumans() ?? $lastRun?->finished_at?->diffForHumans() ?? 'Never';

        $data['migration_mode'] = $ok ? 'real' : ($bound ? 'configured' : 'unavailable');
        $data['demo_boundary'] = $ok
            ? 'Live Google Business Profile location evidence.'
            : ($bound ? 'GBP is bound; collect live data to populate the profile.' : 'Bind a discovered Google Business Profile location to this Digital Asset.');

        $data['identity'] = array_merge($data['identity'] ?? [], [
            'eyebrow' => 'Google Business Profile',
            'title' => $title ?: 'Google Business Profile',
            'brand' => $asset->brand?->name ?? '—',
            'brand_id' => $asset->brand_id,
            'brand_name' => $asset->brand?->name ?? '—',
            'status' => $ok ? 'Connected' : ($bound ? 'Needs collection' : 'Not configured'),
            'locale' => '—',
            'location_line' => $address ?: '—',
            'last_refresh' => $lastRefresh,
        ]);

        $fields = [
            $this->field('Business name', $title, 'Google Business Profile API'),
            $this->field('Primary category', $category, 'Google Business Profile API'),
            $this->field('Website', $website, 'Google Business Profile API'),
            $this->field('Primary phone', $phone, 'Google Business Profile API'),
            $this->field('Address', $address, 'Google Business Profile API'),
        ];
        $present = count(array_filter($fields, fn (array $field): bool => $field['state'] === 'Present'));

        $data['profile_coverage'] = [
            'present' => $present,
            'total_reviewed' => count($fields),
            'need_attention' => $ok ? count($fields) - $present : 0,
            'unavailable' => $ok ? 0 : count($fields),
            'note' => $ok
                ? 'Coverage is based only on fields returned by the current read-only GBP collector.'
                : ($bound ? 'No successful GBP profile evidence collected yet.' : 'No active GBP location binding.'),
            'groups' => [],
        ];

        $data['profile'] = array_merge($data['profile'] ?? [], [
            'subtitle' => $ok
                ? 'Live read-only profile data from the bound Google Business Profile location.'
                : 'Profile data becomes available after a bound location is collected.',
            'fields' => $fields,
            'categories' => [
                'primary' => $category ?: '—',
                'additional' => [],
                'note' => $ok ? 'Additional categories are not collected by the current read mask.' : 'No category evidence collected.',
                'offering_map' => [],
            ],
            'services' => [],
            'location' => array_merge($data['profile']['location'] ?? [], [
                'address' => $address ?: '—',
                'lat' => null,
                'lng' => null,
                'service_area' => 'Not collected',
                'website_location_page' => $website ?: '—',
                'website_location_state' => $website ? 'Observed on GBP' : 'Not collected',
                'note' => 'Coordinates and service-area data are not part of the current GBP collector read mask.',
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
            'reviews' => 'No live GBP reviews collector is wired yet.',
            'performance' => 'No live GBP performance/insights collector is wired yet.',
            'local_visibility' => 'No real local-rank grid collector is wired yet.',
            'media' => 'No live GBP media collector is wired yet.',
        ];

        return $data;
    }

    /** @return array{area:string,value:string,state:string,evidence:string,action:string} */
    private function field(string $area, ?string $value, string $evidence): array
    {
        $present = filled($value);

        return [
            'area' => $area,
            'value' => $present ? (string) $value : '—',
            'state' => $present ? 'Present' : 'Needs attention',
            'evidence' => $evidence,
            'action' => $present ? 'No action' : 'Review the GBP profile / API access.',
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
