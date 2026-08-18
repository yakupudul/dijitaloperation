<?php

namespace App\Services\Integrations;

use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Integrations\BindingCardinalityRegistry;
use App\Support\Integrations\BindingScopeGuard;
use App\Support\Integrations\ExternalResourceAssetCompatibility;
use App\Support\Integrations\Meta\MetaBindingEligibilityPolicy;
use App\Support\Integrations\Meta\MetaConnectorRegistry;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Integrations\ResourceBindingPlan;
use App\Support\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Human-confirmed Meta META_AD_ACCOUNT → Meta Ads DigitalAsset Binding.
 *
 * Reuses shared BindingScopeGuard / cardinality / compatibility.
 * Does NOT call Meta Graph. Does NOT start CollectionRun.
 * Does NOT bind META_BUSINESS. Does NOT invent Instagram relations.
 */
final class ConfirmMetaResourceBindingService
{
    public function __construct(
        private readonly MetaBindingEligibilityPolicy $eligibility = new MetaBindingEligibilityPolicy,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     binding?: CoreAssetBinding,
     *     asset?: DigitalAsset,
     *     created_asset?: bool,
     *     replaced?: bool,
     *     previous_binding_id?: int|null
     * }
     */
    public function confirm(ResourceBindingPlan $plan): array
    {
        $this->assertOperator($plan->confirmedBy);

        $resource = $plan->resource->fresh(['integration']) ?? $plan->resource;

        try {
            $this->eligibility->assertEligibleResource($resource, $plan->expectedIntegrationId);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['resource_id' => $e->getMessage()]);
        }

        $brand = $plan->brand->fresh(['customer']) ?? $plan->brand;
        if (! $brand instanceof Brand) {
            throw ValidationException::withMessages(['brand_id' => 'Select a valid Brand.']);
        }

