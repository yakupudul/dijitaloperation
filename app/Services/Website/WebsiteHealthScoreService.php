<?php

namespace App\Services\Website;

use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Services\IntelligenceProjection\Website\WebsiteProjectionReadService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Read-only site health view (Semrush / Ahrefs "site audit" style) built exclusively from data the
 * website collector already stored: `website_http_snapshot`, `website_crawl_issue_snapshot`,
 * `website_metadata_snapshot`, `website_link_edge` and — when no raw crawl rows exist — the projected
 * `WebsitePageProfile` source states. It never crawls or calls external services.
 *
 * Crawl ("collection") = all rows sharing one `last_collection_run_id` (or, when the run id is missing,
 * the same observation day). The site state "as of" a crawl is the latest observation of every URL seen
 * in that crawl or any earlier one, so partial re-checks (e.g. issue verification of a few URLs) update
 * only the URLs they touched instead of shrinking the site.
 *
 * Health score formula (0–100, integer):
 *
 *     score = clamp(round(100 − Σ_s W_s × U_s / P), 0, 100)
 *
 * - P   = number of checked pages in the current state (distinct normalised URLs)
 * - U_s = number of those pages with at least one rule issue of severity s
 * - W_s = critical 45, high 30, medium 17, low 8, info 0 (weights sum to 100, so a site where every page
 *         has an issue of every severity scores 0)
 *
 * Only deterministic crawl rules (`website_crawl_issue_snapshot` / profile `crawl_issues`) are scored.
 * Derived groups (duplicate titles / meta descriptions, orphan pages, broken internal links) are listed
 * for the operator but marked "not scored" so the trend stays comparable across collections.
 */
final class WebsiteHealthScoreService
{
    /** @var array<string, int> */
    public const array SEVERITY_WEIGHTS = [
        'critical' => 45,
        'high' => 30,
        'medium' => 17,
        'low' => 8,
        'info' => 0,
    ];

    public const int TREND_LIMIT = 6;

    public const int URL_DISPLAY_LIMIT = 50;

    /** @var list<string> */
    public const array BROKEN_CODES = ['HTTP_4XX', 'HTTP_5XX', 'FETCH_FAILED'];

    /** @var list<string> */
    public const array REDIRECT_CODES = ['REDIRECT_CHAIN', 'EXTERNAL_REDIRECT'];

    public function __construct(
        private readonly WebsiteProjectionReadService $projection,
    ) {}

    /**
     * @return array{
     *     available: bool,
     *     source: string,
     *     score: int|null,
     *     grade: string|null,
     *     pages_checked: int,
     *     collected_at: string|null,
     *     crawl_count: int,
     *     trend: list<array{score: int|null, collected_at: string|null}>,
     *     change: int|null,
     *     groups: list<array<string, mixed>>,
     *     summary: array{broken_pages: int, broken_links: int|null, redirects: int, orphans: int|null, duplicates: int|null},
     *     availability: array{history: bool, duplicates: bool, orphans: bool, broken_links: bool},
     *     severity_weights: array<string, int>
     * }
     */
    public function build(DigitalAsset $asset): array
    {
        $assetId = (int) $asset->getKey();
        $crawls = $this->crawlsFromRows($this->httpRows($assetId), $this->issueRows($assetId));
        $source = 'crawl';
        $titles = [];
        $descriptions = [];

        if ($crawls === []) {
            [$crawls, $titles, $descriptions] = $this->fromProjection($asset);
            $source = $crawls === [] ? 'none' : 'projection';
        } else {
            [$titles, $descriptions] = $this->latestMetadata($assetId);
        }

        $edges = $source === 'crawl' ? $this->latestInternalEdges($assetId) : [];

        return $this->report($crawls, $titles, $descriptions, $edges, $source);
    }

