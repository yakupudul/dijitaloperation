<?php

namespace App\Services\Integrations;

use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Integrations\BindingCardinalityRegistry;
use App\Support\Integrations\BindingScopeGuard;
use App\Support\Integrations\ExternalResourceAssetCompatibility;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Integrations\ResourceBindingPlan;
use App\Support\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Human-confirmed Google ExternalResource → DigitalAsset Binding.
 *
 * Does NOT dispatch CollectionRun / analytical collection.
 * Does NOT invent semantic Asset Relationships.
 */
final class ConfirmGoogleResourceBindingService
{
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
        $this->assertSelectableResource($resource);

        $brand = $plan->brand->fresh(['customer']) ?? $plan->brand;
        if (! $brand instanceof Brand) {
            throw ValidationException::withMessages(['brand_id' => 'Select a valid Brand.']);
        }

        return DB::transaction(function () use ($plan, $resource, $brand): array {
            $createdAsset = false;

            if ($plan->mode === ResourceBindingPlan::MODE_CREATE_ASSET) {
                $assetType = ExternalResourceAssetCompatibility::preferredAssetType((string) $resource->resource_type);
                if ($assetType === null) {
                    throw ValidationException::withMessages([
                        'resource_id' => 'This resource type cannot create a Digital Asset.',
                    ]);
                }

                $name = trim($plan->assetName) !== '' ? trim($plan->assetName) : (string) $resource->display_name;
                $asset = DigitalAsset::query()->create([
                    'brand_id' => $brand->id,
                    'name' => $name,
                    'type' => $assetType,
                    'status' => DigitalAssetStatus::Active->value,
                ]);
                $createdAsset = true;
            } elseif ($plan->mode === ResourceBindingPlan::MODE_EXISTING_ASSET) {
                $asset = $plan->existingAsset?->fresh(['brand']) ?? $plan->existingAsset;
                if (! $asset instanceof DigitalAsset) {
                    throw ValidationException::withMessages([
                        'digital_asset_id' => 'Select an existing Digital Asset.',
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

            $capability = (string) $resource->resource_type;
            $replaced = false;
            $previousBindingId = null;

            $exactActive = CoreAssetBinding::query()
                ->where('digital_asset_id', $asset->id)
                ->where('external_resource_id', $resource->id)
                ->where('capability', $capability)
                ->where('status', CoreAssetBinding::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($exactActive instanceof CoreAssetBinding) {
                return [
                    'ok' => true,
                    'message' => 'Google resource is already bound to this Digital Asset. Collection was not started.',
                    'binding' => $exactActive->fresh(['digitalAsset', 'externalResource']) ?? $exactActive,
                    'asset' => $asset->fresh() ?? $asset,
                    'created_asset' => false,
                    'replaced' => false,
                    'previous_binding_id' => null,
                ];
            }

            $exactDisabled = CoreAssetBinding::query()
                ->where('digital_asset_id', $asset->id)
                ->where('external_resource_id', $resource->id)
                ->where('capability', $capability)
                ->where('status', CoreAssetBinding::STATUS_DISABLED)
                ->lockForUpdate()
                ->first();

            $activeOnAsset = CoreAssetBinding::query()
                ->where('digital_asset_id', $asset->id)
                ->where('capability', $capability)
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
                    'resource_id' => 'This ExternalResource is already bound to a Digital Asset.',
                ]);
            }

            if ($activeOnAsset instanceof CoreAssetBinding
                && (int) $activeOnAsset->external_resource_id !== (int) $resource->id) {
                if (! $plan->allowReplace) {
                    throw ValidationException::withMessages([
                        'digital_asset_id' => 'This Digital Asset already has an active Binding for this capability.',
                    ]);
                }

                $previousBindingId = (int) $activeOnAsset->id;
                $this->closeBinding($activeOnAsset, $plan->confirmedBy, 'replaced');
                $replaced = true;
            }

            if ($exactDisabled instanceof CoreAssetBinding) {
                $exactDisabled->forceFill([
                    'status' => CoreAssetBinding::STATUS_ACTIVE,
                    'configuration' => array_merge(
                        is_array($exactDisabled->configuration) ? $exactDisabled->configuration : [],
                        [
                            'confirmed_by_user_id' => $plan->confirmedBy->id,
                            'confirmed_at' => now()->toIso8601String(),
                            'origin' => 'google_integration_selection',
                            'mode' => $plan->mode,
                            'reactivated' => true,
                            'replaced_previous_binding_id' => $previousBindingId,
                        ],
                    ),
                ])->save();

                return [
                    'ok' => true,
                    'message' => $replaced
                        ? 'Google resource connection replaced. Historical data from the previous resource is preserved. Collection was not started.'
                        : 'Google resource reconnected to this Digital Asset. Collection was not started.',
                    'binding' => $exactDisabled->fresh(['digitalAsset', 'externalResource']) ?? $exactDisabled,
                    'asset' => $asset->fresh() ?? $asset,
                    'created_asset' => $createdAsset,
                    'replaced' => $replaced,
                    'previous_binding_id' => $previousBindingId,
                ];
            }

            $rules = BindingCardinalityRegistry::forResourceType($capability);
            if ($activeOnAsset instanceof CoreAssetBinding && ! $replaced && $rules['max_active_resources_per_asset'] <= 1) {
                throw ValidationException::withMessages([
                    'digital_asset_id' => 'This Digital Asset already has an active Binding for this capability.',
                ]);
            }

            try {
                /** @var CoreAssetBinding $binding */
                $binding = CoreAssetBinding::query()->create([
                    'digital_asset_id' => $asset->id,
                    'external_resource_id' => $resource->id,
                    'capability' => $capability,
                    'status' => CoreAssetBinding::STATUS_ACTIVE,
                    'configuration' => [
                        'confirmed_by_user_id' => $plan->confirmedBy->id,
                        'confirmed_at' => now()->toIso8601String(),
                        'origin' => 'google_integration_selection',
                        'mode' => $plan->mode,
                        'created_asset' => $createdAsset,
                        'replaced_previous_binding_id' => $previousBindingId,
                    ],
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    throw ValidationException::withMessages([
                        'resource_id' => 'This Binding already exists or conflicts with an existing Binding.',
                    ]);
                }

                throw $e;
            }

            return [
                'ok' => true,
                'message' => $createdAsset
                    ? 'Digital Asset created and Google resource bound. Collection was not started.'
                    : ($replaced
                        ? 'Google resource connection replaced. Historical data from the previous resource is preserved. Collection was not started.'
                        : 'Google resource bound to Digital Asset. Collection was not started.'),
                'binding' => $binding->fresh(['digitalAsset', 'externalResource']) ?? $binding,
                'asset' => $asset->fresh() ?? $asset,
                'created_asset' => $createdAsset,
                'replaced' => $replaced,
                'previous_binding_id' => $previousBindingId,
            ];
        });
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

        $capability = (string) $resource->resource_type;

        return DigitalAsset::query()
            ->with('brand.customer:id,name')
            ->where('brand_id', $brand->id)
            ->whereIn('type', $types)
            ->where('status', DigitalAssetStatus::Active->value)
            ->whereDoesntHave('assetBindings', function ($q) use ($capability): void {
                $q->where('capability', $capability)
                    ->where('status', CoreAssetBinding::STATUS_ACTIVE);
            })
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->map(fn (DigitalAsset $asset): array => [
                'id' => (int) $asset->id,
                'name' => (string) $asset->name,
                'type' => (string) $asset->type,
                'customer' => (string) ($asset->brand?->customer?->name ?? ''),
            ])
            ->all();
    }

    /**
     * Filament / shared persist path for existing-asset bind.
     */
    public function bindExisting(
        DigitalAsset $asset,
        CoreExternalResource $resource,
        User $confirmedBy,
        bool $allowReplace = false,
        string $status = CoreAssetBinding::STATUS_ACTIVE,
    ): CoreAssetBinding {
        $this->assertOperator($confirmedBy);
        $resource = $resource->fresh(['integration']) ?? $resource;
        $this->assertSelectableResource($resource);

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
        ));

        $binding = $result['binding'] ?? null;
        if (! $binding instanceof CoreAssetBinding) {
            throw new RuntimeException($result['message'] ?? 'Binding failed.');
        }

        if ($status !== CoreAssetBinding::STATUS_ACTIVE) {
            $binding->forceFill(['status' => $status])->save();
        }

        return $binding->fresh(['externalResource']) ?? $binding;
    }

