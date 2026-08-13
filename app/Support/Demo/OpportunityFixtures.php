<?php

namespace App\Support\Demo;

/**
 * Canonical Demo Opportunity records — shared across Global, Brand Growth, and specialist cards.
 */
final class OpportunityFixtures
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $brand = DemoCatalog::brand();
        $customer = DemoCatalog::customer();

        return [
            [
                'id' => 'opp-implant-organic-gap',
                'title' => 'High paid implant demand but weak organic coverage',
                'brand_id' => DemoCatalog::BRAND_ID,
                'brand_name' => $brand['name'],
                'customer_id' => DemoCatalog::CUSTOMER_ID,
                'customer_name' => $customer['name'],
                'category' => 'cross_channel',
                'status' => 'open',
                'service_code' => 'seo',
                'service_label' => 'SEO / Organic Growth',
                'goal_id' => 'goal-primary',
                'goal_title' => 'Increase qualified implant consultations',
                'offering' => 'Dental Implants',
                'market' => 'TR + DE',
                'audience' => 'International implant seekers',
                'asset_ids' => [DemoCatalog::GOOGLE_ADS_ASSET_ID, DemoCatalog::GSC_ASSET_ID, DemoCatalog::WEBSITE_ASSET_ID, DemoCatalog::GA4_ASSET_ID],
                'asset_types' => ['google_ads', 'gsc', 'website', 'ga4'],
                'evidence' => [
                    ['source' => 'Google Ads', 'provenance' => 'Deterministic', 'summary' => 'Implant campaign search demand up 18% vs prior period'],
                    ['source' => 'Search Console', 'provenance' => 'Deterministic', 'summary' => 'Implant landing URLs rank outside top 10 for priority queries'],
                    ['source' => 'Website', 'provenance' => 'Deterministic', 'summary' => 'Implant service pages thin vs paid landing depth'],
                ],
                'why_matters' => ['Primary Goal', 'Active Service', 'Priority Offering', 'Fresh multi-source Evidence'],
                'what' => 'Paid channels show strong implant demand while organic search coverage for the same offering lags.',
                'why' => 'Organic gap increases paid dependency and raises cost per qualified consultation.',
                'known' => 'Google Ads implant campaigns converting; GSC shows impression growth without click share.',
                'unknown' => 'Competitor content depth on DE-language implant queries not fully benchmarked.',
                'observed_at' => '2 days ago',
                'recommendation_id' => null,
                'is_new' => true,
                'ai_assisted' => false,
            ],
            [
                'id' => 'opp-content-coverage',
                'title' => 'Content coverage gap for priority offering',
                'brand_id' => DemoCatalog::BRAND_ID,
                'brand_name' => $brand['name'],
                'customer_id' => DemoCatalog::CUSTOMER_ID,
                'customer_name' => $customer['name'],
                'category' => 'content',
                'status' => 'open',
                'service_code' => 'seo',
                'service_label' => 'SEO / Organic Growth',
                'goal_id' => 'goal-primary',
                'goal_title' => 'Increase qualified implant consultations',
                'offering' => 'Dental Implants',
                'market' => 'TR + DE',
                'audience' => 'International implant seekers',
                'asset_ids' => [DemoCatalog::WEBSITE_ASSET_ID, DemoCatalog::GSC_ASSET_ID],
                'asset_types' => ['website', 'gsc'],
                'evidence' => [
                    ['source' => 'Website', 'provenance' => 'Deterministic', 'summary' => 'Only 3 implant-related URLs vs 12 competitor benchmark pages'],
                    ['source' => 'Search Console', 'provenance' => 'Deterministic', 'summary' => 'Long-tail implant queries with zero matching landing page'],
                ],
                'why_matters' => ['Primary Goal', 'Active Service', 'Priority Offering', 'Fresh multi-source Evidence'],
                'what' => 'Website lacks depth on implant patient journey content for priority markets.',
                'why' => 'Thin content limits organic capture and weakens landing alignment for paid traffic.',
                'known' => 'Core implant service page exists; DE-language variant is shallow.',
                'unknown' => 'Clinical review timeline for new implant FAQ content.',
                'observed_at' => '4 days ago',
                'recommendation_id' => null,
                'is_new' => false,
                'ai_assisted' => false,
            ],
            [
                'id' => 'opp-meta-creative-angle',
                'title' => 'Meta creative angle underrepresented for implant demand',
                'brand_id' => DemoCatalog::BRAND_ID,
                'brand_name' => $brand['name'],
                'customer_id' => DemoCatalog::CUSTOMER_ID,
                'customer_name' => $customer['name'],
                'category' => 'creative',
                'status' => 'open',
                'service_code' => 'meta_ads',
                'service_label' => 'Meta Ads Management',
                'goal_id' => 'goal-secondary',
                'goal_title' => 'Grow international patient acquisition',
                'offering' => 'Dental Implants',
                'market' => 'DE · GB',
                'audience' => 'EU medical-travel patients',
                'asset_ids' => [DemoCatalog::META_ASSET_ID],
                'asset_types' => ['meta_ads'],
                'evidence' => [
                    ['source' => 'Meta Ads', 'provenance' => 'Deterministic', 'summary' => 'Implant-focused creatives account for <15% of spend share'],
                    ['source' => 'Meta Ads', 'provenance' => 'Deterministic', 'summary' => 'Post-bariatric creative fatigue while implant CPL remains efficient'],
                ],
                'why_matters' => ['Active Service', 'Priority Offering', 'Fresh multi-source Evidence'],
                'what' => 'Creative mix over-indexes on post-bariatric angles vs implant medical-travel demand.',
                'why' => 'Underrepresented implant angles may cap international acquisition at current spend levels.',
                'known' => 'Implant lead forms convert when shown; frequency on legacy creatives rising.',
                'unknown' => 'Available implant patient testimonial assets for DE market.',
                'observed_at' => '3 days ago',
                'recommendation_id' => null,
                'is_new' => true,
                'ai_assisted' => false,
            ],
            [
                'id' => 'opp-gbp-local-gap',
                'title' => 'GBP priority offering local representation gap',
                'brand_id' => DemoCatalog::BRAND_ID,
                'brand_name' => $brand['name'],
                'customer_id' => DemoCatalog::CUSTOMER_ID,
                'customer_name' => $customer['name'],
                'category' => 'local',
                'status' => 'open',
                'service_code' => 'local_seo',
                'service_label' => 'Local SEO / Google Business Profile',
                'goal_id' => 'goal-supporting',
                'goal_title' => 'Improve local map visibility for implant demand',
                'offering' => 'Dental Implants',
                'market' => 'TR · Ankara',
                'audience' => 'Local implant demand',
                'asset_ids' => [DemoCatalog::GBP_ASSET_ID],
                'asset_types' => ['gbp'],
                'evidence' => [
                    ['source' => 'GBP', 'provenance' => 'Deterministic', 'summary' => 'Implant services underrepresented in GBP categories vs competitors'],
                    ['source' => 'GBP', 'provenance' => 'Deterministic', 'summary' => 'Map pack visibility lower for “implant Ankara” vs clinic name queries'],
                ],
                'why_matters' => ['Primary Goal', 'Active Service', 'Priority Offering', 'Fresh multi-source Evidence'],
                'what' => 'GBP profile emphasizes general dentistry over implant specialty for local discovery.',
                'why' => 'Local map visibility gap reduces qualified local consultation paths.',
                'known' => 'Profile is verified and active; photos skew general dentistry.',
                'unknown' => 'Clinic approval for implant-specific GBP service items.',
                'observed_at' => '5 days ago',
                'recommendation_id' => null,
                'is_new' => false,
                'ai_assisted' => false,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function forBrand(string $brandId): array
    {
        return collect(self::all())
            ->filter(fn (array $row): bool => ($row['brand_id'] ?? '') === $brandId)
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function forAssetType(string $assetType): array
    {
        return collect(self::all())
            ->filter(fn (array $row): bool => in_array($assetType, $row['asset_types'] ?? [], true))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $id): ?array
    {
        $row = collect(self::all())->firstWhere('id', $id);

        return is_array($row) ? $row : null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{open: int, new: int, linked_primary: int, converted: int}
     */
    public static function glance(array $rows): array
    {
        $collection = collect($rows);

        return [
            'open' => $collection->where('status', 'open')->count(),
            'new' => $collection->where('is_new', true)->whereIn('status', ['open', 'reviewing'])->count(),
            'linked_primary' => $collection->where('goal_id', 'goal-primary')->whereIn('status', ['open', 'reviewing'])->count(),
            'converted' => $collection->where('status', 'converted')->count(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function sortByBusinessRelevance(array $rows): array
    {
        $tierWeight = static fn (array $row): int => match ($row['goal_id'] ?? '') {
            'goal-primary' => 0,
            'goal-secondary' => 1,
            'goal-supporting' => 2,
            default => 3,
        };

        $statusWeight = static fn (array $row): int => match ($row['status'] ?? '') {
            'open' => 0,
            'reviewing' => 1,
            'deferred' => 2,
            'converted' => 3,
            'dismissed' => 4,
            default => 5,
        };

        return collect($rows)
            ->sortBy([
                fn (array $a, array $b): int => $statusWeight($a) <=> $statusWeight($b),
                fn (array $a, array $b): int => $tierWeight($a) <=> $tierWeight($b),
                fn (array $a, array $b): int => (($b['is_new'] ?? false) ? 1 : 0) <=> (($a['is_new'] ?? false) ? 1 : 0),
            ])
            ->values()
            ->all();
    }
}