    /**
     * Pure assembly of the report from already-loaded inputs.
     *
     * @param  list<array{key: string, collected_at: string|null, pages: array<string, array{status: int|null, redirect_count: int|null, redirected_from: string|null}>, issues: array<string, array<string, string>>}>  $crawls
     * @param  array<string, string>  $titles  normalised URL => title
     * @param  array<string, string>  $descriptions  normalised URL => meta description
     * @param  list<array{source: string, target: string}>  $edges  current internal link edges (normalised)
     * @return array<string, mixed>
     */
    public function report(array $crawls, array $titles, array $descriptions, array $edges, string $source = 'crawl'): array
    {
        $empty = [
            'available' => false,
            'source' => $source,
            'score' => null,
            'grade' => null,
            'pages_checked' => 0,
            'collected_at' => null,
            'crawl_count' => 0,
            'trend' => [],
            'change' => null,
            'groups' => [],
            'summary' => ['broken_pages' => 0, 'broken_links' => null, 'redirects' => 0, 'orphans' => null, 'duplicates' => null],
            'availability' => ['history' => false, 'duplicates' => false, 'orphans' => false, 'broken_links' => false],
            'severity_weights' => self::SEVERITY_WEIGHTS,
        ];

        if ($crawls === []) {
            return $empty;
        }

        $lastIndex = count($crawls) - 1;
        $current = $this->stateAsOf($crawls, $lastIndex);
        $previous = $lastIndex > 0 ? $this->stateAsOf($crawls, $lastIndex - 1) : null;
        $pageCount = count($current['pages']);
        if ($pageCount === 0) {
            return $empty;
        }

        $score = $this->score($pageCount, $current['issues']);

        $trend = [];
        for ($index = max(0, $lastIndex - self::TREND_LIMIT + 1); $index <= $lastIndex; $index++) {
            $state = $index === $lastIndex ? $current : $this->stateAsOf($crawls, $index);
            $trend[] = [
                'score' => $this->score(count($state['pages']), $state['issues']),
                'collected_at' => $crawls[$index]['collected_at'],
            ];
        }
        $hasHistory = count($trend) > 1;
        $previousScore = $hasHistory ? $trend[count($trend) - 2]['score'] : null;

        $pageUrls = array_keys($current['pages']);
        $groups = $this->issueGroups($current, $previous);

        $currentTitles = array_intersect_key($titles, $current['pages']);
        $currentDescriptions = array_intersect_key($descriptions, $current['pages']);
        $duplicateTitles = $this->duplicateGroup($currentTitles, 'DUPLICATE_TITLE', 'medium', $pageCount);
        $duplicateDescriptions = $this->duplicateGroup($currentDescriptions, 'DUPLICATE_META_DESCRIPTION', 'low', $pageCount);

        $brokenUrls = $this->brokenUrls($current);
        $brokenLinks = $edges !== [] ? $this->brokenLinkGroup($edges, $brokenUrls, $pageCount) : null;
        $orphans = $edges !== [] ? $this->orphanGroup($pageUrls, $edges, $brokenUrls, $pageCount) : null;

        foreach ([$brokenLinks, $orphans, $duplicateTitles, $duplicateDescriptions] as $derived) {
            if ($derived !== null) {
                $groups[] = $derived;
            }
        }

        return [
            'available' => true,
            'source' => $source,
            'score' => $score,
            'grade' => $this->grade($score),
            'pages_checked' => $pageCount,
            'collected_at' => $crawls[$lastIndex]['collected_at'],
            'crawl_count' => count($crawls),
            'trend' => $hasHistory ? $trend : [],
            'change' => $hasHistory && $score !== null && $previousScore !== null ? $score - $previousScore : null,
            'groups' => $groups,
            'summary' => [
                'broken_pages' => count($brokenUrls),
                'broken_links' => $edges === [] ? null : (int) ($brokenLinks['link_count'] ?? 0),
                'redirects' => (int) (collect($groups)->firstWhere('code', 'REDIRECT_CHAIN')['url_count'] ?? 0),
                'orphans' => $edges === [] ? null : (int) ($orphans['url_count'] ?? 0),
                'duplicates' => $currentTitles === [] ? null : (int) ($duplicateTitles['url_count'] ?? 0),
            ],
            'availability' => [
                'history' => $hasHistory,
                'duplicates' => $currentTitles !== [] || $currentDescriptions !== [],
                'orphans' => $edges !== [],
                'broken_links' => $edges !== [],
            ],
            'severity_weights' => self::SEVERITY_WEIGHTS,
        ];
    }

    /**
     * Health score, see class PHPDoc for the formula.
     *
     * @param  array<string, array<string, string>>  $issuesByUrl  URL => [code => severity]
     */
    public function score(int $pageCount, array $issuesByUrl): ?int
    {
        if ($pageCount <= 0) {
            return null;
        }

        $penalty = 0.0;
        foreach (self::SEVERITY_WEIGHTS as $severity => $weight) {
            if ($weight === 0) {
                continue;
            }
            $affected = 0;
            foreach ($issuesByUrl as $issues) {
                if (in_array($severity, $issues, true)) {
                    $affected++;
                }
            }
            $penalty += $weight * min(1.0, $affected / $pageCount);
        }

        return (int) max(0, min(100, round(100 - $penalty)));
    }

