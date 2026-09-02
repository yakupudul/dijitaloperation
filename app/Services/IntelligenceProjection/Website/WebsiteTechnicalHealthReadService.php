<?php

namespace App\Services\IntelligenceProjection\Website;

use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsiteEntityProfile;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

final class WebsiteTechnicalHealthReadService
{
    private const int PER_PAGE = 25;

    /** @var array<string, int> */
    private const array SEVERITY_WEIGHT = [
        'critical' => 5,
        'high' => 4,
        'medium' => 3,
        'low' => 2,
        'info' => 1,
    ];

    public function __construct(
        private readonly WebsiteProjectionReadService $projection,
    ) {}

    /** @return array<string, mixed> */
    public function workspace(
        DigitalAsset $asset,
        string $search = '',
        string $filter = 'all',
        int $page = 1,
        ?int $selectedProfileId = null,
    ): array {
        $projection = $this->projection->summary($asset);
        $publicCoverage = data_get($projection, 'coverage_state.website', []);
        $infrastructure = $this->infrastructure($asset);
        $profiles = $this->projection->pages($asset)
            ->orderByDesc('last_observed_at')
            ->get(['id', 'preferred_url', 'source_states', 'last_observed_at'])
            ->map(fn (WebsitePageProfile $profile): array => $this->present($profile))
            ->filter(static fn (array $row): bool => $row['available'])
            ->values();
        $pageDataAvailable = $profiles->isNotEmpty();

        $filtered = $this->applyFilters($profiles, $search, $filter);
        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);
        $rows = $filtered->forPage($page, self::PER_PAGE)->values();

        $selected = null;
        if ($selectedProfileId !== null) {
            $profile = $this->projection->pages($asset)->whereKey($selectedProfileId)->first();
            if ($profile instanceof WebsitePageProfile) {
                $candidate = $this->present($profile, true);
                $selected = $candidate['available'] ? $candidate : null;
            }
        }

