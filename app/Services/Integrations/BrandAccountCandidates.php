<?php

namespace App\Services\Integrations;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\BrandSetup\BrandSetupMatcher;
use Illuminate\Support\Collection;

/**
 * Accounts a brand probably owns but has not bound yet ("Hesap ekle" on the brand page, Komuta merkezi coverage).
 *
 * A brand's "business" is the provider container of the accounts already bound to it: the Google Ads manager (MCC)
 * the account was discovered under, the Meta Business portfolio(s) that expose the ad account, the Business Profile
 * account of a location. Every available account in such a container that is bound to no asset is a candidate, and
 * so is any unbound account whose name matches the brand (name / domain, like "Otomatik kur").
 *
 * A candidate is "strong" when its name matches the brand or its container is dedicated to the brand (every account
 * bound in that container belongs to this brand). An agency MCC / Business that holds many customers' accounts is not
 * dedicated, so its other accounts are listed for a manual pick but never raised as a coverage item.
 *
 * One account = one asset: the list holds only accounts bound nowhere; binding one creates a new asset.
 */
final class BrandAccountCandidates
{
    /** Resource types a brand can have several of, one asset each. */
    public const array TYPES = ['google_ads', 'meta_ads', 'google_business_profile'];

    public const array TYPE_LABELS = ['google_ads' => 'Google Ads', 'meta_ads' => 'Meta Ads', 'google_business_profile' => 'İşletme Profili'];

    /** Score from BrandSetupMatcher::nameScore at which an account name counts as the brand's. */
    private const float NAME_MATCH = 0.6;

    /**
     * @return list<array{resource_id: int, type: string, type_label: string, name: string, external_id: string, container: ?string, container_label: ?string, reason: string, strong: bool, currency: ?string}>
     */
    public function forBrand(Brand $brand): array
    {
        return $this->compute(collect([$brand]))[(int) $brand->id] ?? [];
    }

    /**
     * Strong candidates of every given brand (brand id => candidates), computed from one read of bindings and accounts.
     *
     * @param  Collection<int, Brand>  $brands
     * @return array<int, list<array<string, mixed>>>
     */
    public function strongForBrands(Collection $brands): array
    {
        return collect($this->compute($brands))
            ->map(fn (array $rows): array => array_values(array_filter($rows, fn (array $row): bool => $row['strong'])))
            ->filter(fn (array $rows): bool => $rows !== [])
            ->all();
    }

    /**
     * Container key of an account ("google_ads:1234567890", "meta_ads:<business id>", …); several for a Meta ad
     * account visible through more than one Business.
     *
     * @return list<string>
     */
    public static function containers(CoreExternalResource $resource): array
    {
        $type = (string) $resource->resource_type;
        $meta = is_array($resource->metadata) ? $resource->metadata : [];
        $keys = [];
        if ($type === 'google_ads') {
            $manager = preg_replace('/\D+/', '', (string) ($meta['manager_customer_id'] ?? $resource->parent_external_id ?? ''));
            if ($manager !== '' && $manager !== (string) $resource->external_id) {
                $keys[] = $manager;
            }
        } elseif ($type === 'meta_ads') {
            foreach ((array) ($meta['access_contexts'] ?? []) as $context) {
                if (is_array($context) && filled($context['business_id'] ?? null) && ($context['access_lost'] ?? false) !== true) {
                    $keys[] = (string) $context['business_id'];
                }
            }
            if (filled($meta['business_id'] ?? null)) {
                $keys[] = (string) $meta['business_id'];
            }
        } elseif ($type === 'google_business_profile') {
            $account = (string) ($meta['account'] ?? $resource->parent_external_id ?? '');
            if ($account !== '' && $account !== 'accounts/-') {
                $keys[] = $account;
            }
        }

        return array_values(array_unique(array_map(fn (string $key): string => $type.':'.$key, $keys)));
    }