    public function grade(?int $score): ?string
    {
        return match (true) {
            $score === null => null,
            $score >= 80 => 'good',
            $score >= 60 => 'fair',
            default => 'poor',
        };
    }

    /**
     * Groups raw rows into chronologically ordered crawls.
     *
     * @param  list<array{url: string, observed_at: string, run_id: int|null, metadata: array<string, mixed>}>  $httpRows
     * @param  list<array{url: string, code: string, severity: string, observed_at: string, run_id: int|null}>  $issueRows
     * @return list<array{key: string, collected_at: string|null, pages: array<string, array{status: int|null, redirect_count: int|null, redirected_from: string|null}>, issues: array<string, array<string, string>>}>
     */
    public function crawlsFromRows(array $httpRows, array $issueRows): array
    {
        /** @var array<string, array{key: string, collected_at: string|null, pages: array<string, array{status: int|null, redirect_count: int|null, redirected_from: string|null, observed_at: string}>, issues: array<string, array<string, string>>}> $crawls */
        $crawls = [];

        foreach ($httpRows as $row) {
            $key = $this->crawlKey($row['run_id'], $row['observed_at']);
            $metadata = $row['metadata'];
            $finalUrl = is_string($metadata['final_url'] ?? null) && trim($metadata['final_url']) !== '' ? $metadata['final_url'] : null;
            $requested = self::normalizeUrl($row['url']);
            $url = $finalUrl !== null ? self::normalizeUrl($finalUrl) : $requested;
            if ($url === '') {
                continue;
            }
            $crawls[$key] ??= ['key' => $key, 'collected_at' => null, 'pages' => [], 'issues' => []];
            $crawls[$key]['collected_at'] = $this->maxDate($crawls[$key]['collected_at'], $row['observed_at']);
            $existing = $crawls[$key]['pages'][$url] ?? null;
            if ($existing !== null && strcmp($existing['observed_at'], $row['observed_at']) > 0) {
                continue;
            }
            $crawls[$key]['pages'][$url] = [
                'status' => is_numeric($metadata['status_code'] ?? null) ? (int) $metadata['status_code'] : null,
                'redirect_count' => is_numeric($metadata['redirect_count'] ?? null) ? (int) $metadata['redirect_count'] : null,
                'redirected_from' => $requested !== $url ? $row['url'] : null,
                'observed_at' => $row['observed_at'],
            ];
        }

        foreach ($issueRows as $row) {
            $key = $this->crawlKey($row['run_id'], $row['observed_at']);
            $url = self::normalizeUrl($row['url']);
            $code = strtoupper(trim($row['code']));
            if ($url === '' || $code === '') {
                continue;
            }
            $crawls[$key] ??= ['key' => $key, 'collected_at' => null, 'pages' => [], 'issues' => []];
            $crawls[$key]['collected_at'] = $this->maxDate($crawls[$key]['collected_at'], $row['observed_at']);
            $crawls[$key]['pages'][$url] ??= ['status' => null, 'redirect_count' => null, 'redirected_from' => null, 'observed_at' => $row['observed_at']];
            $severity = strtolower(trim($row['severity']));
            $crawls[$key]['issues'][$url][$code] = array_key_exists($severity, self::SEVERITY_WEIGHTS) ? $severity : 'info';
        }

        $crawls = array_values($crawls);
        usort($crawls, static fn (array $left, array $right): int => strcmp((string) $left['collected_at'], (string) $right['collected_at']));

        return array_map(static function (array $crawl): array {
            $crawl['pages'] = array_map(static function (array $page): array {
                return [
                    'status' => $page['status'],
                    'redirect_count' => $page['redirect_count'],
                    'redirected_from' => $page['redirected_from'],
                ];
            }, $crawl['pages']);

            return $crawl;
        }, $crawls);
    }

