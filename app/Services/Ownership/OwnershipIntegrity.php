<?php

namespace App\Services\Ownership;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Integrations\ExternalResourceAssetCompatibility;
use Illuminate\Support\Facades\DB;

/**
 * Ownership invariants: one account ↔ one asset (active binding), one asset ↔ at most one brand, one brand ↔ exactly
 * one customer, one website asset per host. Most are enforced in code and the database (partial unique index on
 * active bindings, digital_assets.brand_id, brands.customer_id NOT NULL, DigitalAsset saving hook); this check lists
 * what still slipped through (older data, deleted parents) and fixes the safe cases (extra / orphan bindings).
 */
final class OwnershipIntegrity
{
    /**
     * @return list<array{code: string, label: string, subject: string, detail: string, fixable: bool, ids: list<int>}>
     */
    public function problems(): array
    {
        $problems = [];

        // 1. An account bound to more than one asset.
        DB::table('core_asset_bindings')->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->groupBy('external_resource_id')->havingRaw('count(*) > 1')->pluck('external_resource_id')
            ->each(function ($resourceId) use (&$problems): void {
                $ids = CoreAssetBinding::query()->where('external_resource_id', $resourceId)->where('status', CoreAssetBinding::STATUS_ACTIVE)->orderBy('id')->pluck('id')->all();
                $problems[] = ['code' => 'resource_bound_twice', 'label' => 'Hesap iki varlığa bağlı', 'subject' => $this->resourceName((int) $resourceId),
                    'detail' => count($ids).' bağlantı', 'fixable' => true, 'ids' => array_map('intval', $ids)];
            });

        // 2. Bindings on a deleted asset, and bindings on an asset of the wrong type.
        CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->with(['digitalAsset' => fn ($q) => $q->withTrashed(), 'externalResource'])->get()
            ->each(function (CoreAssetBinding $binding) use (&$problems): void {
                $asset = $binding->digitalAsset;
                $resource = $binding->externalResource;
                if ($asset === null || $asset->trashed()) {
                    $problems[] = ['code' => 'binding_on_deleted_asset', 'label' => 'Silinmiş varlığa bağlı hesap', 'subject' => $this->resourceName((int) $binding->external_resource_id),
                        'detail' => '#'.$binding->digital_asset_id, 'fixable' => true, 'ids' => [(int) $binding->id]];

                    return;
                }
                $allowed = ExternalResourceAssetCompatibility::compatibleAssetTypes((string) $resource?->resource_type);
                if ($resource === null || ($allowed !== [] && ! in_array($asset->type, $allowed, true))) {
                    $problems[] = ['code' => 'binding_type_mismatch', 'label' => 'Hesap türü varlıkla uyuşmuyor', 'subject' => $this->resourceName((int) $binding->external_resource_id),
                        'detail' => ($resource?->resource_type ?? '?').' → '.$asset->type.' ('.$asset->name.')', 'fixable' => $resource === null, 'ids' => [(int) $binding->id]];
                }
            });

        // 3. Provider assets without their account.
        DigitalAsset::query()->whereIn('type', ['google_ads', 'meta_ads', 'google_business_profile'])
            ->whereDoesntHave('assetBindings', fn ($q) => $q->where('status', CoreAssetBinding::STATUS_ACTIVE))
            ->with('brand')->get()->each(function (DigitalAsset $asset) use (&$problems): void {
                $problems[] = ['code' => 'asset_without_account', 'label' => 'Hesabı bağlı olmayan varlık', 'subject' => (string) $asset->name,
                    'detail' => (string) ($asset->brand?->name ?? 'markasız'), 'fixable' => false, 'ids' => [(int) $asset->id]];
            });

        // 4. Assets whose brand was deleted; brands whose customer is missing or deleted.
        DigitalAsset::query()->whereNotNull('brand_id')->whereDoesntHave('brand')->get()->each(function (DigitalAsset $asset) use (&$problems): void {
            $problems[] = ['code' => 'asset_brand_missing', 'label' => 'Markası silinmiş varlık', 'subject' => (string) $asset->name,
                'detail' => '#'.$asset->brand_id, 'fixable' => false, 'ids' => [(int) $asset->id]];
        });
        Brand::query()->whereDoesntHave('customer')->get()->each(function (Brand $brand) use (&$problems): void {
            $problems[] = ['code' => 'brand_without_customer', 'label' => 'Müşterisi olmayan marka', 'subject' => (string) $brand->name,
                'detail' => '#'.$brand->customer_id, 'fixable' => false, 'ids' => [(int) $brand->id]];
        });

        // 5. Two website assets on one host.
        foreach (app(WebsiteDuplicateMerger::class)->findGroups() as $group) {
            $problems[] = ['code' => 'duplicate_host', 'label' => 'Aynı alan adında iki web sitesi', 'subject' => (string) $group['host'],
                'detail' => count($group['assets']).' varlık', 'fixable' => false, 'ids' => array_map(fn (array $a): int => (int) $a['id'], $group['assets'])];
        }

        return $problems;
    }

    /**
     * Safe fixes only: extra bindings of one account (the oldest stays) and bindings on deleted assets / accounts are
     * disabled (kept for history, reversible). Everything else needs an operator decision.
     *
     * @return int bindings disabled
     */
    public function fix(User $actor): int
    {
        $fixed = 0;
        foreach ($this->problems() as $problem) {
            if (! $problem['fixable']) {
                continue;
            }
            $ids = $problem['code'] === 'resource_bound_twice' ? array_slice($problem['ids'], 1) : $problem['ids'];
            foreach (CoreAssetBinding::query()->whereIn('id', $ids)->where('status', CoreAssetBinding::STATUS_ACTIVE)->get() as $binding) {
                $binding->forceFill(['status' => CoreAssetBinding::STATUS_DISABLED, 'configuration' => array_merge((array) $binding->configuration, [
                    'closed_by_user_id' => $actor->id, 'closed_at' => now()->toIso8601String(), 'closed_reason' => 'integrity_'.$problem['code'],
                ])])->save();
                $fixed++;
            }
        }

        return $fixed;
    }

    private function resourceName(int $resourceId): string
    {
        $row = DB::table('core_external_resources')->where('id', $resourceId)->first(['display_name', 'external_id']);

        return $row !== null ? trim((string) ($row->display_name ?: $row->external_id)) : '#'.$resourceId;
    }
}
