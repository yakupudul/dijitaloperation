<?php

namespace App\Services\Ownership;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\OwnershipTransfer;
use App\Models\User;
use App\Services\Integrations\ConfirmGoogleResourceBindingService;
use App\Services\Integrations\ConfirmMetaResourceBindingService;
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

        return $this->record($conflict, $actor, $note, [
            'to_customer_id' => $target->brand?->customer_id,
            'to_brand_id' => $target->brand_id,
            'to_asset_id' => (int) $target->id,
        ], ['to_asset' => (string) $target->name]);
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
                $this->record($conflict, $actor, $note, [], []);
            }

            return $asset->fresh(['brand.customer']) ?? $asset;
        });
    }

    /** @param  array<string, mixed>  $columns  @param  array<string, string|null>  $snapshot */
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
