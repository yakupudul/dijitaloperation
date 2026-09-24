<?php

namespace App\Services\Demand;

use App\Models\Brand;
use App\Models\BrandServiceArea;
use App\Models\SearchDemandCompetitor;
use App\Models\ServicePageAssignment;
use App\Services\BrandSetup\BrandSetupMatcher;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rule-based comparison of the brand's service page with the pages that rank above it in the area SERP
 * checks: content depth, headings, FAQ, structured data, price and place mentions. Pages are fetched with
 * the safe public fetcher and reused for 28 days (any brand); nothing is paid for, no AI. The latest result
 * per service is stored in demand_service_comparisons and read by SEO Görevleri (competitor-gap tasks).
 */
final class CompetitorPageComparator
{
    public function __construct(private readonly DemandPageFetcher $fetcher) {}

    /** @return array{services: int, compared: int, gaps: int, fetched: int} */
    public function run(Brand $brand): array
    {
        $stats = ['services' => 0, 'compared' => 0, 'gaps' => 0, 'fetched' => 0];
        $maxCompetitors = (int) config('moxdop-demand.compare.competitors_per_service', 4);
        $ownDomains = $brand->digitalAssets()->where('type', 'website')->get(['primary_url', 'domain'])
            ->map(fn ($site): string => BrandSetupMatcher::host((string) ($site->primary_url ?: $site->domain)))->filter()->values()->all();
        $approved = SearchDemandCompetitor::query()->where('brand_id', $brand->id)->where('status', 'approved')->pluck('normalized_domain')
            ->map(fn ($d): string => BrandSetupMatcher::host((string) $d))->flip();
        $rejected = SearchDemandCompetitor::query()->where('brand_id', $brand->id)->where('status', 'rejected')->pluck('normalized_domain')
            ->map(fn ($d): string => BrandSetupMatcher::host((string) $d))->flip();
        $places = BrandServiceArea::query()->where('brand_id', $brand->id)->where('status', 'active')->get()
            ->flatMap(fn (BrandServiceArea $a): array => array_filter([SeoText::fold((string) $a->district_name), SeoText::fold((string) $a->city_name)]))
            ->unique()->values()->all();

        $checks = DB::table('demand_serp_checks')->where('brand_id', $brand->id)->whereIn('status', ['completed', 'reused'])
            ->whereNotNull('brand_offering_id')->where('checked_at', '>=', now()->subDays(56))->orderByDesc('checked_at')->get()
            ->groupBy('brand_offering_id');

        foreach ($checks as $offeringId => $group) {
            $stats['services']++;
            $ourUrl = $this->ourUrl($brand, (int) $offeringId, $group);
            if ($ourUrl === null) {
                continue;
            }
            $bestRank = $group->pluck('our_rank')->filter()->min();

            // Pages that outrank us: approved competitors first, then other top-5 results; one page per domain.
            $candidates = [];
            foreach ($group as $check) {
                foreach ((array) json_decode((string) $check->results, true) as $result) {
                    $domain = (string) ($result['domain'] ?? '');
                    $rank = (int) ($result['rank'] ?? 99);
                    if ($domain === '' || isset($rejected[$domain]) || $this->isOwn($domain, $ownDomains) || $rank > 5) {
                        continue;
                    }
                    if (! isset($candidates[$domain]) || $rank < $candidates[$domain]['rank']) {
                        $candidates[$domain] = ['url' => (string) $result['url'], 'rank' => $rank, 'approved' => isset($approved[$domain])];
                    }
                }
            }
            uasort($candidates, fn (array $a, array $b): int => [$b['approved'], $a['rank']] <=> [$a['approved'], $b['rank']]);
            $candidates = array_slice($candidates, 0, $maxCompetitors, true);
            if ($candidates === []) {
                continue;
            }

            $ours = $this->metrics($ourUrl, $places, $stats);
            $theirs = [];
            foreach ($candidates as $domain => $candidate) {
                $metrics = $this->metrics($candidate['url'], $places, $stats);
                if ($metrics !== null) {
                    $theirs[] = ['domain' => $domain, 'url' => $candidate['url'], 'rank' => $candidate['rank'], 'metrics' => $metrics];
                }
            }
            if ($ours === null || count($theirs) < 2) {
                continue;
            }

            $median = $this->median(array_column($theirs, 'metrics'));
            $gaps = $this->gaps($ours, array_column($theirs, 'metrics'), $median, $places !== []);
            DB::table('demand_service_comparisons')->upsert([[
                'brand_id' => $brand->id, 'brand_offering_id' => (int) $offeringId, 'our_url' => $ourUrl,
                'our_rank' => $bestRank !== null ? (int) $bestRank : null,
                'our_metrics' => json_encode($ours, JSON_UNESCAPED_UNICODE),
                'competitors' => json_encode(array_map(fn (array $t): array => ['domain' => $t['domain'], 'url' => $t['url'], 'rank' => $t['rank'], 'words' => $t['metrics']['words']], $theirs), JSON_UNESCAPED_UNICODE),
                'competitor_median' => json_encode($median), 'gaps' => json_encode($gaps, JSON_UNESCAPED_UNICODE),
                'compared_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]], ['brand_id', 'brand_offering_id'], ['our_url', 'our_rank', 'our_metrics', 'competitors', 'competitor_median', 'gaps', 'compared_at', 'updated_at']);
            $stats['compared']++;
            $stats['gaps'] += count($gaps);
        }

        return $stats;
    }

    /**
     * @param  list<array<string, mixed>>  $competitors
     * @param  array<string, float|int>  $median
     * @return list<array{key: string, text: string}>
     */
    public function gaps(array $ours, array $competitors, array $median, bool $hasAreas): array
    {
        $half = max(1, (int) ceil(count($competitors) / 2));
        $count = fn (callable $test): int => count(array_filter($competitors, $test));
        $gaps = [];
        if ($median['words'] >= 300 && $ours['words'] < 0.6 * $median['words']) {
            $gaps[] = ['key' => 'content_depth', 'text' => sprintf('İçerik kısa: bizde ~%d kelime, rakiplerde ortanca ~%d. Hizmetin kapsamını, sürecini ve sık sorulanları ekle.', $ours['words'], (int) $median['words'])];
        }
        if ($median['h2_count'] >= 4 && $ours['h2_count'] < $median['h2_count'] - 2) {
            $gaps[] = ['key' => 'structure', 'text' => sprintf('Alt başlık az: bizde %d H2, rakiplerde ortanca %d. Kullanıcının aradığı alt konuları ayrı başlıklarla işle.', $ours['h2_count'], (int) $median['h2_count'])];
        }
        if (! $ours['faq'] && $count(fn (array $m): bool => $m['faq']) >= $half) {
            $gaps[] = ['key' => 'faq', 'text' => 'Rakiplerin çoğunda Sık Sorulan Sorular bölümü var, bizde yok. Gerçek sorularla SSS ekle (FAQPage şeması ile).'];
        }
        $theirTypes = [];
        foreach ($competitors as $m) {
            foreach ($m['schema_types'] as $type) {
                $theirTypes[$type] = ($theirTypes[$type] ?? 0) + 1;
            }
        }
        $missing = array_keys(array_filter($theirTypes, fn (int $n, string $type): bool => $n >= $half && ! in_array($type, $ours['schema_types'], true)
            && in_array($type, ['FAQPage', 'Service', 'MedicalProcedure', 'MedicalBusiness', 'Dentist', 'Physician', 'LocalBusiness', 'Product', 'Offer', 'AggregateRating', 'Review', 'BreadcrumbList', 'HowTo'], true), ARRAY_FILTER_USE_BOTH));
        if ($missing !== []) {
            $gaps[] = ['key' => 'schema', 'text' => 'Rakiplerde olan yapısal veri bizde eksik: '.implode(', ', $missing).'.'];
        }
        if (! $ours['has_price'] && $count(fn (array $m): bool => $m['has_price']) >= $half) {
            $gaps[] = ['key' => 'price', 'text' => 'Rakiplerin çoğu fiyat/ücret bilgisinden söz ediyor, sayfamız etmiyor. Fiyatı etkileyen etkenleri ya da aralığı anlat (sektör kurallarına uyarak).'];
        }
        if ($hasAreas && ! $ours['mentions_place'] && $count(fn (array $m): bool => $m['mentions_place']) >= $half) {
            $gaps[] = ['key' => 'area', 'text' => 'Rakipler hizmet verilen semt/şehri sayfada anıyor, biz anmıyoruz. Konum, ulaşım ve bölgeye özgü bilgiyi ekle.'];
        }

        return $gaps;
    }

    /**
     * @param  list<array<string, mixed>>  $metrics
     * @return array{words: float, h2_count: float, internal_links: float}
     */
    private function median(array $metrics): array
    {
        $median = static function (array $values): float {
            sort($values);
            $n = count($values);

            return $n === 0 ? 0.0 : ($n % 2 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2);
        };

        return [
            'words' => $median(array_column($metrics, 'words')),
            'h2_count' => $median(array_column($metrics, 'h2_count')),
            'internal_links' => $median(array_column($metrics, 'internal_links')),
        ];
    }

    /** @param  list<string>  $places */
    private function metrics(string $url, array $places, array &$stats): ?array
    {
        $key = hash('sha256', SeoText::urlKey($url));
        $cached = DB::table('demand_page_snapshots')->where('url_key', $key)
            ->where('fetched_at', '>=', now()->subDays((int) config('moxdop-demand.compare.page_freshness_days', 28)))->first();
        if ($cached !== null) {
            return $cached->metrics !== null ? $this->forBrand((array) json_decode((string) $cached->metrics, true), $places) : null;
        }
        try {
            $page = $this->fetcher->fetch($url);
        } catch (Throwable $exception) {
            report($exception);
            $page = ['status_code' => null, 'html' => null, 'error' => $exception->getMessage()];
        }
        $stats['fetched']++;
        $metrics = $page['html'] !== null ? PageContentMetrics::from($url, $page['html']) : null;
        DB::table('demand_page_snapshots')->upsert([[
            'url_key' => $key, 'url' => $url, 'domain' => BrandSetupMatcher::host($url), 'status_code' => $page['status_code'],
            'metrics' => $metrics !== null ? json_encode($metrics, JSON_UNESCAPED_UNICODE) : null,
            'error' => $page['error'] !== null ? mb_substr((string) $page['error'], 0, 1000) : null,
            'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]], ['url_key'], ['url', 'domain', 'status_code', 'metrics', 'error', 'fetched_at', 'updated_at']);

        return $metrics !== null ? $this->forBrand($metrics, $places) : null;
    }

    /** Place mentions for this brand's areas; the long text excerpt is not kept in comparisons. */
    private function forBrand(array $metrics, array $places): array
    {
        $text = ' '.($metrics['text_folded'] ?? '').' ';
        $metrics['mentions_place'] = collect($places)->contains(fn (string $place): bool => $place !== '' && str_contains($text, ' '.$place.' '));
        unset($metrics['text_folded']);

        return $metrics;
    }

    private function ourUrl(Brand $brand, int $offeringId, $checks): ?string
    {
        $assigned = ServicePageAssignment::query()->where('brand_offering_id', $offeringId)
            ->whereHas('offering', fn ($q) => $q->where('brand_id', $brand->id))->orderByDesc('id')->value('page_url');
        if (filled($assigned)) {
            return (string) $assigned;
        }

        return $checks->pluck('our_url')->filter()->first();
    }

    /** @param  list<string>  $ownDomains */
    private function isOwn(string $domain, array $ownDomains): bool
    {
        foreach ($ownDomains as $own) {
            if ($domain === $own || str_ends_with($domain, '.'.$own)) {
                return true;
            }
        }

        return false;
    }
}
