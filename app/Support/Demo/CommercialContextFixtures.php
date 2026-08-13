<?php

namespace App\Support\Demo;

use App\Support\Options\AgencyServiceOptions;

/**
 * Demo-only commercial context: service scope and structured goals derived from portfolio fixtures.
 *
 * Future persistence may need normalized Goal / ServicePlan tables; this is Demo presentation only.
 */
final class CommercialContextFixtures
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function serviceScopeForCustomer(string $customerId): array
    {
        if ($customerId !== DemoCatalog::CUSTOMER_ID) {
            return [];
        }

        $team = collect(DemoCatalog::teamMembers())->keyBy('id');

        return [
            [
                'service_code' => 'google_ads',
                'service_label' => AgencyServiceOptions::label('google_ads'),
                'status' => 'active',
                'applies_to_brand_ids' => [DemoCatalog::BRAND_ID],
                'applies_to_brand_names' => [DemoCatalog::brand()['name']],
                'owner_id' => 'u-mert',
                'owner_name' => 'Yakup',
                'review_cadence' => 'weekly',
                'reporting_cadence' => 'monthly',
                'started_at' => '2026-03-01',
                'in_scope' => ['campaign monitoring', 'efficiency review', 'landing alignment'],
                'out_of_scope' => ['daily social posting'],
            ],
            [
                'service_code' => 'seo',
                'service_label' => 'SEO / Organic Growth',
                'status' => 'active',
                'applies_to_brand_ids' => [DemoCatalog::BRAND_ID],
                'applies_to_brand_names' => [DemoCatalog::brand()['name']],
                'owner_id' => 'u-selin',
                'owner_name' => $team['u-selin']['name'] ?? 'Selin Kaya',
                'review_cadence' => 'monthly',
                'reporting_cadence' => 'monthly',
                'started_at' => '2024-03-01',
                'in_scope' => ['technical SEO', 'GSC review', 'content coverage'],
                'out_of_scope' => ['daily social publishing'],
            ],
            [
                'service_code' => 'meta_ads',
                'service_label' => AgencyServiceOptions::label('meta_ads'),
                'status' => 'active',
                'applies_to_brand_ids' => [DemoCatalog::BRAND_ID],
                'applies_to_brand_names' => [DemoCatalog::brand()['name']],
                'owner_id' => 'u-ayse',
                'owner_name' => $team['u-ayse']['name'] ?? 'Ayşe Demir',
                'review_cadence' => 'weekly',
                'reporting_cadence' => 'monthly',
                'started_at' => '2024-03-01',
                'in_scope' => ['campaign monitoring', 'creative performance', 'lead measurement'],
                'out_of_scope' => ['organic Instagram posting', 'community management'],
            ],
            [
                'service_code' => 'website_maintenance',
                'service_label' => AgencyServiceOptions::label('website_maintenance'),
                'status' => 'active',
                'applies_to_brand_ids' => [DemoCatalog::BRAND_ID],
                'applies_to_brand_names' => [DemoCatalog::brand()['name']],
                'owner_id' => 'u-can',
                'owner_name' => $team['u-can']['name'] ?? 'Can Öztürk',
                'review_cadence' => 'monthly',
                'reporting_cadence' => 'monthly',
                'started_at' => '2024-03-01',
                'in_scope' => ['site health', 'content updates', 'performance monitoring'],
                'out_of_scope' => ['new site builds'],
            ],
            [
                'service_code' => 'local_seo',
                'service_label' => AgencyServiceOptions::label('local_seo'),
                'status' => 'active',
                'applies_to_brand_ids' => [DemoCatalog::BRAND_ID],
                'applies_to_brand_names' => [DemoCatalog::brand()['name']],
                'owner_id' => 'u-selin',
                'owner_name' => $team['u-selin']['name'] ?? 'Selin Kaya',
                'review_cadence' => 'monthly',
                'reporting_cadence' => 'monthly',
                'started_at' => '2024-06-01',
                'in_scope' => ['GBP optimization', 'local map visibility', 'review monitoring'],
                'out_of_scope' => ['paid local ads'],
            ],
            [
                'service_code' => 'instagram_management',
                'service_label' => 'Instagram management',
                'status' => 'outside_scope',
                'applies_to_brand_ids' => [DemoCatalog::BRAND_ID],
                'applies_to_brand_names' => [DemoCatalog::brand()['name']],
                'owner_id' => null,
                'owner_name' => null,
                'review_cadence' => null,
                'reporting_cadence' => null,
                'started_at' => null,
                'in_scope' => [],
                'out_of_scope' => ['Instagram asset exists but no Instagram management service'],
                'note' => 'Outside current agency scope',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function effectiveScopeForBrand(string $brandId): array
    {
        $customerId = self::customerIdForBrand($brandId);
        if ($customerId === null) {
            return [];
        }

        return collect(self::serviceScopeForCustomer($customerId))
            ->filter(function (array $row) use ($brandId): bool {
                $brandIds = $row['applies_to_brand_ids'] ?? [];

                return in_array($brandId, $brandIds, true);
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function scopeForAssetType(string $assetType): ?array
    {
        if ($assetType === 'instagram') {
            return null;
        }

        $map = [
            'google_ads' => 'google_ads',
            'gsc' => 'seo',
            'website' => 'website_maintenance',
            'meta_ads' => 'meta_ads',
            'gbp' => 'local_seo',
            'ga4' => 'website_maintenance',
        ];

        $serviceCode = $map[$assetType] ?? null;
        if ($serviceCode === null) {
            return null;
        }

        return collect(self::serviceScopeForCustomer(DemoCatalog::CUSTOMER_ID))
            ->firstWhere('service_code', $serviceCode);
    }

    /**
     * Structured goals built from Brand Business Context — not persisted separately.
     *
     * @return list<array<string, mixed>>
     */
    public static function structuredGoalsForBrand(string $brandId): array
    {
        if ($brandId !== DemoCatalog::BRAND_ID) {
            return [];
        }

        $context = DemoState::brandBusinessContext($brandId) ?? DemoCatalog::brandBusinessContext();
        $businessGoals = array_values($context['business_goals'] ?? []);

        $primaryTitle = $businessGoals[0] ?? 'Increase qualified implant consultations';
        $secondaryTitle = 'Grow international patient acquisition';
        $supportingTitle = $businessGoals[1] ?? 'Improve local map visibility for implant demand';

        if (isset($businessGoals[1]) && str_contains(mb_strtolower($businessGoals[1]), 'local map')) {
            $supportingTitle = $businessGoals[1];
        }

        return [
            [
                'id' => 'goal-primary',
                'tier' => 'primary',
                'title' => $primaryTitle,
                'offering' => 'Dental Implants',
                'market' => 'TR + DE',
                'audience' => 'International implant seekers',
                'success_signal' => 'Qualified consultations',
                'target' => '40 / month',
                'target_note' => null,
                'services' => ['google_ads', 'seo', 'website_maintenance'],
                'assets' => ['website', 'google_ads', 'ga4', 'gsc'],
                'status' => 'active',
            ],
            [
                'id' => 'goal-secondary',
                'tier' => 'secondary',
                'title' => $secondaryTitle,
                'offering' => 'Dental implants · Medical travel',
                'market' => 'DE · GB',
                'audience' => 'EU medical-travel patients',
                'success_signal' => 'International consultation requests',
                'target' => null,
                'target_note' => 'No numeric target configured',
                'services' => ['google_ads', 'meta_ads', 'seo'],
                'assets' => ['google_ads', 'meta_ads', 'website', 'ga4'],
                'status' => 'active',
            ],
            [
                'id' => 'goal-supporting',
                'tier' => 'supporting',
                'title' => $supportingTitle,
                'offering' => 'Dental Implants',
                'market' => 'TR · Ankara',
                'audience' => 'Local implant demand',
                'success_signal' => 'Map visibility · GBP actions',
                'target' => null,
                'target_note' => 'No numeric target configured',
                'services' => ['local_seo'],
                'assets' => ['gbp', 'gsc'],
                'status' => 'active',
            ],
        ];
    }

    private static function customerIdForBrand(string $brandId): ?string
    {
        if ($brandId === DemoCatalog::BRAND_ID) {
            return DemoCatalog::CUSTOMER_ID;
        }

        $brand = DemoState::findBrand($brandId);

        return is_array($brand) ? ($brand['customer_id'] ?? null) : null;
    }
}
