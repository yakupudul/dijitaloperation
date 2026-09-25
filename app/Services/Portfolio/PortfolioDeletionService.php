<?php

namespace App\Services\Portfolio;

use App\Enums\Security\SecurityAuditEventKind;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Security\SecurityAuditRecorder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Removes customers or brands from the portfolio WITHOUT deleting collected data. Admin-only; the caller confirms first.
 *
 * What happens, in one transaction per entity:
 *  - The customer (with its brands) or the brand, and the digital assets under them, are archived (soft delete): they
 *    disappear from every list and screen.
 *  - The active account bindings of those assets are disabled. An unbound account is not collected any more
 *    (ResourceAutomationService::portfolioGate), so collection stops.
 *  - Nothing else is touched. Collected rows are keyed by the external account, so when that account is bound again to a
 *    brand's asset, collection resumes on its own and the history is visible again.
 */
final class PortfolioDeletionService
{
    public const string CLOSED_REASON = 'portföyden silindi';

    public function __construct(private readonly SecurityAuditRecorder $audit) {}

    /**
     * @param  list<int>  $customerIds
     * @return array{deleted: int, skipped: int}
     */
    public function deleteCustomers(array $customerIds, ?User $actor = null): array
    {
        $customers = Customer::query()->whereIn('id', $customerIds)->get();
        $deleted = 0;
        $skipped = 0;
        foreach ($customers as $customer) {
            $brandIds = Brand::query()->where('customer_id', $customer->id)->pluck('id')->map('intval')->all();
            $this->archive($actor, $brandIds, static function () use ($customer): void {
                Brand::query()->where('customer_id', $customer->id)->get()->each->delete();
                $customer->delete();
            }) ? $deleted++ : $skipped++;
        }
        $this->log($actor, 'customer', $customers->pluck('name', 'id')->all());

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    /**
     * @param  list<int>  $brandIds
     * @return array{deleted: int, skipped: int}
     */
    public function deleteBrands(array $brandIds, ?User $actor = null): array
    {
        $brands = Brand::query()->whereIn('id', $brandIds)->get();
        $deleted = 0;
        $skipped = 0;
        foreach ($brands as $brand) {
            $this->archive($actor, [(int) $brand->id], static function () use ($brand): void {
                $brand->delete();
            }) ? $deleted++ : $skipped++;
        }
        $this->log($actor, 'brand', $brands->pluck('name', 'id')->all());

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    /**
     * Disable the bindings of the brands' assets, archive the assets, then archive the root via $archiveRoot.
     *
     * @param  list<int>  $brandIds
     */
    private function archive(?User $actor, array $brandIds, callable $archiveRoot): bool
    {
        try {
            DB::transaction(function () use ($actor, $brandIds, $archiveRoot): void {
                $assets = $brandIds === [] ? collect() : DigitalAsset::query()->whereIn('brand_id', $brandIds)->get();
                $this->disableBindings($assets->pluck('id')->map('intval')->all(), $actor);
                $assets->each->delete();
                $archiveRoot();
            });

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /** @param  list<int>  $assetIds */
    private function disableBindings(array $assetIds, ?User $actor): void
    {
        if ($assetIds === []) {
            return;
        }
        CoreAssetBinding::query()
            ->whereIn('digital_asset_id', $assetIds)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->get()
            ->each(function (CoreAssetBinding $binding) use ($actor): void {
                $binding->forceFill([
                    'status' => CoreAssetBinding::STATUS_DISABLED,
                    'configuration' => array_merge((array) $binding->configuration, [
                        'closed_by_user_id' => $actor?->id,
                        'closed_at' => now()->toIso8601String(),
                        'closed_reason' => self::CLOSED_REASON,
                    ]),
                ])->save();
            });
    }

    /** @param  array<int, string>  $names */
    private function log(?User $actor, string $type, array $names): void
    {
        if ($names === []) {
            return;
        }
        try {
            $this->audit->record(SecurityAuditEventKind::SecuritySettingChanged, $actor, null, null, null, null,
                $type === 'customer' ? 'Müşteri(ler) portföyden silindi (veriler korundu, veri çekimi durdu)' : 'Marka(lar) portföyden silindi (veriler korundu, veri çekimi durdu)',
                ['type' => $type, 'count' => count($names), 'archived' => array_slice($names, 0, 200, true)]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