    /**
     * @param  Collection<int, Brand>  $brands
     * @return array<int, list<array<string, mixed>>>
     */
    private function compute(Collection $brands): array
    {
        if ($brands->isEmpty()) {
            return [];
        }
        $active = CoreAssetBinding::query()->where('core_asset_bindings.status', CoreAssetBinding::STATUS_ACTIVE)
            ->whereIn('core_asset_bindings.capability', self::TYPES)
            ->join('digital_assets', 'digital_assets.id', '=', 'core_asset_bindings.digital_asset_id')
            ->get(['core_asset_bindings.external_resource_id', 'digital_assets.brand_id']);
        $boundIds = CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->pluck('external_resource_id')->map(fn ($id): int => (int) $id)->flip();

        $resources = CoreExternalResource::query()
            ->whereIn('resource_type', self::TYPES)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->whereHas('integration', fn ($q) => $q->where('status', CoreIntegration::STATUS_ACTIVE))
            ->orderBy('display_name')
            ->get();
        $byId = $resources->keyBy('id');

        $missing = $active->pluck('external_resource_id')->map(fn ($id): int => (int) $id)->reject(fn (int $id): bool => $byId->has($id))->unique()->values();
        if ($missing->isNotEmpty()) {
            $byId = $byId->union(CoreExternalResource::query()->whereIn('id', $missing->all())->get()->keyBy('id'));
        }

        // container => brand ids that have an account bound in it
        $containerBrands = [];
        foreach ($active as $row) {
            $resource = $byId->get((int) $row->external_resource_id);
            if ($resource === null || $row->brand_id === null) {
                continue;
            }
            foreach (self::containers($resource) as $key) {
                $containerBrands[$key][(int) $row->brand_id] = true;
            }
        }
        $managerNames = $resources->where('resource_type', 'google_ads')
            ->filter(fn (CoreExternalResource $r): bool => (bool) data_get($r->metadata, 'is_manager', false))
            ->mapWithKeys(fn (CoreExternalResource $r): array => ['google_ads:'.$r->external_id => (string) $r->display_name]);

        $unbound = $resources->filter(fn (CoreExternalResource $r): bool => ! $boundIds->has((int) $r->id) && $this->bindable($r));
        $hosts = DigitalAsset::query()->whereIn('brand_id', $brands->pluck('id'))->where('type', 'website')
            ->get(['brand_id', 'domain', 'primary_url'])->groupBy('brand_id')
            ->map(fn (Collection $sites): string => BrandSetupMatcher::host((string) ($sites->first()->primary_url ?: $sites->first()->domain)));

        $out = [];
        foreach ($brands as $brand) {
            $brandId = (int) $brand->id;
            $host = (string) ($hosts[$brandId] ?? '');
            $rows = [];
            foreach ($unbound as $resource) {
                $meta = is_array($resource->metadata) ? $resource->metadata : [];
                $keys = self::containers($resource);
                $shared = array_values(array_filter($keys, fn (string $key): bool => isset($containerBrands[$key][$brandId])));
                $name = trim($resource->display_name.' '.($meta['descriptive_name'] ?? '').' '.($meta['business_name'] ?? ''));
                $nameMatch = BrandSetupMatcher::nameScore($name, (string) $brand->name, $host) >= self::NAME_MATCH;
                if ($shared === [] && ! $nameMatch) {
                    continue;
                }
                $container = $shared[0] ?? ($keys[0] ?? null);
                $dedicated = collect($shared)->contains(fn (string $key): bool => array_keys($containerBrands[$key]) === [$brandId]);
                $rows[] = [
                    'resource_id' => (int) $resource->id,
                    'type' => (string) $resource->resource_type,
                    'type_label' => self::TYPE_LABELS[$resource->resource_type] ?? (string) $resource->resource_type,
                    'name' => (string) ($resource->display_name ?: $resource->external_id),
                    'external_id' => (string) ($meta['customer_id_formatted'] ?? $resource->external_id),
                    'container' => $container,
                    'container_label' => $container !== null ? $this->containerLabel($container, $resource, $managerNames) : null,
                    'reason' => $shared !== [] ? 'same_business' : 'name_match',
                    'strong' => $nameMatch || $dedicated,
                    'currency' => $meta['currency_code'] ?? $meta['currency'] ?? null,
                ];
            }
            usort($rows, fn (array $a, array $b): int => [$b['strong'], $a['type'], $a['name']] <=> [$a['strong'], $b['type'], $b['name']]);
            $out[$brandId] = $rows;
        }

        return $out;
    }

    private function bindable(CoreExternalResource $resource): bool
    {
        $meta = is_array($resource->metadata) ? $resource->metadata : [];

        return ($meta['is_manager'] ?? false) !== true && ($meta['selectable'] ?? true) !== false && ($meta['bindable'] ?? true) !== false;
    }

    /** @param Collection<string, string> $managerNames */
    private function containerLabel(string $container, CoreExternalResource $resource, Collection $managerNames): string
    {
        [$type, $id] = explode(':', $container, 2);
        $meta = is_array($resource->metadata) ? $resource->metadata : [];

        return match ($type) {
            'google_ads' => 'MCC '.($managerNames[$container] ?? $id),
            'meta_ads' => 'Business '.(collect((array) ($meta['access_contexts'] ?? []))->firstWhere('business_id', $id)['business_name'] ?? ($meta['business_name'] ?? $id)),
            default => 'Hesap '.($meta['account_display_name'] ?? $id),
        };
    }
}
