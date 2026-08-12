<?php

namespace App\Support\Demo;

/**
 * Deterministic Demo Mode fixtures for the GBP Local Intelligence workspace.
 * No live Google / Maps / Places / rank-provider calls.
 */
final class GbpWorkspaceFixtures
{
    /** Çankaya, Ankara — Atlas Dental demo location. */
    public const float BUSINESS_LAT = 39.9012;

    public const float BUSINESS_LNG = 32.8597;

    /**
     * @return array<string, mixed>
     */
    public static function workspace(string $preset = 'last_28'): array
    {
        $f = DemoCatalog::periodFactors($preset);

        return [
            'period_label' => $f['label'],
            'demo_boundary' => 'Demo Mode · product vision fixtures — no live GBP API, Maps scrape, or rank provider',
            'identity' => self::identity(),
            'glance' => self::glance($f),
            'needs_attention' => self::needsAttention(),
            'visibility_snapshot' => self::visibilitySnapshot(),
            'profile_coverage' => self::profileCoverage(),
            'customer_actions' => self::customerActions($f),
            'review_pulse' => self::reviewPulse($f),
            'website_consistency' => self::websiteConsistency(),
            'opportunities' => self::opportunities(),
            'recent_outcomes' => self::recentOutcomes(),
            'profile' => self::profile(),
            'visibility' => self::visibility(),
            'performance' => self::performance($f),
            'reviews' => self::reviews($f),
            'competitors' => self::competitors(),
            'operations' => self::operations(),
            'ai_guidance' => self::aiGuidance(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function identity(): array
    {
        return [
            'eyebrow' => 'Google Business Profile',
            'title' => 'Atlas Dental Ankara',
            'location_line' => 'Atlas Dental Ankara · Çankaya, Ankara',
            'brand_id' => DemoCatalog::BRAND_ID,
            'brand_name' => 'Atlas Dental Ankara',
            'website_asset_id' => DemoCatalog::WEBSITE_ASSET_ID,
            'status' => 'Connected',
            'freshness' => 'Profile refreshed 2h ago',
            'locale' => 'Çankaya, Ankara · Turkish',
            'lat' => self::BUSINESS_LAT,
            'lng' => self::BUSINESS_LNG,
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function glance(array $f): array
    {
        return [
            'profile' => ['value' => '14 / 17', 'label' => 'Reviewed profile fields present'],
            'visibility' => ['value' => '17 / 25', 'label' => 'Observed points in Top 3 · çankaya diş kliniği'],
            'reviews' => ['value' => '4.7 ★', 'label' => '326 reviews · 8 need attention'],
            'actions' => [
                'value' => (string) (int) round(874 * ($f['results_factor'] ?? 1)),
                'label' => 'Website + call + direction clicks · Last 30 days',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function needsAttention(): array
    {
        return [
            [
                'severity' => 'High',
                'category' => 'Website consistency',
                'problem' => 'Google Business Profile phone differs from Website contact information.',
                'context' => 'GBP +90 312 555 01 01 · Website +90 312 000 00 00',
                'why' => 'Entity inconsistency confuses customers and local discovery systems.',
                'evidence' => 'Derived · Website ↔ GBP comparison',
                'suggested' => 'Confirm the correct business phone and align controlled public sources.',
                'actionability' => 'Client input required',
                'finding_id' => 'gf-phone-mismatch',
            ],
            [
                'severity' => 'High',
                'category' => 'Visibility',
                'problem' => 'Local visibility drops outside the immediate Çankaya center for “ankara implant”.',
                'context' => '7 / 25 scan points outside Top 3',
                'why' => 'Priority Implant offering under-delivers geographically on the tracked Demo scan.',
                'evidence' => 'Demo local rank tracking · 12 Aug · 14:30',
                'suggested' => 'Inspect the Visibility map and western/southern weak points.',
                'actionability' => 'Agency can fix',
                'finding_id' => 'gf-implant-west-weak',
            ],
            [
                'severity' => 'Medium',
                'category' => 'Profile',
                'problem' => 'Implant is a priority service but is not represented clearly in the current GBP service list.',
                'context' => 'Brand Context · Priority offering · GBP services',
                'why' => 'Profile may under-signal a core offering that already has Website coverage.',
                'evidence' => 'Derived · Brand Context + GBP services',
                'suggested' => 'Review GBP service configuration (external edit remains outside MoxDOP).',
                'actionability' => 'Client input required',
                'finding_id' => 'gf-implant-service-gap',
            ],
            [
                'severity' => 'Medium',
                'category' => 'Reviews',
                'problem' => '8 recent reviews need attention; waiting-time theme is recurring.',
                'context' => 'Reviews · unanswered + negative/mixed',
                'why' => 'Unanswered friction themes undermine trust for local converters.',
                'evidence' => 'Provider reviews · Demo AI analysis',
                'suggested' => 'Open Response Queue and create internal Tasks where needed.',
                'actionability' => 'Agency can fix',
                'finding_id' => 'gf-waiting-theme',
            ],
            [
                'severity' => 'Low',
                'category' => 'Local content',
                'problem' => 'Parking/access information is unclear on the Website location page.',
                'context' => 'Review topic · Parking · Website /subeler/cankaya/',
                'why' => 'Customers mention parking; the location page may not set expectations.',
                'evidence' => 'Demo AI analysis + Website Demo',
                'suggested' => 'Review arrival information on the location page.',
                'actionability' => 'Website work required',
                'finding_id' => null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function visibilitySnapshot(): array
    {
        $scan = self::visibility()['scans']['çankaya diş kliniği']['current'];

        return [
            'keyword' => 'çankaya diş kliniği',
            'average_rank' => $scan['average_rank'],
            'top3' => $scan['top3_count'].' / 25',
            'top10' => $scan['top10_count'].' / 25',
            'weakest_area' => 'South-west of location',
            'scanned_at' => $scan['scanned_at'],
            'source' => 'Demo local rank tracking',
            'points' => $scan['points'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function profileCoverage(): array
    {
        return [
            'present' => 14,
            'need_attention' => 2,
            'unavailable' => 1,
            'total_reviewed' => 17,
            'note' => 'Coverage reflects MoxDOP\'s reviewed profile fields, not Google\'s ranking evaluation.',
            'groups' => [
                ['area' => 'Identity', 'state' => 'Present'],
                ['area' => 'Categories', 'state' => 'Needs attention'],
                ['area' => 'Services', 'state' => 'Needs attention'],
                ['area' => 'Location', 'state' => 'Present'],
                ['area' => 'Contact', 'state' => 'Needs attention'],
                ['area' => 'Hours', 'state' => 'Present'],
                ['area' => 'Website', 'state' => 'Present'],
                ['area' => 'Attributes', 'state' => 'Present'],
                ['area' => 'Media', 'state' => 'Present'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function customerActions(array $f): array
    {
        $rf = $f['results_factor'] ?? 1.0;

        return [
            'period' => 'Google Business Profile · Last 30 days · Demo',
            'search_impressions' => (int) round(18420 * $rf),
            'maps_impressions' => (int) round(12640 * $rf),
            'website_clicks' => (int) round(412 * $rf),
            'call_clicks' => (int) round(286 * $rf),
            'direction_requests' => (int) round(176 * $rf),
            'series' => DemoCatalog::trendSeries('gbp_actions_demo', 14, 40, 120),
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function reviewPulse(array $f): array
    {
        return [
            'rating' => 4.7,
            'total' => 326,
            'new' => (int) round(24 * ($f['results_factor'] ?? 1)),
            'unanswered' => 8,
            'attention' => 5,
            'positive' => ['Staff', 'Doctor communication', 'Cleanliness'],
            'needs_attention_themes' => ['Waiting time', 'Parking', 'Pricing clarity'],
            'provenance' => 'Demo AI analysis',
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    public static function websiteConsistency(): array
    {
        return [
            ['field' => 'Business name', 'state' => 'Match'],
            ['field' => 'Phone', 'state' => 'Mismatch'],
            ['field' => 'Address', 'state' => 'Match'],
            ['field' => 'Website URL', 'state' => 'Match'],
            ['field' => 'Opening hours', 'state' => 'Needs review'],
            ['field' => 'Services', 'state' => 'Partial'],
            ['field' => 'LocalBusiness data', 'state' => 'Partial'],
            ['field' => 'Location page', 'state' => 'Matched · /subeler/cankaya/'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function opportunities(): array
    {
        return [
            [
                'priority' => 'High',
                'title' => 'Local content · Çankaya implant intent',
                'why' => 'Priority offering + GBP discovery + weak western ranks + thin local Implant support content.',
                'evidence' => 'GBP + Local Rank Demo + Website Demo',
                'tab' => 'visibility',
            ],
            [
                'priority' => 'High',
                'title' => 'Profile · Implant service representation',
                'why' => 'Brand priority offering has Website coverage but GBP service list is incomplete.',
                'evidence' => 'Brand Context + GBP Profile',
                'tab' => 'profile',
            ],
            [
                'priority' => 'Medium',
                'title' => 'Customer experience · Parking expectations',
                'why' => '12 recent parking mentions; location page arrival info unclear.',
                'evidence' => 'Reviews Demo AI analysis + Website',
                'tab' => 'reviews',
            ],
            [
                'priority' => 'Explore',
                'title' => 'Search demand · acil dişçi çankaya',
                'why' => 'Growing discovery query; Website coverage weak; not currently rank-tracked.',
                'evidence' => 'Performance Search queries · Demo',
                'tab' => 'performance',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recentOutcomes(): array
    {
        return [
            [
                'title' => 'GBP phone differs from Website',
                'chain' => 'Finding → Recommendation → Task completed 6 Aug → Follow-up 8 Aug',
                'outcome' => 'Improvement observed',
                'note' => 'Website and GBP phone now match in later Evidence — not claiming causality for this Demo chain variant.',
            ],
            [
                'title' => 'Improve local Implant service coverage',
                'chain' => 'Task completed 24 Jul → Later local visibility scan',
                'outcome' => 'Improvement observed',
                'note' => '9 of 25 points improved · 4 declined · 12 stable. Observed after change.',
            ],
            [
                'title' => 'Waiting-time complaints recurring',
                'chain' => 'Task completed → Later review observation',
                'outcome' => 'Still observed',
                'note' => 'Waiting-time topic remains present in the later window.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function profile(): array
    {
        return [
            'subtitle' => 'Business information, categories, services and local entity signals.',
            'fields' => [
                ['area' => 'Business name', 'value' => 'Atlas Dental Ankara', 'state' => 'Present', 'evidence' => 'GBP · Provider', 'action' => '—'],
                ['area' => 'Primary category', 'value' => 'Dental clinic', 'state' => 'Present', 'evidence' => 'GBP · Provider', 'action' => '—'],
                ['area' => 'Additional categories', 'value' => 'Cosmetic dentist, Orthodontist', 'state' => 'Review', 'evidence' => 'GBP + Brand context', 'action' => 'Verify service relevance'],
                ['area' => 'Address', 'value' => 'Kızılırmak Mah. · Çankaya, Ankara', 'state' => 'Present', 'evidence' => 'GBP · Provider', 'action' => '—'],
                ['area' => 'Phone', 'value' => '+90 312 555 01 01', 'state' => 'Needs attention', 'evidence' => 'GBP vs Website', 'action' => 'Align with Website'],
                ['area' => 'Website', 'value' => 'https://atlasdental.example', 'state' => 'Present', 'evidence' => 'GBP · Provider', 'action' => '—'],
                ['area' => 'Hours', 'value' => 'Mon–Sat 09:00–19:00', 'state' => 'Present', 'evidence' => 'GBP · Provider', 'action' => '—'],
                ['area' => 'Description', 'value' => 'Implant and post-bariatric dental care in Çankaya.', 'state' => 'Present', 'evidence' => 'GBP · Provider', 'action' => '—'],
                ['area' => 'Attributes', 'value' => 'Wheelchair accessible · Appointment required', 'state' => 'Present', 'evidence' => 'GBP · Provider', 'action' => '—'],
                ['area' => 'Profile photo', 'value' => 'Present', 'state' => 'Present', 'evidence' => 'GBP · Provider', 'action' => '—'],
                ['area' => 'Cover photo', 'value' => 'Present', 'state' => 'Present', 'evidence' => 'GBP · Provider', 'action' => '—'],
                ['area' => 'Merchant media', 'value' => '48 photos', 'state' => 'Present', 'evidence' => 'GBP · Provider', 'action' => '—'],
            ],
            'categories' => [
                'primary' => 'Dental clinic',
                'additional' => ['Cosmetic dentist', 'Orthodontist'],
                'offering_map' => [
                    ['offering' => 'Implant', 'support' => 'Partial'],
                    ['offering' => 'Orthodontics', 'support' => 'Covered'],
                    ['offering' => 'Smile Design', 'support' => 'Partial'],
                    ['offering' => 'General Dentistry', 'support' => 'Covered'],
                ],
                'note' => 'Category review candidates are interpretive — competitor usage is not proof.',
            ],
            'services' => [
                ['service' => 'Dental Implant', 'offering' => 'Implant', 'website' => '/implant/', 'gbp' => 'Partial', 'state' => 'Review opportunity'],
                ['service' => 'Orthodontics', 'offering' => 'Orthodontics', 'website' => '/orthodontics/', 'gbp' => 'Present', 'state' => 'Covered'],
                ['service' => 'Smile Design', 'offering' => 'Smile Design', 'website' => '/smile-design/', 'gbp' => 'Present', 'state' => 'Covered'],
                ['service' => 'Invisalign', 'offering' => 'Invisalign', 'website' => '/invisalign/', 'gbp' => 'Not represented', 'state' => 'Review opportunity'],
                ['service' => 'General Dentistry', 'offering' => 'General Dentistry', 'website' => '/general/', 'gbp' => 'Present', 'state' => 'Covered'],
            ],
            'location' => [
                'address' => 'Kızılırmak Mah. · Çankaya, Ankara',
                'lat' => self::BUSINESS_LAT,
                'lng' => self::BUSINESS_LNG,
                'service_area' => 'Ankara metro · appointment-led',
                'website_location_page' => '/subeler/cankaya/',
                'website_location_state' => 'Matched',
                'note' => 'A dedicated location page is not automatically required for every business.',
            ],
            'media' => [
                'profile_photo' => true,
                'cover_photo' => true,
                'merchant_count' => 48,
                'customer_count' => 112,
                'note' => 'Read-only overview — no upload actions.',
            ],
        ];
    }

    /**
     * Build geographically coherent 5×5 points around the business.
     *
     * @param  list<list<int>>  $ranks
     * @param  list<list<int>>  $previous
     * @return list<array<string, mixed>>
     */
    public static function buildGridPoints(array $ranks, array $previous, string $keyword, string $scannedAt): array
    {
        $points = [];
        $stepLat = 0.0085;
        $stepLng = 0.011;
        $id = 0;
        for ($r = 0; $r < 5; $r++) {
            for ($c = 0; $c < 5; $c++) {
                $id++;
                $lat = self::BUSINESS_LAT + ((2 - $r) * $stepLat);
                $lng = self::BUSINESS_LNG + (($c - 2) * $stepLng);
                $rank = $ranks[$r][$c];
                $prev = $previous[$r][$c];
                $delta = $prev - $rank;
                $dir = match (true) {
                    $r < 2 && $c < 2 => 'north-west',
                    $r < 2 && $c > 2 => 'north-east',
                    $r > 2 && $c < 2 => 'south-west',
                    $r > 2 && $c > 2 => 'south-east',
                    $r < 2 => 'north',
                    $r > 2 => 'south',
                    $c < 2 => 'west',
                    $c > 2 => 'east',
                    default => 'center',
                };
                $km = round(sqrt((($r - 2) * 0.95) ** 2 + (($c - 2) * 0.95) ** 2), 1);
                $points[] = [
                    'id' => 'p-'.$id,
                    'lat' => round($lat, 5),
                    'lng' => round($lng, 5),
                    'rank' => $rank,
                    'previous_rank' => $prev,
                    'delta' => $delta,
                    'keyword' => $keyword,
                    'scan_at' => $scannedAt,
                    'distance_km' => $km,
                    'direction' => $dir,
                    'top_results' => self::topResultsForPoint($rank, $id),
                ];
            }
        }

        return $points;
    }

    /**
     * @return list<array{position: int, name: string}>
     */
    protected static function topResultsForPoint(int $ourRank, int $seed): array
    {
        $pool = [
            'Nova Dental Ankara',
            'Capital Smile Clinic',
            'Çankaya Oral Care',
            'Ankara Dental Center',
            'Atlas Dental Ankara',
        ];
        $ordered = [];
        for ($i = 1; $i <= 5; $i++) {
            if ($i === $ourRank) {
                $ordered[] = ['position' => $i, 'name' => 'Atlas Dental Ankara'];
            } else {
                $idx = ($seed + $i) % 4;
                $ordered[] = ['position' => $i, 'name' => $pool[$idx]];
            }
        }

        return $ordered;
    }

    /**
     * @return array<string, mixed>
     */
    public static function visibility(): array
    {
        $keywords = [
            'çankaya diş kliniği',
            'ankara diş hekimi',
            'ankara implant',
            'çankaya ortodonti',
            'diş kliniği ankara',
        ];

        $defs = [
            'çankaya diş kliniği' => [
                'current' => [[2, 1, 1, 2, 3], [3, 2, 1, 2, 3], [4, 3, 2, 2, 4], [6, 4, 3, 3, 5], [9, 7, 5, 6, 8]],
                'previous' => [[3, 2, 1, 2, 4], [4, 2, 1, 2, 4], [5, 3, 2, 3, 5], [7, 5, 3, 4, 6], [10, 8, 6, 7, 9]],
                'weakness' => 'Slight softening south of the location.',
            ],
            'ankara diş hekimi' => [
                'current' => [[4, 3, 2, 3, 5], [5, 4, 3, 4, 6], [7, 5, 4, 5, 7], [10, 8, 6, 7, 10], [14, 11, 9, 10, 13]],
                'previous' => [[4, 3, 2, 3, 5], [5, 4, 3, 4, 6], [7, 5, 4, 5, 7], [9, 7, 6, 7, 9], [13, 10, 8, 9, 12]],
                'weakness' => 'Outer rings lose Top 3 coverage.',
            ],
            'ankara implant' => [
                'current' => [[3, 2, 1, 2, 4], [5, 3, 1, 2, 5], [7, 4, 2, 3, 7], [12, 8, 5, 6, 12], [18, 14, 9, 11, 18]],
                'previous' => [[2, 2, 1, 2, 3], [4, 3, 1, 2, 4], [6, 3, 2, 3, 6], [10, 7, 4, 5, 10], [15, 12, 8, 9, 15]],
                'weakness' => 'Visibility weakens south-west of the location. 7 scan points are outside the Top 3.',
            ],
            'çankaya ortodonti' => [
                'current' => [[3, 2, 2, 3, 4], [4, 3, 2, 3, 4], [5, 4, 3, 3, 5], [7, 5, 4, 4, 6], [10, 8, 6, 7, 9]],
                'previous' => [[4, 3, 2, 3, 5], [5, 3, 2, 3, 5], [6, 4, 3, 4, 6], [8, 6, 4, 5, 7], [11, 9, 7, 8, 10]],
                'weakness' => 'Southern points watch-band only.',
            ],
            'diş kliniği ankara' => [
                'current' => [[6, 5, 4, 5, 7], [8, 6, 4, 5, 8], [10, 7, 5, 6, 9], [14, 11, 8, 9, 13], [19, 16, 12, 14, 20]],
                'previous' => [[6, 5, 4, 5, 7], [8, 6, 4, 5, 8], [9, 7, 5, 6, 9], [13, 10, 8, 9, 12], [18, 15, 11, 13, 19]],
                'weakness' => 'City-wide head term remains competitive outside Çankaya.',
            ],
        ];

        $scans = [];
        foreach ($defs as $keyword => $def) {
            $points = self::buildGridPoints($def['current'], $def['previous'], $keyword, '12 Aug 2026 · 14:30');
            $ranks = array_column($points, 'rank');
            $top3 = count(array_filter($ranks, fn (int $r): bool => $r <= 3));
            $top10 = count(array_filter($ranks, fn (int $r): bool => $r <= 10));
            $scans[$keyword] = [
                'current' => [
                    'scanned_at' => '12 Aug 2026 · 14:30',
                    'average_rank' => round(array_sum($ranks) / max(1, count($ranks)), 1),
                    'top3_count' => $top3,
                    'top10_count' => $top10,
                    'best' => min($ranks),
                    'worst' => max($ranks),
                    'points' => $points,
                    'weakness' => $def['weakness'],
                    'source' => 'Demo local rank tracking',
                    'grid' => '5 × 5',
                    'radius' => '≈ 5 km area',
                ],
                'previous' => [
                    'scanned_at' => '29 Jul 2026 · 11:10',
                    'average_rank' => round(array_sum(array_column($points, 'previous_rank')) / 25, 1),
                ],
            ];
        }

        $comparison = [];
        foreach ($keywords as $keyword) {
            $cur = $scans[$keyword]['current'];
            $prevAvg = $scans[$keyword]['previous']['average_rank'];
            $change = round($prevAvg - $cur['average_rank'], 1);
            $comparison[] = [
                'keyword' => $keyword,
                'avg_rank' => $cur['average_rank'],
                'top3_pct' => (int) round(($cur['top3_count'] / 25) * 100),
                'top10_pct' => (int) round(($cur['top10_count'] / 25) * 100),
                'change' => $change,
                'change_label' => $change > 0.15 ? '↑' : ($change < -0.15 ? '↓' : '→'),
            ];
        }

        return [
            'subtitle' => 'Where this location appears across geographically sampled local searches.',
            'keywords' => $keywords,
            'default_keyword' => 'çankaya diş kliniği',
            'scans' => $scans,
            'comparison' => $comparison,
            'coverage_regions' => [
                ['region' => 'North', 'state' => 'Strong'],
                ['region' => 'East', 'state' => 'Strong'],
                ['region' => 'South', 'state' => 'Mixed'],
                ['region' => 'West', 'state' => 'Needs attention'],
                ['region' => 'Center', 'state' => 'Strong'],
            ],
            'opportunities' => [
                'High-priority Implant offering shows weak western/southern coverage on “ankara implant”.',
                'Strong central ranks for neighborhood queries — protect Çankaya relevance signals.',
                'City-wide “diş kliniği ankara” remains outside Top 3 for most outer points.',
            ],
            'business' => [
                'name' => 'Atlas Dental Ankara',
                'lat' => self::BUSINESS_LAT,
                'lng' => self::BUSINESS_LNG,
                'label' => 'GBP location · Çankaya, Ankara',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function performance(array $f): array
    {
        $rf = $f['results_factor'] ?? 1.0;

        return [
            'subtitle' => 'How people discover and interact with this Business Profile.',
            'period' => 'Last 30 days · Demo',
            'previous_label' => 'vs previous 30 days',
            'discovery' => [
                'search_impressions' => (int) round(18420 * $rf),
                'search_delta' => 8.2,
                'maps_impressions' => (int) round(12640 * $rf),
                'maps_delta' => 3.4,
                'total_impressions' => (int) round(31060 * $rf),
                'series_search' => DemoCatalog::trendSeries('gbp_search_impr', 14, 900, 1600),
                'series_maps' => DemoCatalog::trendSeries('gbp_maps_impr', 14, 600, 1100),
                'source' => 'Google Business Profile · Demo provider fixture',
            ],
            'actions' => [
                'website_clicks' => (int) round(412 * $rf),
                'website_delta' => 12.0,
                'call_clicks' => (int) round(286 * $rf),
                'call_delta' => 5.0,
                'direction_requests' => (int) round(176 * $rf),
                'direction_delta' => 2.0,
                'series' => DemoCatalog::trendSeries('gbp_actions_demo', 14, 40, 120),
                'source' => 'Google Business Profile · Demo provider fixture',
                'note' => 'Call clicks ≠ phone calls answered. Direction requests ≠ store visits.',
            ],
            'queries' => [
                'period_options' => ['Last month', 'Last 3 months', 'Last 6 months'],
                'period' => 'Last month',
                'rows' => [
                    ['query' => 'çankaya diş kliniği', 'impressions' => (int) round(4200 * $rf), 'change' => '+14%', 'intent' => 'Local service', 'offering' => 'General Dentistry', 'website' => 'Strong', 'tracked' => true],
                    ['query' => 'ankara implant', 'impressions' => (int) round(3800 * $rf), 'change' => '−8%', 'intent' => 'Service', 'offering' => 'Implant', 'website' => 'Strong', 'tracked' => true],
                    ['query' => 'ankara diş hekimi', 'impressions' => (int) round(2900 * $rf), 'change' => '+3%', 'intent' => 'Discovery', 'offering' => 'General Dentistry', 'website' => 'Partial', 'tracked' => true],
                    ['query' => 'çankaya ortodonti', 'impressions' => (int) round(1600 * $rf), 'change' => '+9%', 'intent' => 'Local service', 'offering' => 'Orthodontics', 'website' => 'Strong', 'tracked' => true],
                    ['query' => 'diş kliniği ankara', 'impressions' => (int) round(5100 * $rf), 'change' => '−2%', 'intent' => 'Discovery', 'offering' => 'General Dentistry', 'website' => 'Partial', 'tracked' => true],
                    ['query' => 'acil dişçi çankaya', 'impressions' => (int) round(640 * $rf), 'change' => '+22%', 'intent' => 'Local service', 'offering' => 'Emergency Dental', 'website' => 'Missing', 'tracked' => false],
                    ['query' => 'atlas dental', 'impressions' => (int) round(980 * $rf), 'change' => '+5%', 'intent' => 'Brand', 'offering' => '—', 'website' => 'Strong', 'tracked' => false],
                ],
                'intent_provenance' => 'Derived',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function reviews(array $f): array
    {
        return [
            'subtitle' => 'Customer feedback, recurring themes and reviews that require operator attention.',
            'glance' => [
                'rating' => 4.7,
                'total' => 326,
                'new' => (int) round(24 * ($f['results_factor'] ?? 1)),
                'needs_reply' => 8,
                'attention' => 5,
            ],
            'distribution' => [5 => 258, 4 => 39, 3 => 16, 2 => 7, 1 => 6],
            'inbox' => [
                ['id' => 'rv-1', 'reviewer' => 'E. Yılmaz', 'stars' => 5, 'excerpt' => 'Excellent implant consultation — clear plan and kind staff.', 'date' => '10 Aug 2026', 'reply' => 'Replied', 'topics' => ['Staff', 'Treatment experience'], 'sentiment' => 'Positive', 'attention' => false],
                ['id' => 'rv-2', 'reviewer' => 'M. Demir', 'stars' => 2, 'excerpt' => 'Waited almost an hour past my appointment time.', 'date' => '9 Aug 2026', 'reply' => 'Needs reply', 'topics' => ['Waiting time', 'Appointment'], 'sentiment' => 'Negative', 'attention' => true, 'why' => '2-star · Unanswered 3 days · Mentions waiting time'],
                ['id' => 'rv-3', 'reviewer' => 'S. Kaya', 'stars' => 3, 'excerpt' => 'Good care but parking near the clinic is difficult.', 'date' => '7 Aug 2026', 'reply' => 'Needs reply', 'topics' => ['Parking', 'Treatment experience'], 'sentiment' => 'Mixed', 'attention' => true, 'why' => 'Unanswered · Parking theme'],
                ['id' => 'rv-4', 'reviewer' => 'A. Öztürk', 'stars' => 5, 'excerpt' => 'Doctor explained every step clearly. Very clean clinic.', 'date' => '5 Aug 2026', 'reply' => 'Replied', 'topics' => ['Doctor communication', 'Cleanliness'], 'sentiment' => 'Positive', 'attention' => false],
                ['id' => 'rv-5', 'reviewer' => 'B. Şen', 'stars' => 1, 'excerpt' => 'Pricing was unclear before treatment started.', 'date' => '4 Aug 2026', 'reply' => 'Needs reply', 'topics' => ['Price'], 'sentiment' => 'Negative', 'attention' => true, 'why' => '1-star · Unanswered · Pricing clarity'],
                ['id' => 'rv-6', 'reviewer' => 'C. Arslan', 'stars' => 4, 'excerpt' => 'Friendly team. Appointment ran a bit late.', 'date' => '2 Aug 2026', 'reply' => 'Replied', 'topics' => ['Staff', 'Waiting time'], 'sentiment' => 'Mixed', 'attention' => false],
                ['id' => 'rv-7', 'reviewer' => 'D. Aydın', 'stars' => 5, 'excerpt' => 'Great communication throughout implant process.', 'date' => '1 Aug 2026', 'reply' => 'Replied', 'topics' => ['Doctor communication', 'Treatment experience'], 'sentiment' => 'Positive', 'attention' => false],
                ['id' => 'rv-8', 'reviewer' => 'F. Kurt', 'stars' => 2, 'excerpt' => 'Waiting time again — third visit with delays.', 'date' => '30 Jul 2026', 'reply' => 'Needs reply', 'topics' => ['Waiting time'], 'sentiment' => 'Negative', 'attention' => true, 'why' => '2-star · Recurring waiting-time theme'],
            ],
            'topics' => [
                ['topic' => 'Staff', 'mentions' => 84, 'positive' => 78, 'mixed' => 4, 'negative' => 2],
                ['topic' => 'Treatment experience', 'mentions' => 61, 'positive' => 52, 'mixed' => 6, 'negative' => 3],
                ['topic' => 'Doctor communication', 'mentions' => 43, 'positive' => 39, 'mixed' => 3, 'negative' => 1],
                ['topic' => 'Waiting time', 'mentions' => 19, 'positive' => 2, 'mixed' => 8, 'negative' => 9],
                ['topic' => 'Parking', 'mentions' => 12, 'positive' => 1, 'mixed' => 4, 'negative' => 7],
                ['topic' => 'Price', 'mentions' => 11, 'positive' => 3, 'mixed' => 3, 'negative' => 5],
            ],
            'topic_trend' => [
                'topic' => 'Waiting time',
                'current' => 9,
                'previous' => 3,
                'note' => 'Waiting-time complaints increased recently (Demo fixture).',
            ],
            'queue' => [
                ['id' => 'rv-2', 'reviewer' => 'M. Demir', 'stars' => 2, 'age' => '3 days', 'topics' => 'Waiting time', 'why' => '2-star unanswered complaint'],
                ['id' => 'rv-5', 'reviewer' => 'B. Şen', 'stars' => 1, 'age' => '8 days', 'topics' => 'Price', 'why' => '1-star pricing clarity'],
                ['id' => 'rv-8', 'reviewer' => 'F. Kurt', 'stars' => 2, 'age' => '13 days', 'topics' => 'Waiting time', 'why' => 'Recurring theme'],
                ['id' => 'rv-3', 'reviewer' => 'S. Kaya', 'stars' => 3, 'age' => '5 days', 'topics' => 'Parking', 'why' => 'Unanswered mixed review'],
            ],
            'provenance' => 'Demo AI analysis',
            'no_write' => 'External review replies are disabled. Create internal Tasks only.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function competitors(): array
    {
        return [
            'subtitle' => 'Businesses repeatedly observed alongside this location in relevant local searches.',
            'note' => 'Observed local competitors from Demo rank scans — not a definitive market census. No live Maps scrape.',
            'rows' => [
                ['name' => 'Nova Dental Ankara', 'distance_km' => 1.2, 'category' => 'Dental clinic', 'rating' => 4.6, 'reviews' => 410, 'top3' => 15, 'queries' => 'ankara implant, diş kliniği ankara', 'lat' => 39.9085, 'lng' => 32.8680],
                ['name' => 'Capital Smile Clinic', 'distance_km' => 1.8, 'category' => 'Cosmetic dentist', 'rating' => 4.8, 'reviews' => 620, 'top3' => 12, 'queries' => 'çankaya diş kliniği', 'lat' => 39.8960, 'lng' => 32.8450],
                ['name' => 'Çankaya Oral Care', 'distance_km' => 0.9, 'category' => 'Dental clinic', 'rating' => 4.5, 'reviews' => 280, 'top3' => 11, 'queries' => 'çankaya diş kliniği, çankaya ortodonti', 'lat' => 39.9050, 'lng' => 32.8520],
                ['name' => 'Ankara Dental Center', 'distance_km' => 2.4, 'category' => 'Dental clinic', 'rating' => 4.4, 'reviews' => 890, 'top3' => 9, 'queries' => 'ankara diş hekimi', 'lat' => 39.9180, 'lng' => 32.8800],
            ],
            'presence' => [
                ['name' => 'Atlas Dental Ankara', 'top3' => 17],
                ['name' => 'Nova Dental Ankara', 'top3' => 15],
                ['name' => 'Capital Smile Clinic', 'top3' => 12],
                ['name' => 'Çankaya Oral Care', 'top3' => 11],
                ['name' => 'Ankara Dental Center', 'top3' => 9],
            ],
            'presence_label' => 'Observed Top 3 presence · 25 scan points · çankaya diş kliniği',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function operations(): array
    {
        return [
            'subtitle' => 'Local presence findings, decisions, work and observed outcomes.',
            'findings' => [
                ['id' => 'gf-phone-mismatch', 'severity' => 'high', 'category' => 'Website consistency', 'title' => 'GBP phone number differs from Website primary business phone', 'status' => 'open'],
                ['id' => 'gf-implant-west-weak', 'severity' => 'high', 'category' => 'Visibility', 'title' => '“ankara implant” visibility is consistently weak across the western scan region', 'status' => 'open', 'affected' => '7 scan points'],
                ['id' => 'gf-implant-service-gap', 'severity' => 'medium', 'category' => 'Category/service', 'title' => 'Priority Implant offering not clearly represented in GBP services', 'status' => 'open'],
                ['id' => 'gf-waiting-theme', 'severity' => 'medium', 'category' => 'Reviews', 'title' => 'Waiting-time complaints increased materially across recent reviews', 'status' => 'open'],
                ['id' => 'gf-phone-resolved', 'severity' => 'medium', 'category' => 'Website consistency', 'title' => 'Prior phone mismatch (resolved Demo chain)', 'status' => 'resolved'],
            ],
            'recommendations' => [
                ['id' => 'gr-align-phone', 'title' => 'Confirm the correct primary business phone and align controlled local entity sources', 'finding_id' => 'gf-phone-mismatch', 'status' => 'pending'],
                ['id' => 'gr-implant-coverage', 'title' => 'Review Implant local relevance signals and Website/GBP service representation', 'finding_id' => 'gf-implant-west-weak', 'status' => 'accepted'],
                ['id' => 'gr-waiting', 'title' => 'Investigate appointment/waiting-time communication across Profile and Website', 'finding_id' => 'gf-waiting-theme', 'status' => 'pending'],
            ],
            'tasks' => [
                ['id' => 'gt-phone', 'title' => 'Confirm correct phone and update controlled sources', 'status' => 'completed', 'owner' => 'Agency', 'due' => '6 Aug'],
                ['id' => 'gt-implant', 'title' => 'Improve local Implant service coverage signals', 'status' => 'completed', 'owner' => 'Local SEO', 'due' => '24 Jul'],
                ['id' => 'gt-waiting', 'title' => 'Improve appointment expectation communication', 'status' => 'completed', 'owner' => 'Client', 'due' => '1 Aug'],
                ['id' => 'gt-parking', 'title' => 'Clarify parking/access on location page', 'status' => 'blocked', 'owner' => 'Website developer', 'due' => 'Waiting on clinic copy'],
                ['id' => 'gt-invisalign', 'title' => 'Review Invisalign GBP service representation with clinic', 'status' => 'open', 'owner' => 'Agency', 'due' => 'Next week'],
            ],
            'outcomes' => [
                ['task' => 'Confirm correct phone and update controlled sources', 'state' => 'Improvement observed', 'note' => 'Website and GBP phone now match in later Evidence.'],
                ['task' => 'Improve local Implant service coverage signals', 'state' => 'Improvement observed', 'note' => '9 of 25 points improved on later scan — observed after change.'],
                ['task' => 'Improve appointment expectation communication', 'state' => 'Still observed', 'note' => 'Waiting-time topic remains present in later reviews.'],
            ],
            'finding_detail' => [
                'gf-phone-mismatch' => [
                    'what' => 'GBP lists +90 312 555 01 01 while the Website primary contact shows +90 312 000 00 00.',
                    'where' => 'GBP contact · Website /contact',
                    'why' => 'Public entity inconsistency reduces trust and confuses local discovery.',
                    'evidence' => 'Normalized phone comparison · Website ↔ GBP',
                    'affected' => 'Contact entity',
                    'owner' => 'Client + Agency',
                    'next' => 'Confirm canonical phone; align Website and GBP (external edit outside MoxDOP).',
                    'verify' => 'Re-run entity consistency check after sources update.',
                ],
                'gf-implant-west-weak' => [
                    'what' => '“ankara implant” ranks outside Top 3 across western/southern scan points.',
                    'where' => '7 geographically sampled points',
                    'why' => 'Priority offering under-delivers in parts of the Demo scan area.',
                    'evidence' => 'Demo local rank tracking · keyword ankara implant',
                    'affected' => '7 scan points',
                    'owner' => 'Local SEO / Agency',
                    'next' => 'Inspect map weak zones and related Profile/Website signals.',
                    'verify' => 'Compare a later scan for the same keyword/grid.',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function aiGuidance(): array
    {
        return [
            'what_matters' => [
                'Resolve phone entity mismatch between Website and GBP first — high-confidence controllable signal.',
                'Implant geographic weakness on “ankara implant” deserves Visibility investigation, not a ranking guarantee.',
                'Waiting-time review theme remains Still observed after prior communication work.',
            ],
            'evidence' => ['Entity consistency', 'Demo local rank tracking', 'Review topics'],
            'disclaimer' => 'AI Guidance is derived interpretation in Demo Mode. It cannot create Findings, Tasks, or external writes.',
        ];
    }
}
