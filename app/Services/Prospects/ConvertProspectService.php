<?php

namespace App\Services\Prospects;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\DigitalAsset;
use App\Models\Prospect;
use App\Models\ProspectDiscoveryCandidate;
use App\Models\User;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Support\DigitalAssetTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConvertProspectService
{
    public function __construct(
        private readonly ProspectDuplicateDetector $duplicates = new ProspectDuplicateDetector,
        private readonly ProspectActivityRecorder $activities = new ProspectActivityRecorder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(Prospect $prospect): array
    {
        $prospect->loadMissing('discoveryCandidates');
        $duplicates = $this->duplicates->find($prospect);

        return [
            'prospect_id' => $prospect->id,
            'company_name' => $prospect->company_name,
            'customer_name' => $prospect->company_name,
            'brand_name' => $prospect->company_name,
            'country' => $prospect->country,
            'city' => $prospect->city,
            'website_url' => $prospect->website_url,
            'owner_user_id' => $prospect->owner_user_id,
            'contact_name' => $prospect->contact_name,
            'contact_email' => $prospect->contact_email,
            'contact_phone' => $prospect->contact_phone,
            'already_converted' => $prospect->converted_customer_id !== null,
            'converted_customer_id' => $prospect->converted_customer_id,
            'converted_brand_id' => $prospect->converted_brand_id,
            'duplicates' => $duplicates,
            'promotable_assets' => $this->promotableAssets($prospect),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function convert(Prospect $prospect, array $input, User $actor): Prospect
    {
        if ($prospect->converted_customer_id !== null && $prospect->converted_brand_id !== null) {
            return $prospect->fresh(['convertedCustomer', 'convertedBrand']) ?? $prospect;
        }

        $customerName = trim((string) ($input['customer_name'] ?? $prospect->company_name));
        $brandName = trim((string) ($input['brand_name'] ?? $prospect->company_name));
        if (strlen($customerName) < 2 || strlen($brandName) < 2) {
            throw ValidationException::withMessages([
                'customer_name' => [__('operator.prospects.conversion.name_required')],
            ]);
        }

        $existingCustomerId = isset($input['existing_customer_id']) && $input['existing_customer_id'] !== ''
            ? (int) $input['existing_customer_id']
            : null;
        $existingBrandId = isset($input['existing_brand_id']) && $input['existing_brand_id'] !== ''
            ? (int) $input['existing_brand_id']
            : null;
        if ($existingBrandId !== null && $existingCustomerId === null) {
            $existingBrand = Brand::query()->find($existingBrandId);
            if ($existingBrand instanceof Brand) {
                $existingCustomerId = $existingBrand->customer_id;
            }
        }
        $forceCreate = (bool) ($input['confirm_create_despite_duplicates'] ?? false);

        $duplicates = $this->duplicates->find($prospect);
        $hasDuplicates = $duplicates['customers'] !== [] || $duplicates['brands'] !== [] || $duplicates['digital_assets'] !== [];
        if ($hasDuplicates && $existingCustomerId === null && $existingBrandId === null && ! $forceCreate) {
            throw ValidationException::withMessages([
                'duplicates' => [__('operator.prospects.conversion.duplicate_requires_choice')],
            ]);
        }

        $rawSelected = $input['selected_assets'] ?? [];
        $selectedAssets = array_values(array_filter(
            is_array($rawSelected) ? $rawSelected : [],
            'is_string',
        ));
        $promoteSummary = (bool) ($input['promote_observed_summary'] ?? false);

        $prospect->loadMissing('discoveryCandidates');

        $converted = DB::transaction(function () use (
            $prospect,
            $actor,
            $customerName,
            $brandName,
            $existingCustomerId,
            $existingBrandId,
            $selectedAssets,
            $promoteSummary,
        ): Prospect {
            $customer = $this->resolveCustomer($prospect, $customerName, $existingCustomerId);
            $brand = $this->resolveBrand($prospect, $customer, $brandName, $existingBrandId);

            if ($prospect->owner_user_id) {
                $customer->responsibleUsers()->syncWithoutDetaching([$prospect->owner_user_id]);
                $brand->responsibleUsers()->syncWithoutDetaching([$prospect->owner_user_id]);
            }

            $this->maybeCreateContact($prospect, $customer);
            $this->promoteAssets($prospect, $brand, $selectedAssets);
            if ($promoteSummary) {
                $this->promoteObservedSummary($prospect, $brand, $actor);
            }

            $prospect->converted_customer_id = $customer->id;
            $prospect->converted_brand_id = $brand->id;
            $prospect->converted_at = now();
            $prospect->save();

            return $prospect->fresh(['convertedCustomer', 'convertedBrand']) ?? $prospect;
        });

        $this->activities->record(
            $converted,
            'prospect.converted',
            __('operator.prospects.activity.converted'),
            $converted->company_name,
            $actor,
            [
                'customer_id' => $converted->converted_customer_id,
                'brand_id' => $converted->converted_brand_id,
            ],
        );

        return $converted;
    }

    /**
     * @return list<array{key: string, type: string, label: string, url: string, supported: bool}>
     */
    public function promotableAssets(Prospect $prospect): array
    {
        $items = [];

        if (is_string($prospect->website_url) && $prospect->website_url !== '') {
            $items[] = [
                'key' => 'website:'.$prospect->website_url,
                'type' => 'website',
                'label' => 'Website',
                'url' => $prospect->website_url,
                'supported' => true,
            ];
        }

        foreach ($prospect->discoveryCandidates as $candidate) {
            if ($candidate->candidate_type !== 'social_links') {
                continue;
            }

            $url = trim($candidate->proposed_value);
            $type = $this->socialAssetType($url);
            if ($type === null) {
                continue;
            }

            $items[] = [
                'key' => $type.':'.$url,
                'type' => $type,
                'label' => DigitalAssetTypes::options()[$type] ?? $type,
                'url' => $url,
                'supported' => array_key_exists($type, DigitalAssetTypes::options()),
            ];
        }

        return $items;
    }

    private function resolveCustomer(Prospect $prospect, string $customerName, ?int $existingId): Customer
    {
        if ($existingId !== null) {
            return Customer::query()->findOrFail($existingId);
        }

        return Customer::query()->create([
            'name' => $customerName,
            'legal_name' => $customerName,
            'type' => CustomerType::Company,
            'status' => CustomerStatus::Active,
            'hq_country' => $prospect->country,
            'hq_city' => $prospect->city,
            'primary_email' => $prospect->contact_email,
            'primary_phone' => $prospect->contact_phone,
        ]);
    }

    private function resolveBrand(Prospect $prospect, Customer $customer, string $brandName, ?int $existingId): Brand
    {
        if ($existingId !== null) {
            $brand = Brand::query()->findOrFail($existingId);
            if ($brand->customer_id !== $customer->id) {
                throw ValidationException::withMessages([
                    'existing_brand_id' => [__('operator.prospects.conversion.brand_customer_mismatch')],
                ]);
            }

            return $brand;
        }

        return Brand::query()->create([
            'customer_id' => $customer->id,
            'name' => $brandName,
            'primary_country' => $prospect->country,
            'target_markets' => $prospect->country ? [$prospect->country] : [],
        ]);
    }

    private function maybeCreateContact(Prospect $prospect, Customer $customer): void
    {
        if ($prospect->contact_name === null && $prospect->contact_email === null && $prospect->contact_phone === null) {
            return;
        }

        CustomerContact::query()->create([
            'customer_id' => $customer->id,
            'name' => $prospect->contact_name ?: $prospect->company_name,
            'email' => $prospect->contact_email,
            'phone' => $prospect->contact_phone,
        ]);
    }

    /**
     * @param  list<string>  $selectedKeys
     */
    private function promoteAssets(Prospect $prospect, Brand $brand, array $selectedKeys): void
    {
        foreach ($this->promotableAssets($prospect) as $item) {
            if (! $item['supported'] || ! in_array($item['key'], $selectedKeys, true)) {
                continue;
            }

            $type = $item['type'];
            $url = $item['url'];
            $domain = ProspectDuplicateDetector::normalizeDomain($url);

            $exists = DigitalAsset::query()
                ->where('brand_id', $brand->id)
                ->where('type', $type)
                ->get()
                ->contains(function (DigitalAsset $asset) use ($domain, $url): bool {
                    return $asset->primary_url === $url
                        || ProspectDuplicateDetector::normalizeDomain($asset->primary_url) === $domain
                        || ProspectDuplicateDetector::normalizeDomain($asset->domain) === $domain;
                });

            if ($exists) {
                continue;
            }

            DigitalAsset::query()->create([
                'brand_id' => $brand->id,
                'name' => $prospect->company_name.' '.($item['label']),
                'type' => $type,
                'status' => DigitalAssetStatus::Active,
                'module_id' => OperatorPortfolioPresenter::derivedModuleId($type),
                'domain' => $type === 'website' ? $domain : null,
                'primary_url' => $url,
            ]);
        }
    }

    private function promoteObservedSummary(Prospect $prospect, Brand $brand, User $actor): void
    {
        $summary = $prospect->latestSalesIntelligence?->summary;
        if (! is_string($summary) || trim($summary) === '') {
            $candidate = $prospect->discoveryCandidates
                ->first(fn (ProspectDiscoveryCandidate $row): bool => in_array($row->candidate_type, ['business_summary', 'title'], true));
            $summary = $candidate?->proposed_value;
        }

        if (! is_string($summary) || trim($summary) === '') {
            return;
        }

        $existing = BrandIntelligenceContext::query()->where('brand_id', $brand->id)->first();
        if ($existing instanceof BrandIntelligenceContext) {
            return;
        }

        BrandIntelligenceContext::query()->create([
            'brand_id' => $brand->id,
            'business_summary' => trim($summary),
            'source' => BrandIntelligenceContext::SOURCE_PUBLIC_DISCOVERY,
            'updated_by' => $actor->id,
        ]);
    }

    private function socialAssetType(string $url): ?string
    {
        $host = ProspectDuplicateDetector::normalizeDomain($url);
        if ($host === null) {
            return null;
        }

        return match (true) {
            str_contains($host, 'instagram.com') => 'instagram',
            str_contains($host, 'facebook.com') => 'facebook',
            str_contains($host, 'linkedin.com') => 'linkedin',
            str_contains($host, 'youtube.com'), str_contains($host, 'youtu.be') => 'youtube',
            default => null,
        };
    }
}