    /**
     * Site state after the crawl at $index: every URL keeps its latest observation.
     *
     * @param  list<array{key: string, collected_at: string|null, pages: array<string, array{status: int|null, redirect_count: int|null, redirected_from: string|null}>, issues: array<string, array<string, string>>}>  $crawls
     * @return array{pages: array<string, array{status: int|null, redirect_count: int|null, redirected_from: string|null}>, issues: array<string, array<string, string>>}
     */
    public function stateAsOf(array $crawls, int $index): array
    {
        $pages = [];
        $issues = [];
        for ($position = 0; $position <= $index && isset($crawls[$position]); $position++) {
            foreach ($crawls[$position]['pages'] as $url => $page) {
                $pages[$url] = $page;
                $crawlIssues = $crawls[$position]['issues'][$url] ?? [];
                if ($crawlIssues === []) {
                    unset($issues[$url]);
                } else {
                    $issues[$url] = $crawlIssues;
                }
            }
        }

        return ['pages' => $pages, 'issues' => $issues];
    }

    /**
     * Rule issue groups of the current state, most severe / widest first.
     *
     * @param  array{pages: array<string, array{status: int|null, redirect_count: int|null, redirected_from: string|null}>, issues: array<string, array<string, string>>}  $current
     * @param  array{pages: array<string, array{status: int|null, redirect_count: int|null, redirected_from: string|null}>, issues: array<string, array<string, string>>}|null  $previous
     * @return list<array<string, mixed>>
     */
    public function issueGroups(array $current, ?array $previous): array
    {
        $pageCount = max(1, count($current['pages']));
        $groups = [];
        foreach ($current['issues'] as $url => $issues) {
            foreach ($issues as $code => $severity) {
                $groups[$code] ??= ['code' => $code, 'severity' => $severity, 'urls' => []];
                if ($this->severityRank($severity) > $this->severityRank($groups[$code]['severity'])) {
                    $groups[$code]['severity'] = $severity;
                }
                $groups[$code]['urls'][] = $url;
            }
        }

        $result = [];
        foreach ($groups as $code => $group) {
            $urls = $group['urls'];
            sort($urls);
            $new = null;
            if ($previous !== null) {
                $new = count(array_filter(
                    $urls,
                    static fn (string $url): bool => ! isset($previous['issues'][$url][$code]),
                ));
            }
            $result[] = [
                'code' => (string) $code,
                'label' => $this->label((string) $code),
                'severity' => $group['severity'],
                'kind' => in_array($code, self::BROKEN_CODES, true) ? 'broken' : (in_array($code, self::REDIRECT_CODES, true) ? 'redirect' : 'rule'),
                'scored' => true,
                'url_count' => count($urls),
                'share' => min(1.0, count($urls) / $pageCount),
                'new_count' => $new,
                'items' => array_map(fn (string $url): array => [
                    'url' => $url,
                    'detail' => $this->pageDetail((string) $code, $current['pages'][$url] ?? null),
                ], $urls),
            ];
        }

        usort($result, fn (array $left, array $right): int => [$this->severityRank($right['severity']), $right['url_count'], $left['code']]
            <=> [$this->severityRank($left['severity']), $left['url_count'], $right['code']]);

        return $result;
    }

    /**
     * Pages sharing the same (case-insensitive, trimmed) value.
     *
     * @param  array<string, string>  $valuesByUrl
     * @return array<string, mixed>|null
     */
    public function duplicateGroup(array $valuesByUrl, string $code, string $severity, int $pageCount): ?array
    {
        $clusters = [];
        foreach ($valuesByUrl as $url => $value) {
            $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
            if ($normalized === '') {
                continue;
            }
            $clusters[$normalized]['value'] ??= trim($value);
            $clusters[$normalized]['urls'][] = (string) $url;
        }

        $clusters = array_filter($clusters, static fn (array $cluster): bool => count($cluster['urls']) > 1);
        if ($clusters === []) {
            return null;
        }

        uasort($clusters, static fn (array $left, array $right): int => count($right['urls']) <=> count($left['urls']));
        $items = [];
        foreach ($clusters as $cluster) {
            $urls = $cluster['urls'];
            sort($urls);
            foreach ($urls as $url) {
                $items[] = ['url' => $url, 'detail' => '“'.Str::limit($cluster['value'], 90).'” · '.count($urls)];
            }
        }

        return [
            'code' => $code,
            'label' => $this->label($code),
            'severity' => $severity,
            'kind' => 'duplicate',
            'scored' => false,
            'url_count' => count($items),
            'share' => min(1.0, count($items) / max(1, $pageCount)),
            'new_count' => null,
            'cluster_count' => count($clusters),
            'items' => $items,
        ];
    }

