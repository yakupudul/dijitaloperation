<?php

namespace App\Services\Ownership;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\OwnershipTransfer;
use App\Models\ResourceAutomation;
use App\Models\User;
use App\Services\Integrations\ConfirmGoogleResourceBindingService;
use App\Services\Integrations\ConfirmMetaResourceBindingService;
use App\Services\Queries\AssetSectorService;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Yetki devri: moves an external account or a digital asset to another owner, only when an Admin explicitly
 * confirmed it from a human action (`confirmed = true`). Every transfer is recorded in ownership_transfers.
 * Automatic flows (Otomatik kur, Toplu ekle, Brain) never call this; they skip and report instead.
 */
final class OwnershipTransferService
{
    public const string CLOSED_REASON = 'transferred';

    public function __construct(private readonly OwnershipGuard $guard) {}

    /**
     * Binds the resource to the target asset, closing its binding on the current asset (kept disabled for history).
     */
    public function transferResource(
        CoreExternalResource $resource,
        DigitalAsset $target,
        User $actor,
        bool $confirmed,
        ?string $note = null,
        bool $allowReplace = true,
    ): CoreAssetBinding {
        $this->assertAllowed($actor, $confirmed);

        return match ($resource->provider) {
            ProviderRegistry::META => app(ConfirmMetaResourceBindingService::class)->bindExisting($target, $resource, $actor, allowReplace: $allowReplace, transferConfirmed: true, transferNote: $note),
            ProviderRegistry::GOOGLE => app(ConfirmGoogleResourceBindingService::class)->bindExisting($target, $resource, $actor, allowReplace: $allowReplace, transferConfirmed: true, transferNote: $note),
            default => throw ValidationException::withMessages(['resource_id' => 'Bu hesap türü devredilemez.']),
        };
    }

    /**
     * Called by the binding services inside their transaction once the confirmation is checked: closes the current
     * binding with reason "transferred" and records the transfer.
     */
    public function releaseForTransfer(CoreAssetBinding $current, OwnershipConflict $conflict, DigitalAsset $target, User $actor, ?string $note = null): OwnershipTransfer
    {
        $this->assertAllowed($actor, true);
        $config = is_array($current->configuration) ? $current->configuration : [];
        $current->forceFill([
            'status' => CoreAssetBinding::STATUS_DISABLED,
            'configuration' => array_merge($config, [
                'closed_by_user_id' => $actor->id,
                'closed_at' => now()->toIso8601String(),
                'closed_reason' => self::CLOSED_REASON,
                'transferred_to_asset_id' => (int) $target->id,
            ]),
        ])->save();

        $target->loadMissing('brand');
        $targetCustomerId = $target->brand?->customer_id !== null ? (int) $target->brand->customer_id : null;
        $sameCustomer = $conflict->currentCustomerId !== null && $conflict->currentCustomerId === $targetCustomerId;
        $mapping = $this->rescopeMappings([(int) $conflict->subjectId], $sameCustomer, $target->brand_id !== null ? (int) $target->brand_id : null);

        return $this->record($conflict, $actor, $note, [
            'to_customer_id' => $target->brand?->customer_id,
            'to_brand_id' => $target->brand_id,
            'to_asset_id' => (int) $target->id,
        ], ['to_asset' => (string) $target->name] + ($mapping !== [] ? ['mapping' => $mapping] : []));
    }