        return DB::transaction(function () use ($plan, $resource, $brand): array {
            $createdAsset = false;
            $replaced = false;
            $previousBindingId = null;

            if ($plan->mode === ResourceBindingPlan::MODE_CREATE_ASSET) {
                $existingPreferred = $this->findExistingMetaAdsAsset($brand);
                if ($existingPreferred instanceof DigitalAsset) {
                    $asset = $existingPreferred;
                } else {
                    $name = trim($plan->assetName) !== '' ? trim($plan->assetName) : (string) $resource->display_name;
                    $asset = DigitalAsset::query()->create([
                        'brand_id' => $brand->id,
                        'name' => $name !== '' ? $name : 'Meta Ads',
                        'type' => 'meta_ads',
                        'module_id' => 'meta-ads',
                        'status' => DigitalAssetStatus::Active->value,
                    ]);
                    $createdAsset = true;
                }
            } elseif ($plan->mode === ResourceBindingPlan::MODE_EXISTING_ASSET) {
                $asset = $plan->existingAsset?->fresh(['brand']) ?? $plan->existingAsset;
                if (! $asset instanceof DigitalAsset) {
                    throw ValidationException::withMessages([
                        'digital_asset_id' => 'Select an existing Meta Ads Digital Asset.',
                    ]);
                }

                if ((int) $asset->brand_id !== (int) $brand->id) {
                    throw ValidationException::withMessages([
                        'digital_asset_id' => 'Digital Asset must belong to the selected Brand.',
                    ]);
                }
            } else {
                throw ValidationException::withMessages(['mode' => 'Invalid binding mode.']);
            }

            try {
                $this->eligibility->assertEligibleAsset($asset);
                BindingScopeGuard::assertCanBind($asset, $resource);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages([
                    'digital_asset_id' => $e->getMessage(),
                ]);
            }

            if (! ExternalResourceAssetCompatibility::isCompatible($asset, $resource)) {
                throw ValidationException::withMessages([
                    'digital_asset_id' => 'Digital Asset type is not compatible with this ExternalResource.',
                ]);
            }

            // Idempotent: exact active Binding already present.
            $exactActive = CoreAssetBinding::query()
                ->where('digital_asset_id', $asset->id)
                ->where('external_resource_id', $resource->id)
                ->where('capability', MetaConnectorRegistry::META_ADS)
                ->where('status', CoreAssetBinding::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($exactActive instanceof CoreAssetBinding) {
                return [
                    'ok' => true,
                    'message' => 'Meta Ad Account is already connected to this Meta Ads asset. Collection was not started.',
                    'binding' => $exactActive->fresh(['digitalAsset', 'externalResource']) ?? $exactActive,
                    'asset' => $asset->fresh() ?? $asset,
                    'created_asset' => false,
                    'replaced' => false,
                    'previous_binding_id' => null,
                ];
            }

            // Reactivate historical exact pair after unbind (same account).
            $exactDisabled = CoreAssetBinding::query()
                ->where('digital_asset_id', $asset->id)
                ->where('external_resource_id', $resource->id)
                ->where('capability', MetaConnectorRegistry::META_ADS)
                ->where('status', CoreAssetBinding::STATUS_DISABLED)
                ->lockForUpdate()
                ->first();

            $activeOnAsset = CoreAssetBinding::query()
                ->where('digital_asset_id', $asset->id)
                ->where('capability', MetaConnectorRegistry::META_ADS)
                ->where('status', CoreAssetBinding::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            $activeOnResource = CoreAssetBinding::query()
                ->where('external_resource_id', $resource->id)
                ->where('status', CoreAssetBinding::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($activeOnResource instanceof CoreAssetBinding
                && (int) $activeOnResource->digital_asset_id !== (int) $asset->id) {
                throw ValidationException::withMessages([
                    'resource_id' => 'This Meta Ad Account is already connected to another Meta Ads asset.',
                ]);
            }

            if ($activeOnAsset instanceof CoreAssetBinding
                && (int) $activeOnAsset->external_resource_id !== (int) $resource->id) {
                if (! $plan->allowReplace) {
                    $currentName = $activeOnAsset->externalResource?->display_name
                        ?? $activeOnAsset->externalResource?->external_id
                        ?? 'another Ad Account';

                    throw ValidationException::withMessages([
                        'resource_id' => "This Meta Ads asset is currently connected to {$currentName}. Confirm replacement to change the Ad Account — historical data from the previous account is preserved.",
                    ]);
                }

                $previousBindingId = (int) $activeOnAsset->id;
                $this->closeBinding($activeOnAsset, $plan->confirmedBy, 'replaced');
                $replaced = true;
            }

            if ($exactDisabled instanceof CoreAssetBinding) {
                if ($activeOnAsset instanceof CoreAssetBinding
                    && (int) $activeOnAsset->id !== (int) $exactDisabled->id
                    && (int) $activeOnAsset->external_resource_id !== (int) $resource->id) {
                    // Already closed above when allowReplace.
                }

                $exactDisabled->forceFill([
                    'status' => CoreAssetBinding::STATUS_ACTIVE,
                    'configuration' => array_merge(
                        is_array($exactDisabled->configuration) ? $exactDisabled->configuration : [],
                        [
                            'confirmed_by_user_id' => $plan->confirmedBy->id,
                            'confirmed_at' => now()->toIso8601String(),
                            'origin' => 'meta_integration_selection',
                            'mode' => $plan->mode,
                            'reactivated' => true,
                        ],
                    ),
                ])->save();

                return [
                    'ok' => true,
                    'message' => $replaced
                        ? 'Meta Ad Account connection replaced. Historical data from the previous account is preserved. Collection was not started.'
                        : 'Meta Ad Account reconnected to this Meta Ads asset. Collection was not started.',
                    'binding' => $exactDisabled->fresh(['digitalAsset', 'externalResource']) ?? $exactDisabled,
                    'asset' => $asset->fresh() ?? $asset,
                    'created_asset' => $createdAsset,
                    'replaced' => $replaced,
                    'previous_binding_id' => $previousBindingId,
                ];
            }

            $rules = BindingCardinalityRegistry::forResourceType(MetaResourceType::META_AD_ACCOUNT);
            if ($activeOnAsset instanceof CoreAssetBinding && ! $replaced && $rules['max_active_resources_per_asset'] <= 1) {
                throw ValidationException::withMessages([
                    'digital_asset_id' => 'This Meta Ads asset is currently connected to another Ad Account.',
                ]);
            }

            try {
                /** @var CoreAssetBinding $binding */
                $binding = CoreAssetBinding::query()->create([
                    'digital_asset_id' => $asset->id,
                    'external_resource_id' => $resource->id,
                    'capability' => MetaConnectorRegistry::META_ADS,
                    'status' => CoreAssetBinding::STATUS_ACTIVE,
                    'configuration' => [
                        'confirmed_by_user_id' => $plan->confirmedBy->id,
                        'confirmed_at' => now()->toIso8601String(),
                        'origin' => 'meta_integration_selection',
                        'mode' => $plan->mode,
                        'created_asset' => $createdAsset,
                        'replaced_previous_binding_id' => $previousBindingId,
                    ],
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    $existing = CoreAssetBinding::query()
                        ->where('digital_asset_id', $asset->id)
                        ->where('external_resource_id', $resource->id)
                        ->where('status', CoreAssetBinding::STATUS_ACTIVE)
                        ->first();

                    if ($existing instanceof CoreAssetBinding) {
                        return [
                            'ok' => true,
                            'message' => 'Meta Ad Account is already connected to this Meta Ads asset. Collection was not started.',
                            'binding' => $existing,
                            'asset' => $asset->fresh() ?? $asset,
                            'created_asset' => false,
                            'replaced' => false,
                            'previous_binding_id' => null,
                        ];
                    }

                    throw ValidationException::withMessages([
                        'resource_id' => 'This Binding already exists or conflicts with an existing Binding.',
                    ]);
                }

                throw $e;
            }

            return [
                'ok' => true,
                'message' => $createdAsset
                    ? 'Meta Ads Digital Asset created and Ad Account connected. Collection was not started.'
                    : ($replaced
                        ? 'Meta Ad Account connection replaced. Historical data from the previous account is preserved. Collection was not started.'
                        : 'Meta Ad Account connected to Meta Ads Digital Asset. Collection was not started.'),
                'binding' => $binding->fresh(['digitalAsset', 'externalResource']) ?? $binding,
                'asset' => $asset->fresh() ?? $asset,
                'created_asset' => $createdAsset,
                'replaced' => $replaced,
                'previous_binding_id' => $previousBindingId,
            ];
        });
    }

    /**
     * Disconnect Ad Account Binding without revoking Meta authorization.
     *
     * @return array{ok: bool, message: string, binding?: CoreAssetBinding}
     */
    public function unbind(CoreAssetBinding $binding, User $operator): array
    {
        $this->assertOperator($operator);

        $binding = $binding->fresh(['externalResource', 'digitalAsset']) ?? $binding;

        if ($binding->capability !== MetaConnectorRegistry::META_ADS) {
            throw ValidationException::withMessages([
                'binding_id' => 'Only Meta Ads Bindings can be disconnected through this action.',
            ]);
        }

        if ($binding->status === CoreAssetBinding::STATUS_DISABLED) {
            return [
                'ok' => true,
                'message' => 'Ad Account is already disconnected from this Meta Ads asset.',
                'binding' => $binding,
            ];
        }

        $this->closeBinding($binding, $operator, 'unbound');

        return [
            'ok' => true,
            'message' => 'Disconnected this Ad Account from this Meta Ads asset. Meta authorization and resource inventory are unchanged. Historical data is preserved.',
            'binding' => $binding->fresh() ?? $binding,
        ];
    }

    /**
     * Filament / shared persist path for existing-asset bind.
     */
    public function bindExisting(
        DigitalAsset $asset,
        CoreExternalResource $resource,
        User $confirmedBy,
        bool $allowReplace = false,
        ?int $expectedIntegrationId = null,
    ): CoreAssetBinding {
        $this->assertOperator($confirmedBy);
        $resource = $resource->fresh(['integration']) ?? $resource;

        $brand = $asset->brand;
        if (! $brand instanceof Brand) {
            throw new RuntimeException('Digital Asset Brand is missing.');
        }

        $result = $this->confirm(new ResourceBindingPlan(
            resource: $resource,
            brand: $brand,
            mode: ResourceBindingPlan::MODE_EXISTING_ASSET,
            existingAsset: $asset,
            assetName: (string) $asset->name,
            confirmedBy: $confirmedBy,
            allowReplace: $allowReplace,
            expectedIntegrationId: $expectedIntegrationId ?? (int) $resource->integration_id,
        ));

        $binding = $result['binding'] ?? null;
        if (! $binding instanceof CoreAssetBinding) {
            throw new RuntimeException($result['message'] ?? 'Binding failed.');
        }

        return $binding->fresh(['externalResource']) ?? $binding;
    }

    /**
     * @return list<array{id: int, name: string, type: string, customer: string}>
     */
    public function compatibleExistingAssets(CoreExternalResource $resource, Brand $brand): array
    {
        $types = ExternalResourceAssetCompatibility::compatibleAssetTypes((string) $resource->resource_type);
        if ($types === []) {
            return [];
        }

        $capability = MetaConnectorRegistry::META_ADS;

        return DigitalAsset::query()
            ->with('brand.customer:id,name')
            ->where('brand_id', $brand->id)
            ->whereIn('type', $types)
            ->where('status', DigitalAssetStatus::Active->value)
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->map(fn (DigitalAsset $asset): array => [
                'id' => (int) $asset->id,
                'name' => (string) $asset->name,
                'type' => (string) $asset->type,
                'customer' => (string) ($asset->brand?->customer?->name ?? ''),
                'has_active_binding' => CoreAssetBinding::query()
                    ->where('digital_asset_id', $asset->id)
                    ->where('capability', $capability)
                    ->where('status', CoreAssetBinding::STATUS_ACTIVE)
                    ->exists(),
            ])
            ->all();
    }

    private function findExistingMetaAdsAsset(Brand $brand): ?DigitalAsset
    {
        return DigitalAsset::query()
            ->where('brand_id', $brand->id)
            ->where('type', 'meta_ads')
            ->where('status', DigitalAssetStatus::Active->value)
            ->whereDoesntHave('assetBindings', function ($q): void {
                $q->where('capability', MetaConnectorRegistry::META_ADS)
                    ->where('status', CoreAssetBinding::STATUS_ACTIVE);
            })
            ->orderBy('id')
            ->first();
    }

    private function closeBinding(CoreAssetBinding $binding, User $operator, string $reason): void
    {
        $config = is_array($binding->configuration) ? $binding->configuration : [];
        $binding->forceFill([
            'status' => CoreAssetBinding::STATUS_DISABLED,
            'configuration' => array_merge($config, [
                'closed_by_user_id' => $operator->id,
                'closed_at' => now()->toIso8601String(),
                'closed_reason' => $reason,
            ]),
        ])->save();
    }

    private function assertOperator(User $user): void
    {
        if (! $user->hasRole(Roles::ADMIN)) {
            throw ValidationException::withMessages([
                'authorization' => 'Only authorized operators may confirm Meta resource bindings.',
            ]);
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || (string) $e->getCode() === '23000';
    }
}