    /**
     * Checked, healthy, non-root pages that no other crawled page links to.
     *
     * @param  list<string>  $pageUrls
     * @param  list<array{source: string, target: string}>  $edges
     * @param  array<string, true>  $brokenUrls
     * @return array<string, mixed>|null
     */
    public function orphanGroup(array $pageUrls, array $edges, array $brokenUrls, int $pageCount): ?array
    {
        $linked = [];
        foreach ($edges as $edge) {
            if ($edge['source'] !== $edge['target']) {
                $linked[$edge['target']] = true;
            }
        }

        $orphans = array_values(array_filter($pageUrls, function (string $url) use ($linked, $brokenUrls): bool {
            return ! isset($linked[$url]) && ! isset($brokenUrls[$url]) && ! $this->isRootUrl($url);
        }));
        sort($orphans);

        if ($orphans === []) {
            return null;
        }

        return [
            'code' => 'ORPHAN_PAGE',
            'label' => $this->label('ORPHAN_PAGE'),
            'severity' => 'low',
            'kind' => 'orphan',
            'scored' => false,
            'url_count' => count($orphans),
            'share' => min(1.0, count($orphans) / max(1, $pageCount)),
            'new_count' => null,
            'items' => array_map(static fn (string $url): array => ['url' => $url, 'detail' => null], $orphans),
        ];
    }

    /**
     * Internal links pointing at a page whose latest check was 4xx / 5xx / unreachable.
     *
     * @param  list<array{source: string, target: string}>  $edges
     * @param  array<string, true>  $brokenUrls
     * @return array<string, mixed>|null
     */
    public function brokenLinkGroup(array $edges, array $brokenUrls, int $pageCount): ?array
    {
        $items = [];
        $sources = [];
        foreach ($edges as $edge) {
            if (isset($brokenUrls[$edge['target']]) && $edge['source'] !== $edge['target']) {
                $items[$edge['source'].' '.$edge['target']] = ['url' => $edge['source'], 'detail' => '→ '.$edge['target']];
                $sources[$edge['source']] = true;
            }
        }

        if ($items === []) {
            return null;
        }

        ksort($items);

        return [
            'code' => 'BROKEN_INTERNAL_LINK',
            'label' => $this->label('BROKEN_INTERNAL_LINK'),
            'severity' => 'high',
            'kind' => 'broken',
            'scored' => false,
            'url_count' => count($sources),
            'share' => min(1.0, count($sources) / max(1, $pageCount)),
            'new_count' => null,
            'link_count' => count($items),
            'items' => array_values($items),
        ];
    }

