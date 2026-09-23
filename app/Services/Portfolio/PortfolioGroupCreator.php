<?php

namespace App\Services\Portfolio;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Brand;
use App\Models\BrandSetupProposal;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\BrandSetup\BrandSetupApplier;
use App\Services\BrandSetup\BrandSetupMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates (or reuses) the customer and brand for one "Keşfet ve Grupla" group, then applies the selected
 * accounts through the "Otomatik kur" applier: website asset, GA4 / Search Console bound to the website,
 * new Google Ads / Meta / Business Profile assets. Services are left to "Otomatik kur" (site crawl is queued).
 */
final class PortfolioGroupCreator
{
    public function __construct(private readonly BrandSetupApplier $applier) {}

    /**
     * @param  array{customer_id?: ?int, customer_name?: ?string, brand_id?: ?int, brand_name?: ?string, website_url?: ?string}  $input
     * @param  list<int>  $resourceIds
     * @return array{brand: Brand, results: list<array{key: string, label: string, ok: bool, message: string}>}
     */
    public function create(array $input, array $resourceIds, User $actor): array
    {
        $resources = CoreExternalResource::query()->whereIn('id', $resourceIds)
            ->whereIn('resource_type', PortfolioDiscoveryGrouper::TYPES)->get();
        if ($resources->isEmpty()) {
            throw ValidationException::withMessages(['resources' => 'En az bir hesap seçin.']);
        }

        $brand = DB::transaction(fn (): Brand => $this->brand($input));
        $websiteUrl = trim((string) ($input['website_url'] ?? ''));
        $host = BrandSetupMatcher::host($websiteUrl);
        $website = $host === '' ? null : $brand->digitalAssets()->where('type', 'website')->get()
            ->first(fn (DigitalAsset $asset): bool => BrandSetupMatcher::host((string) ($asset->primary_url ?: $asset->domain)) === $host);

        $items = [];
        if ($host !== '') {
            $items[] = [
                'key' => 'asset:website', 'kind' => 'asset', 'group' => 'website', 'label' => 'Web sitesi: '.$host,
                'asset_id' => $website?->id, 'url' => 'https://'.$host.'/', 'status' => $website !== null ? 'already' : 'proposed',
            ];
        }
        foreach ($resources as $resource) {
            $type = (string) $resource->resource_type;
            $items[] = [
                'key' => $type.':'.$resource->id, 'kind' => 'bind', 'group' => $type, 'capability' => $type,
                'resource_id' => $resource->id, 'label' => $resource->display_name.' ('.$resource->external_id.')',
                'target' => in_array($type, ['search_console', 'ga4'], true) ? 'website' : 'new:'.$type,
                'status' => 'proposed',
            ];
        }

        $proposal = BrandSetupProposal::query()->create([
            'brand_id' => $brand->id,
            'status' => BrandSetupProposal::STATUS_READY,
            'website_url' => $host !== '' ? 'https://'.$host.'/' : null,
            'items' => $items,
            'services' => [],
            'services_status' => $host !== '' && $website === null ? 'waiting_for_site' : 'none',
            'summary' => ['source' => 'discover_and_group'],
            'created_by' => $actor->id,
        ]);

        $results = $this->applier->apply($proposal, $actor, array_column($items, 'key'), []);

        return ['brand' => $brand->fresh(), 'results' => $results];
    }

    /** @param  array<string, mixed>  $input */
    private function brand(array $input): Brand
    {
        if (! empty($input['brand_id'])) {
            return Brand::query()->findOrFail((int) $input['brand_id']);
        }

        $brandName = trim((string) ($input['brand_name'] ?? ''));
        if (mb_strlen($brandName) < 2) {
            throw ValidationException::withMessages(['brand_name' => 'Marka adı en az 2 karakter olmalı.']);
        }

        if (! empty($input['customer_id'])) {
            $customer = Customer::query()->findOrFail((int) $input['customer_id']);
        } else {
            $customerName = trim((string) ($input['customer_name'] ?? ''));
            if (mb_strlen($customerName) < 2) {
                throw ValidationException::withMessages(['customer_name' => 'Müşteri adı en az 2 karakter olmalı.']);
            }
            $customer = Customer::query()->firstOrCreate(
                ['name' => $customerName],
                ['type' => CustomerType::Company, 'status' => CustomerStatus::Active],
            );
        }

        return Brand::query()->firstOrCreate(['customer_id' => $customer->id, 'name' => $brandName]);
    }
}