    /**
     * Disconnect a Google Binding without revoking Google authorization.
     *
     * @return array{ok: bool, message: string, binding?: CoreAssetBinding}
     */
    public function unbind(CoreAssetBinding $binding, User $operator): array
    {
        $this->assertOperator($operator);

        $binding = $binding->fresh(['externalResource', 'digitalAsset']) ?? $binding;

        if (! GoogleResourceType::isValid((string) $binding->capability)) {
            throw ValidationException::withMessages([
                'binding_id' => 'Only Google Bindings can be disconnected through this action.',
            ]);
        }

        if ($binding->status === CoreAssetBinding::STATUS_DISABLED) {
            return [
                'ok' => true,
                'message' => 'Google resource is already disconnected from this Digital Asset.',
                'binding' => $binding,
            ];
        }

        $this->closeBinding($binding, $operator, 'unbound');

        return [
            'ok' => true,
            'message' => 'Disconnected this Google resource from this Digital Asset. Authorization and resource inventory are unchanged. Historical data is preserved.',
            'binding' => $binding->fresh() ?? $binding,
        ];
    }

    private function assertOperator(User $user): void
    {
        if (! $user->hasRole(Roles::ADMIN)) {
            throw ValidationException::withMessages([
                'authorization' => 'Only authorized operators may confirm Google resource bindings.',
            ]);
        }
    }

    private function assertSelectableResource(CoreExternalResource $resource): void
    {
        if ($resource->provider !== ProviderRegistry::GOOGLE) {
            throw ValidationException::withMessages([
                'resource_id' => 'Only Google ExternalResources can be bound through this service.',
            ]);
        }

        if ($resource->status !== CoreExternalResource::STATUS_AVAILABLE) {
            throw ValidationException::withMessages([
                'resource_id' => 'ExternalResource is not available for binding.',
            ]);
        }

        $integration = $resource->integration;
        if (! $integration instanceof CoreIntegration || $integration->status !== CoreIntegration::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'resource_id' => 'ExternalResource belongs to an inactive Integration.',
            ]);
        }

        $rules = BindingCardinalityRegistry::forResourceType((string) $resource->resource_type);
        $selectable = $resource->metadata['selectable'] ?? true;
        if ($rules['managers_selectable'] === false && $selectable === false) {
            throw ValidationException::withMessages([
                'resource_id' => 'This Google Ads manager account is hierarchy context and cannot be bound as a performance Digital Asset.',
            ]);
        }

        if (($resource->metadata['is_manager'] ?? false) === true && $resource->resource_type === 'google_ads') {
            throw ValidationException::withMessages([
                'resource_id' => 'Google Ads manager accounts are not selectable performance bind targets.',
            ]);
        }
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

    private function isUniqueViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || (string) $e->getCode() === '23000';
    }
}