        return [
            'available' => $pageDataAvailable || $infrastructure['available'],
            'page_data_available' => $pageDataAvailable,
            'projection' => $projection,
            'coverage' => [
                'state' => (string) data_get($publicCoverage, 'state', 'not_collected'),
                'watermark' => data_get($publicCoverage, 'watermark'),
                'page_count' => data_get($publicCoverage, 'page_count'),
                'html_snapshot_count' => data_get($publicCoverage, 'html_snapshot_count'),
            ],
            'summary' => $this->summary($profiles, $pageDataAvailable),
            'severity_counts' => $this->severityCounts($profiles, $pageDataAvailable),
            'issue_groups' => $this->issueGroups($profiles),
            'infrastructure' => $infrastructure,
            'rows' => $rows->all(),
            'selected' => $selected,
            'pagination' => [
                'page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total === 0 ? 0 : (($page - 1) * self::PER_PAGE) + 1,
                'to' => min($total, $page * self::PER_PAGE),
            ],
        ];
    }

    /** @param Collection<int, array<string, mixed>> $profiles @return array<string, int|null> */
    private function summary(Collection $profiles, bool $available): array
    {
        if (! $available) {
            return [
                'observed_pages' => null,
                'reachable_pages' => null,
                'affected_pages' => null,
                'critical_high_observations' => null,
                'pagespeed_measured' => null,
            ];
        }

        $performanceCollected = $profiles->contains(
            static fn (array $row): bool => $row['performance']['measurements'] !== [],
        );

        return [
            'observed_pages' => $profiles->count(),
            'reachable_pages' => $profiles->filter(static fn (array $row): bool => $row['http']['reachable'] === true)->count(),
            'affected_pages' => $profiles->filter(static fn (array $row): bool => $row['issues'] !== [])->count(),
            'critical_high_observations' => $profiles->sum(static fn (array $row): int => count(array_filter(
                $row['issues'],
                static fn (array $issue): bool => in_array($issue['severity'], ['critical', 'high'], true),
            ))),
            'pagespeed_measured' => $performanceCollected
                ? $profiles->filter(static fn (array $row): bool => $row['performance']['available'])->count()
                : null,
        ];
    }

    /** @param Collection<int, array<string, mixed>> $profiles @return array<string, int|null> */
    private function severityCounts(Collection $profiles, bool $available): array
    {
        if (! $available) {
            return array_fill_keys(array_keys(self::SEVERITY_WEIGHT), null);
        }

        $counts = array_fill_keys(array_keys(self::SEVERITY_WEIGHT), 0);
        foreach ($profiles as $profile) {
            foreach ($profile['issues'] as $issue) {
                if (array_key_exists($issue['severity'], $counts)) {
                    $counts[$issue['severity']]++;
                }
            }
        }

        return $counts;
    }

    /** @param Collection<int, array<string, mixed>> $profiles @return list<array<string, mixed>> */
    private function issueGroups(Collection $profiles): array
    {
        $groups = [];
        foreach ($profiles as $profile) {
            foreach ($profile['issues'] as $issue) {
                $code = $issue['code'];
                $groups[$code] ??= [
                    'code' => $code,
                    'label' => $issue['label'],
                    'category' => $issue['category'],
                    'severity' => $issue['severity'],
                    'observations' => 0,
                    'pages' => [],
                ];
                $groups[$code]['observations']++;
                $groups[$code]['pages'][$profile['id']] = true;
                if ($this->severityWeight($issue['severity']) > $this->severityWeight($groups[$code]['severity'])) {
                    $groups[$code]['severity'] = $issue['severity'];
                }
            }
        }

        $groups = array_values(array_map(static function (array $group): array {
            $group['pages'] = count($group['pages']);

            return $group;
        }, $groups));

        usort($groups, function (array $left, array $right): int {
            return [$this->severityWeight($right['severity']), $right['pages']]
                <=> [$this->severityWeight($left['severity']), $left['pages']];
        });

        return $groups;
    }

    /** @param Collection<int, array<string, mixed>> $profiles @return Collection<int, array<string, mixed>> */
    private function applyFilters(Collection $profiles, string $search, string $filter): Collection
    {
        $search = mb_strtolower(trim($search));
        if ($search !== '') {
            $profiles = $profiles->filter(static function (array $row) use ($search): bool {
                return str_contains(mb_strtolower($row['url']), $search)
                    || str_contains(mb_strtolower((string) ($row['title'] ?? '')), $search);
            });
        }

        return $profiles->filter(function (array $row) use ($filter): bool {
            $categories = array_column($row['issues'], 'category');

            return match ($filter) {
                'issues' => $row['issues'] !== [],
                'critical_high' => in_array($row['highest_severity'], ['critical', 'high'], true),
                'accessibility' => in_array('accessibility', $categories, true),
                'redirects' => in_array('redirects', $categories, true),
                'metadata' => in_array('metadata', $categories, true),
                'indexability' => in_array('indexability', $categories, true),
                'application' => in_array('application', $categories, true),
                'schema' => $row['schema']['available'],
                'performance' => $row['performance']['available'],
                'clean' => $row['issues'] === [],
                default => true,
            };
        })->values();
    }

    /** @return array<string, mixed> */
    private function present(WebsitePageProfile $profile, bool $detailed = false): array
    {
        $website = data_get($profile->source_states, 'website');
        if (! is_array($website)) {
            return ['id' => (int) $profile->getKey(), 'available' => false];
        }

        $http = is_array($website['http'] ?? null) ? $website['http'] : [];
        $head = is_array($website['document_head'] ?? null) ? $website['document_head'] : [];
        $headings = is_array($website['headings'] ?? null) ? $website['headings'] : [];
        $schema = is_array($website['structured_data'] ?? null) ? $website['structured_data'] : [];
        $content = is_array($website['content'] ?? null) ? $website['content'] : [];
        $links = is_array($website['links'] ?? null) ? $website['links'] : [];
        $linksObserved = filled($links['observed_at'] ?? null);
        $issues = collect(is_array($website['crawl_issues'] ?? null) ? $website['crawl_issues'] : [])
            ->filter(static fn (mixed $issue): bool => is_array($issue) && filled($issue['code'] ?? null))
            ->map(fn (array $issue): array => $this->presentIssue($issue))
            ->values()
            ->all();
        $performance = $this->performance(is_array($website['performance'] ?? null) ? $website['performance'] : []);
        $highestSeverity = $this->highestSeverity($issues);

        $row = [
            'id' => (int) $profile->getKey(),
            'available' => true,
            'url' => (string) $profile->preferred_url,
            'title' => is_string($head['title'] ?? null) && trim($head['title']) !== '' ? trim($head['title']) : null,
            'last_observed_at' => $profile->last_observed_at,
            'last_observed_human' => $profile->last_observed_at?->diffForHumans(),
            'highest_severity' => $highestSeverity,
            'issues' => $issues,
            'http' => [
                'status_code' => is_numeric($http['status_code'] ?? null) ? (int) $http['status_code'] : null,
                'reachable' => is_bool($http['reachable'] ?? null) ? $http['reachable'] : null,
                'redirect_count' => is_numeric($http['redirect_count'] ?? null) ? (int) $http['redirect_count'] : null,
                'final_url' => $http['final_url'] ?? null,
                'content_type' => $http['content_type'] ?? null,
                'error' => $http['error'] ?? null,
                'observed_at' => $http['observed_at'] ?? null,
            ],
            'metadata' => [
                'title_present' => is_bool($head['title_present'] ?? null) ? $head['title_present'] : null,
                'meta_description_present' => array_key_exists('meta_description', $head) ? filled($head['meta_description']) : null,
                'h1_present' => is_bool($headings['h1_present'] ?? null) ? $headings['h1_present'] : null,
                'canonical_count' => is_array($head['canonical_hrefs'] ?? null) ? count($head['canonical_hrefs']) : null,
                'robots' => $head['robots'] ?? null,
            ],
            'schema' => [
                'available' => $schema !== [],
                'block_count' => is_numeric($schema['block_count'] ?? null) ? (int) $schema['block_count'] : null,
                'valid_blocks' => is_numeric($schema['valid_blocks'] ?? null) ? (int) $schema['valid_blocks'] : null,
                'malformed_blocks' => is_numeric($schema['malformed_blocks'] ?? null) ? (int) $schema['malformed_blocks'] : null,
                'types' => is_array($schema['types'] ?? null) ? $schema['types'] : [],
            ],
            'content' => [
                'word_count' => is_numeric($content['word_count'] ?? null) ? (int) $content['word_count'] : null,
                'thin_content_hint' => is_bool($content['thin_content_hint'] ?? null) ? $content['thin_content_hint'] : null,
                'language' => $content['language'] ?? null,
            ],
            'links' => [
                'internal' => $linksObserved && is_numeric($links['internal'] ?? null) ? (int) $links['internal'] : null,
                'external' => $linksObserved && is_numeric($links['external'] ?? null) ? (int) $links['external'] : null,
            ],
            'performance' => $performance,
        ];

        if ($detailed) {
            $row['document_head'] = $head;
            $row['headings'] = $headings;
            $row['source_records'] = is_array($website['source_records'] ?? null) ? $website['source_records'] : [];
        }

        return $row;
    }

    /** @param list<array<string, mixed>> $measurements @return array<string, mixed> */
    private function performance(array $measurements): array
    {
        $rows = collect($measurements)
            ->filter(static fn (mixed $measurement): bool => is_array($measurement))
            ->map(static function (array $measurement): array {
                $facts = is_array($measurement['measurements'] ?? null) ? $measurement['measurements'] : [];

                return [
                    'strategy' => $measurement['strategy'] ?? null,
                    'observed_at' => $measurement['observed_at'] ?? null,
                    'lcp_ms' => is_numeric($facts['lcp_ms'] ?? null) ? (float) $facts['lcp_ms'] : null,
                    'final_url' => $facts['final_url'] ?? null,
                    'fetch_time' => $facts['fetch_time'] ?? null,
                    'lab_data' => $facts['lab_data'] ?? null,
                ];
            })
            ->values()
            ->all();

        return [
            'available' => collect($rows)->contains(static fn (array $row): bool => $row['lcp_ms'] !== null),
            'primary_lcp_ms' => data_get(collect($rows)->first(static fn (array $row): bool => $row['lcp_ms'] !== null), 'lcp_ms'),
            'measurements' => $rows,
        ];
    }

    /** @param array<string, mixed> $issue @return array<string, mixed> */
    private function presentIssue(array $issue): array
    {
        $code = strtoupper((string) $issue['code']);
        $severity = strtolower((string) ($issue['severity'] ?? 'info'));
        if (! array_key_exists($severity, self::SEVERITY_WEIGHT)) {
            $severity = 'info';
        }

        $translationKey = 'operator.website.technical_health.issue_codes.'.$code;
        $label = __($translationKey);

        return [
            'code' => $code,
            'label' => $label === $translationKey ? $code : $label,
            'severity' => $severity,
            'category' => $this->issueCategory($code),
            'observed_at' => $issue['observed_at'] ?? null,
            'source_record_id' => $issue['source_record_id'] ?? null,
        ];
    }

    private function issueCategory(string $code): string
    {
        return match ($code) {
            'HTTP_5XX', 'HTTP_4XX', 'FETCH_FAILED' => 'accessibility',
            'REDIRECT_CHAIN', 'EXTERNAL_REDIRECT' => 'redirects',
            'WORDPRESS_CRITICAL_ERROR', 'WORDPRESS_DATABASE_ERROR', 'APPLICATION_ERROR_PAGE', 'SOFT_404' => 'application',
            'MISSING_TITLE', 'MISSING_META_DESCRIPTION', 'MISSING_H1', 'MULTIPLE_H1' => 'metadata',
            'CANONICAL_MISSING', 'CANONICAL_MULTIPLE', 'NOINDEX' => 'indexability',
            default => 'other',
        };
    }

    /** @param list<array<string, mixed>> $issues */
    private function highestSeverity(array $issues): ?string
    {
        $highest = null;
        foreach ($issues as $issue) {
            if ($this->severityWeight($issue['severity']) > $this->severityWeight($highest)) {
                $highest = $issue['severity'];
            }
        }

        return $highest;
    }

    private function severityWeight(?string $severity): int
    {
        return self::SEVERITY_WEIGHT[$severity ?? ''] ?? 0;
    }

    /** @return array<string, mixed> */
    private function infrastructure(DigitalAsset $asset): array
    {
        $entity = $this->projection->entities($asset)
            ->get(['source_states'])
            ->first(static fn (WebsiteEntityProfile $profile): bool => is_array(data_get($profile->source_states, 'website.infra')));
        $infra = $entity instanceof WebsiteEntityProfile ? data_get($entity->source_states, 'website.infra') : null;
        $facts = is_array(data_get($infra, 'facts')) ? data_get($infra, 'facts') : [];
        $tls = is_array(data_get($facts, 'tls')) ? data_get($facts, 'tls') : [];
        $validTo = is_string($tls['valid_to'] ?? null) ? $tls['valid_to'] : null;
        $expiresInDays = null;
        if ($validTo !== null) {
            try {
                $expiresInDays = (int) now()->startOfDay()->diffInDays(CarbonImmutable::parse($validTo)->startOfDay(), false);
            } catch (Throwable) {
                $validTo = null;
            }
        }

        return [
            'available' => $facts !== [],
            'host' => $facts['host'] ?? null,
            'tls_present' => is_bool($facts['present'] ?? null) ? $facts['present'] : null,
            'issuer' => $tls['issuer_common_name'] ?? null,
            'valid_from' => $tls['valid_from'] ?? null,
            'valid_to' => $validTo,
            'expires_in_days' => $expiresInDays,
            'error' => $tls['error_class'] ?? null,
            'observed_at' => data_get($infra, 'observed_at'),
        ];
    }
}