    /**
     * Moves the asset to the brand. Another customer's brand = yetki devri (Admin + confirmed, recorded);
     * a brand of the same customer, or a brandless asset, moves directly.
     */
    public function moveAsset(DigitalAsset $asset, Brand $targetBrand, User $actor, bool $confirmed = false, ?string $note = null): DigitalAsset
    {
        if ((int) $asset->brand_id === (int) $targetBrand->id) {
            return $asset;
        }
        $conflict = $this->guard->forAssetMove($asset, $targetBrand);
        if ($conflict !== null) {
            if (! $actor->hasRole(Roles::ADMIN)) {
                throw ValidationException::withMessages(['brand_id' => $conflict->plainMessage().' Yetki devrini yalnız Admin yapabilir.']);
            }
            if (! $confirmed) {
                throw ValidationException::withMessages(['brand_id' => $conflict->errorMessage()]);
            }
        }

        return DB::transaction(function () use ($asset, $targetBrand, $actor, $conflict, $note): DigitalAsset {
            $asset->forceFill(['brand_id' => $targetBrand->id])->save();
            if ($conflict !== null) {
                // The asset takes its accounts to the other customer: their mapping follows the new owner too.
                $resourceIds = $asset->assetBindings()->where('status', CoreAssetBinding::STATUS_ACTIVE)
                    ->pluck('external_resource_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
                $mapping = $this->rescopeMappings($resourceIds, false, (int) $targetBrand->id);
                $this->record($conflict, $actor, $note, [], $mapping !== [] ? ['mapping' => $mapping] : []);
            }

            return $asset->fresh(['brand.customer']) ?? $asset;
        });
    }

    /**
     * The account's sector belongs to the owner's business.
     * - Another customer: an AI / brand-derived sector is cleared so the query pipeline assigns it again for the new
     *   owner (an operator-set sector is kept: manual wins); the automation is due now and a parked portfolio gate
     *   is lifted.
     * - Same customer: nothing changes.
     *
     * @param  list<int>  $resourceIds
     * @return list<array<string, mixed>> what was reset, kept on the transfer record (snapshot.mapping)
     */
    public function rescopeMappings(array $resourceIds, bool $sameCustomer, ?int $targetBrandId): array
    {
        if ($resourceIds === [] || $sameCustomer) {
            return [];
        }
        $log = [];
        foreach ($resourceIds as $resourceId) {
            $sector = DB::table('asset_sectors')->where('subject_type', AssetSectorService::RESOURCE)->where('subject_id', $resourceId)->first();
            $entry = ['resource_id' => $resourceId, 'action' => 'reset'];
            if ($sector !== null && $sector->method !== AssetSectorService::MANUAL) {
                $entry['cleared'] = ['sector_id' => $sector->service_category_id, 'method' => $sector->method];
                DB::table('asset_sectors')->where('id', $sector->id)->update([
                    'service_category_id' => null, 'method' => AssetSectorService::NONE, 'confidence' => null, 'signals_hash' => null, 'updated_at' => now(),
                ]);
            } elseif ($sector !== null) {
                $entry['kept'] = ['sector_id' => $sector->service_category_id, 'method' => $sector->method];
            }
            $automation = ResourceAutomation::query()->where('external_resource_id', $resourceId)->first();
            if ($automation !== null) {
                $gated = in_array($automation->collection_error, ['unbound', 'customer_passive'], true);
                $automation->forceFill([
                    'revision' => (int) $automation->revision + 1,
                    'next_collection_at' => now(),
                    'collection_error' => $gated ? null : $automation->collection_error,
                    'collection_status' => $gated ? 'waiting' : $automation->collection_status,
                ])->save();
            }
            $log[] = $entry;
        }

        return $log;
    }

    /** @param  array<string, mixed>  $columns  @param  array<string, mixed>  $snapshot */
    private function record(OwnershipConflict $conflict, User $actor, ?string $note, array $columns, array $snapshot): OwnershipTransfer
    {
        $transfer = OwnershipTransfer::query()->create(array_merge([
            'subject_type' => $conflict->subjectType,
            'subject_id' => $conflict->subjectId,
            'from_customer_id' => $conflict->currentCustomerId,
            'from_brand_id' => $conflict->currentBrandId,
            'from_asset_id' => $conflict->currentAssetId,
            'to_customer_id' => $conflict->targetCustomerId,
            'to_brand_id' => $conflict->targetBrandId,
            'to_asset_id' => $conflict->targetAssetId,
            'transferred_by' => $actor->id,
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'snapshot' => array_merge($conflict->snapshot(), $snapshot),
        ], $columns));

        Log::info('ownership.transferred', [
            'transfer_id' => $transfer->id,
            'subject_type' => $transfer->subject_type,
            'subject_id' => $transfer->subject_id,
            'from_customer_id' => $transfer->from_customer_id,
            'to_customer_id' => $transfer->to_customer_id,
            'from_asset_id' => $transfer->from_asset_id,
            'to_asset_id' => $transfer->to_asset_id,
            'user_id' => $actor->id,
        ]);

        return $transfer;
    }

    private function assertAllowed(User $actor, bool $confirmed): void
    {
        if (! $actor->hasRole(Roles::ADMIN)) {
            throw ValidationException::withMessages(['authorization' => 'Yetki devrini yalnız Admin yapabilir.']);
        }
        if (! $confirmed) {
            throw ValidationException::withMessages(['confirmation' => 'Devretmek için onaylayın.']);
        }
    }
}