    /**
     * CSV rows (header first): one row per affected URL of every group.
     *
     * @param  array<string, mixed>  $report
     * @return list<list<string>>
     */
    public function csvRows(array $report): array
    {
        $rows = [[
            __('operator_website.health_score.csv.code'),
            __('operator_website.health_score.csv.issue'),
            __('operator_website.health_score.csv.severity'),
            __('operator_website.health_score.csv.scored'),
            __('operator_website.health_score.csv.url_count'),
            __('operator_website.health_score.csv.share'),
            __('operator_website.health_score.csv.new_count'),
            __('operator_website.health_score.csv.url'),
            __('operator_website.health_score.csv.detail'),
        ]];

        foreach ($report['groups'] ?? [] as $group) {
            foreach ($group['items'] as $item) {
                $rows[] = [
                    (string) $group['code'],
                    (string) $group['label'],
                    (string) __('operator_website.severity.'.$group['severity']),
                    $group['scored'] ? __('operator_website.health_score.csv.yes') : __('operator_website.health_score.csv.no'),
                    (string) $group['url_count'],
                    number_format($group['share'] * 100, 1, ',', ''),
                    $group['new_count'] === null ? '' : (string) $group['new_count'],
                    (string) $item['url'],
                    (string) ($item['detail'] ?? ''),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<list<string>>  $rows
     */
    public function csvContent(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';', '"', '');
        }
        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /**
     * Lower-cases scheme and host, drops the fragment and a trailing slash (root stays "/").
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return $url;
        }

        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return strtolower($parts['scheme'] ?? 'https').'://'.strtolower($parts['host'])
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .$path
            .(isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '');
    }

    /**
     * @param  array{pages: array<string, array<string, mixed>>, issues: array<string, array<string, string>>}  $state
     * @return array<string, true>
     */
    private function brokenUrls(array $state): array
    {
        $broken = [];
        foreach ($state['issues'] as $url => $issues) {
            if (array_intersect(array_keys($issues), self::BROKEN_CODES) !== []) {
                $broken[$url] = true;
            }
        }
        foreach ($state['pages'] as $url => $page) {
            if (($page['status'] ?? null) !== null && $page['status'] >= 400) {
                $broken[$url] = true;
            }
        }

        return $broken;
    }

    /** @param array{status: int|null, redirect_count: int|null, redirected_from: string|null}|null $page */
    private function pageDetail(string $code, ?array $page): ?string
    {
        if ($page === null) {
            return null;
        }

        if (in_array($code, self::BROKEN_CODES, true) && $page['status'] !== null) {
            return 'HTTP '.$page['status'];
        }

        if (in_array($code, self::REDIRECT_CODES, true)) {
            $parts = [];
            if ($page['redirect_count'] !== null) {
                $parts[] = __('operator_website.health_score.redirect_steps', ['count' => $page['redirect_count']]);
            }
            if ($page['redirected_from'] !== null) {
                $parts[] = '← '.$page['redirected_from'];
            }

            return $parts === [] ? null : implode(' ', $parts);
        }

        return null;
    }

    private function label(string $code): string
    {
        foreach (['operator.website.technical_health.issue_codes.'.$code, 'operator_website.health_score.codes.'.$code] as $key) {
            if (Lang::has($key)) {
                return (string) __($key);
            }
        }

        return Str::headline(strtolower($code));
    }

    private function severityRank(?string $severity): int
    {
        return match ($severity) {
            'critical' => 5,
            'high' => 4,
            'medium' => 3,
            'low' => 2,
            'info' => 1,
            default => 0,
        };
    }

    private function isRootUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        return ($path === null || $path === '' || $path === '/') && parse_url($url, PHP_URL_QUERY) === null;
    }

    private function crawlKey(?int $runId, string $observedAt): string
    {
        return $runId !== null ? 'run:'.$runId : 'day:'.substr($observedAt, 0, 10);
    }

    private function maxDate(?string $current, string $candidate): string
    {
        return $current === null || strcmp($candidate, $current) > 0 ? $candidate : $current;
    }

    /** @return list<array{url: string, observed_at: string, run_id: int|null, metadata: array<string, mixed>}> */
    private function httpRows(int $assetId): array
    {
        if (! Schema::hasTable('website_http_snapshot')) {
            return [];
        }

        return DB::table('website_http_snapshot')
            ->where('digital_asset_id', $assetId)
            ->orderBy('observed_at')
            ->get(['url', 'observed_at', 'last_collection_run_id', 'metadata'])
            ->map(fn (object $row): array => [
                'url' => (string) $row->url,
                'observed_at' => $this->isoDate($row->observed_at),
                'run_id' => $row->last_collection_run_id !== null ? (int) $row->last_collection_run_id : null,
                'metadata' => $this->decode($row->metadata),
            ])
            ->all();
    }

    /** @return list<array{url: string, code: string, severity: string, observed_at: string, run_id: int|null}> */
    private function issueRows(int $assetId): array
    {
        if (! Schema::hasTable('website_crawl_issue_snapshot')) {
            return [];
        }

        return DB::table('website_crawl_issue_snapshot')
            ->where('digital_asset_id', $assetId)
            ->orderBy('observed_at')
            ->get(['url', 'issue_code', 'severity', 'observed_at', 'last_collection_run_id'])
            ->map(fn (object $row): array => [
                'url' => (string) $row->url,
                'code' => (string) $row->issue_code,
                'severity' => (string) $row->severity,
                'observed_at' => $this->isoDate($row->observed_at),
                'run_id' => $row->last_collection_run_id !== null ? (int) $row->last_collection_run_id : null,
            ])
            ->all();
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} */
    private function latestMetadata(int $assetId): array
    {
        if (! Schema::hasTable('website_metadata_snapshot')) {
            return [[], []];
        }

        $titles = [];
        $descriptions = [];
        DB::table('website_metadata_snapshot')
            ->where('digital_asset_id', $assetId)
            ->orderBy('observed_at')
            ->get(['url', 'metadata'])
            ->each(function (object $row) use (&$titles, &$descriptions): void {
                $url = self::normalizeUrl((string) $row->url);
                $metadata = $this->decode($row->metadata);
                $title = is_string($metadata['title'] ?? null) ? trim($metadata['title']) : '';
                $description = is_string($metadata['meta_description'] ?? null) ? trim($metadata['meta_description']) : '';
                if ($title !== '') {
                    $titles[$url] = $title;
                } else {
                    unset($titles[$url]);
                }
                if ($description !== '') {
                    $descriptions[$url] = $description;
                } else {
                    unset($descriptions[$url]);
                }
            });

        return [$titles, $descriptions];
    }

    /** @return list<array{source: string, target: string}> */
    private function latestInternalEdges(int $assetId): array
    {
        if (! Schema::hasTable('website_link_edge')) {
            return [];
        }

        $bySource = [];
        DB::table('website_link_edge')
            ->where('digital_asset_id', $assetId)
            ->where('is_internal', true)
            ->orderBy('observed_at')
            ->get(['source_url', 'normalized_target_url', 'observed_at'])
            ->each(function (object $row) use (&$bySource): void {
                $source = self::normalizeUrl((string) $row->source_url);
                $observedAt = $this->isoDate($row->observed_at);
                if (! isset($bySource[$source]) || strcmp($observedAt, $bySource[$source]['observed_at']) > 0) {
                    $bySource[$source] = ['observed_at' => $observedAt, 'targets' => []];
                }
                if ($bySource[$source]['observed_at'] === $observedAt) {
                    $bySource[$source]['targets'][self::normalizeUrl((string) $row->normalized_target_url)] = true;
                }
            });

        $edges = [];
        foreach ($bySource as $source => $entry) {
            foreach (array_keys($entry['targets']) as $target) {
                $edges[] = ['source' => (string) $source, 'target' => (string) $target];
            }
        }

        return $edges;
    }

    /**
     * Single-collection fallback from projected page profiles when no raw crawl rows exist.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>, 2: array<string, string>}
     */
    private function fromProjection(DigitalAsset $asset): array
    {
        $pages = [];
        $issues = [];
        $titles = [];
        $descriptions = [];
        $collectedAt = null;

        $this->projection->pages($asset)
            ->get(['id', 'preferred_url', 'source_states', 'last_observed_at'])
            ->each(function (WebsitePageProfile $profile) use (&$pages, &$issues, &$titles, &$descriptions, &$collectedAt): void {
                $website = data_get($profile->source_states, 'website');
                if (! is_array($website)) {
                    return;
                }
                $url = self::normalizeUrl((string) $profile->preferred_url);
                if ($url === '') {
                    return;
                }
                $http = is_array($website['http'] ?? null) ? $website['http'] : [];
                $pages[$url] = [
                    'status' => is_numeric($http['status_code'] ?? null) ? (int) $http['status_code'] : null,
                    'redirect_count' => is_numeric($http['redirect_count'] ?? null) ? (int) $http['redirect_count'] : null,
                    'redirected_from' => null,
                ];
                foreach (is_array($website['crawl_issues'] ?? null) ? $website['crawl_issues'] : [] as $issue) {
                    if (! is_array($issue) || ! filled($issue['code'] ?? null)) {
                        continue;
                    }
                    $severity = strtolower((string) ($issue['severity'] ?? 'info'));
                    $issues[$url][strtoupper((string) $issue['code'])] = array_key_exists($severity, self::SEVERITY_WEIGHTS) ? $severity : 'info';
                }
                $head = is_array($website['document_head'] ?? null) ? $website['document_head'] : [];
                if (is_string($head['title'] ?? null) && trim($head['title']) !== '') {
                    $titles[$url] = trim($head['title']);
                }
                if (is_string($head['meta_description'] ?? null) && trim($head['meta_description']) !== '') {
                    $descriptions[$url] = trim($head['meta_description']);
                }
                $observed = $profile->last_observed_at?->toIso8601String();
                if ($observed !== null) {
                    $collectedAt = $this->maxDate($collectedAt, $observed);
                }
            });

        if ($pages === []) {
            return [[], [], []];
        }

        return [[[
            'key' => 'projection',
            'collected_at' => $collectedAt,
            'pages' => $pages,
            'issues' => $issues,
        ]], $titles, $descriptions];
    }

    /** @return array<string, mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function isoDate(mixed $value): string
    {
        try {
            return CarbonImmutable::parse((string) $value)->utc()->toIso8601String();
        } catch (Throwable) {
            return (string) $value;
        }
    }
}
