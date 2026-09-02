<?php

namespace App\Services\IntelligenceProjection\Website;

use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Support\IntelligenceProjection\Website\WebsitePageFamilyClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class WebsitePagesContentReadService
{
    private const int PER_PAGE = 25;

    public function __construct(
        private readonly WebsiteProjectionReadService $projection,
        private readonly WebsitePageFamilyClassifier $families,
    ) {}

    /** @return array<string, mixed> */
    public function workspace(
        DigitalAsset $asset,
        string $search = '',
        string $filter = 'all',
        string $source = 'all',
        string $sort = 'recent',
        int $page = 1,
        ?int $selectedProfileId = null,
    ): array {
        $summary = $this->projection->summary($asset);
        $base = $this->projection->pages($asset);
        $familyIndex = $this->familyIndex($asset, $summary);
        $counts = $this->counts($base, (bool) $summary['available'], $familyIndex);
        $query = $this->applyFilters($base, $search, $filter, $source, $familyIndex['pagination_ids']);

        if ($filter === 'families') {
            [$rows, $pagination] = $this->familyRows($query, $sort, $page, $familyIndex['counts']);
        } else {
            $query = $this->applySort($query, $sort);
            $total = (clone $query)->count();
            $pagination = $this->pagination($total, $page);
            $profiles = $query->forPage($pagination['page'], self::PER_PAGE)->get();
            $rows = $profiles
                ->map(fn (WebsitePageProfile $profile): array => $this->present($profile, false, $familyIndex['counts']))
                ->values()
                ->all();
        }

        $selected = null;
        if ($selectedProfileId !== null) {
            $profile = $this->projection->pages($asset)->whereKey($selectedProfileId)->first();
            if ($profile instanceof WebsitePageProfile) {
                $selected = $this->present($profile, true, $familyIndex['counts']);
                $selected['versions'] = $this->versions($asset, $selected);
            }
        }

        return [
            'projection' => $summary,
            'coverage' => $this->coverage($summary),
            'counts' => $counts,
            'family_stats' => [
                'families' => count($familyIndex['counts']),
                'multi_page_families' => count(array_filter($familyIndex['counts'], static fn (int $count): bool => $count > 1)),
                'pagination_pages' => $familyIndex['pagination_pages'],
            ],
            'rows' => $rows,
            'selected' => $selected,
            'pagination' => $pagination,
            'filters' => [
                'search' => trim($search),
                'filter' => $filter,
                'source' => $source,
                'sort' => $sort,
            ],
        ];
    }

    /**
     * @param  array{counts:array<string,int>,pagination_pages:int,pagination_ids:list<int>}  $familyIndex
     * @return array<string, int|float|null>
     */
    private function counts(Builder $base, bool $available, array $familyIndex): array
    {
        if (! $available) {
            return array_fill_keys([
                'pages', 'public_pages', 'html_captured', 'wordpress_pages', 'matched',
                'cms_without_public', 'public_without_cms', 'platform_only', 'gsc_pages',
                'ga4_pages', 'semantic_compared', 'meaningful_changed', 'cms_mismatch',
                'html_coverage_percent', 'multi_page_families', 'expected_family_members', 'cms_review',
            ], null);
        }

        $publicPages = (clone $base)->whereNotNull('source_states->website->url')->count();
        $htmlCaptured = (clone $base)->whereNotNull('source_states->website->html->html_hash')->count();
        $matched = (clone $base)
            ->whereNotNull('source_states->website->url')
            ->whereNotNull('source_states->wordpress->object->id')
            ->count();
        $cmsWithoutPublic = (clone $base)
            ->whereNotNull('source_states->wordpress->object->id')
            ->whereNull('source_states->website->url')
            ->count();
        $publicWithoutCmsIds = (clone $base)
            ->whereNotNull('source_states->website->url')
            ->whereNull('source_states->wordpress->object->id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $publicWithoutCms = count($publicWithoutCmsIds);
        $expectedFamilyMembers = count(array_intersect($publicWithoutCmsIds, $familyIndex['pagination_ids']));
        $semanticAvailable = (clone $base)
            ->whereNotNull('source_states->website->html->semantic_change_state')
            ->exists();

        return [
            'pages' => (clone $base)->count(),
            'public_pages' => $publicPages,
            'html_captured' => $htmlCaptured,
            'wordpress_pages' => (clone $base)->whereNotNull('source_states->wordpress->object->id')->count(),
            'matched' => $matched,
            'cms_without_public' => $cmsWithoutPublic,
            'public_without_cms' => $publicWithoutCms,
            'platform_only' => (clone $base)
                ->whereNull('source_states->website->url')
                ->whereNull('source_states->wordpress->object->id')
                ->where(function (Builder $query): void {
                    $query->whereNotNull('source_states->gsc->state')
                        ->orWhereNotNull('source_states->ga4->state');
                })
                ->count(),
            'gsc_pages' => (clone $base)->whereNotNull('source_states->gsc->state')->count(),
            'ga4_pages' => (clone $base)->whereNotNull('source_states->ga4->state')->count(),
            'semantic_compared' => $semanticAvailable
                ? (clone $base)->whereIn('source_states->website->html->semantic_change_state', ['meaningful_change', 'no_meaningful_change'])->count()
                : null,
            'meaningful_changed' => $semanticAvailable
                ? (clone $base)->where('source_states->website->html->semantic_change_state', 'meaningful_change')->count()
                : null,
            'cms_mismatch' => $cmsWithoutPublic + $publicWithoutCms,
            'expected_family_members' => $expectedFamilyMembers,
            'cms_review' => $cmsWithoutPublic + max(0, $publicWithoutCms - $expectedFamilyMembers),
            'html_coverage_percent' => $publicPages > 0 ? round(($htmlCaptured / $publicPages) * 100, 1) : null,
            'multi_page_families' => count(array_filter($familyIndex['counts'], static fn (int $count): bool => $count > 1)),
        ];
    }

    /** @param list<int> $paginationIds */
    private function applyFilters(Builder $query, string $search, string $filter, string $source, array $paginationIds): Builder
    {
        $search = trim($search);
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $nested) use ($like): void {
                $nested
                    ->whereLike('preferred_url', $like, caseSensitive: false)
                    ->orWhereLike('source_states->wordpress->object->title', $like, caseSensitive: false)
                    ->orWhereLike('source_states->website->document_head->title', $like, caseSensitive: false);
            });
        }

        match ($source) {
            'website' => $query->whereNotNull('source_states->website->url'),
            'wordpress' => $query->whereNotNull('source_states->wordpress->object->id'),
            'gsc' => $query->whereNotNull('source_states->gsc->state'),
            'ga4' => $query->whereNotNull('source_states->ga4->state'),
            default => null,
        };

        match ($filter) {
            'public' => $query->whereNotNull('source_states->website->url'),
            'search_visible' => $query->whereNotNull('source_states->gsc->state'),
            'traffic' => $query->whereNotNull('source_states->ga4->state'),
            'meaningful_changed' => $query->where('source_states->website->html->semantic_change_state', 'meaningful_change'),
            'cms_mismatch' => $query->where(function (Builder $nested) use ($paginationIds): void {
                $nested->where(function (Builder $cms): void {
                    $cms->whereNotNull('source_states->wordpress->object->id')
                        ->whereNull('source_states->website->url');
                })->orWhere(function (Builder $public) use ($paginationIds): void {
                    $public->whereNotNull('source_states->website->url')
                        ->whereNull('source_states->wordpress->object->id');
                    if ($paginationIds !== []) {
                        $public->whereNotIn('id', $paginationIds);
                    }
                });
            }),
            'matched' => $query
                ->whereNotNull('source_states->website->url')
                ->whereNotNull('source_states->wordpress->object->id'),
            'raw_changed', 'changed' => $query->where('source_states->website->html->change_state', 'changed'),
            'published' => $query->where('source_states->wordpress->object->status', 'publish'),
            'draft' => $query->where('source_states->wordpress->object->status', 'draft'),
            'cms_without_public' => $query
                ->whereNotNull('source_states->wordpress->object->id')
                ->whereNull('source_states->website->url'),
            'public_without_cms' => $query
                ->whereNotNull('source_states->website->url')
                ->whereNull('source_states->wordpress->object->id'),
            default => null,
        };

        return $query;
    }

    private function applySort(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'url' => $query->orderBy('preferred_url'),
            'oldest' => $query->orderBy('last_observed_at')->orderBy('preferred_url'),
            default => $query->orderByDesc('last_observed_at')->orderBy('preferred_url'),
        };
    }

    /** @param array<string,int> $familyCounts @return array{0:list<array<string,mixed>>,1:array<string,int>} */
    private function familyRows(Builder $query, string $sort, int $page, array $familyCounts): array
    {
        $groups = $this->applySort($query, $sort)
            ->get()
            ->map(fn (WebsitePageProfile $profile): array => $this->present($profile, false, $familyCounts))
            ->filter(static fn (array $row): bool => (int) data_get($row, 'family.member_count', 1) > 1)
            ->groupBy('family.key')
            ->map(function (Collection $members): array {
                $representative = $members->first(static fn (array $row): bool => data_get($row, 'family.page_number') === null)
                    ?? $members->sortBy('family.page_number')->first();
                $representative['family']['grouped'] = true;

                return $representative;
            })
            ->values();
        $pagination = $this->pagination($groups->count(), $page);

        return [$groups->forPage($pagination['page'], self::PER_PAGE)->values()->all(), $pagination];
    }

    /** @return array<string, int> */
    private function pagination(int $total, int $page): array
    {
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);

        return [
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'total' => $total,
            'last_page' => $lastPage,
            'from' => $total === 0 ? 0 : (($page - 1) * self::PER_PAGE) + 1,
            'to' => min($total, $page * self::PER_PAGE),
        ];
    }

    /** @param array<string,mixed> $summary @return array{counts:array<string,int>,pagination_pages:int,pagination_ids:list<int>} */
    private function familyIndex(DigitalAsset $asset, array $summary): array
    {
        $runId = (int) ($summary['projection_run_id'] ?? 0);

        return Cache::remember(
            'website-pages-family-index:v2:'.$asset->getKey().':'.$runId,
            now()->addMinutes(10),
            function () use ($asset): array {
                $counts = [];
                $paginationPages = 0;
                $paginationIds = [];
                $this->projection->pages($asset)
                    ->get(['id', 'preferred_url'])
                    ->each(function (WebsitePageProfile $profile) use (&$counts, &$paginationPages, &$paginationIds): void {
                        $family = $this->families->classify((string) $profile->preferred_url);
                        $counts[$family['key']] = ($counts[$family['key']] ?? 0) + 1;
                        if ($family['page_number'] !== null) {
                            $paginationPages++;
                            $paginationIds[] = (int) $profile->getKey();
                        }
                    });

                return ['counts' => $counts, 'pagination_pages' => $paginationPages, 'pagination_ids' => $paginationIds];
            },
        );
    }

    /** @param array<string,mixed> $summary @return list<array<string,mixed>> */
    private function coverage(array $summary): array
    {
        $coverage = is_array($summary['coverage_state'] ?? null) ? $summary['coverage_state'] : [];
        $sourceErrors = is_array($summary['source_errors'] ?? null) ? $summary['source_errors'] : [];

        return collect([
            'website' => __('operator.website.pages_content.sources.website'),
            'wordpress' => __('operator.website.pages_content.sources.wordpress'),
            'gsc' => __('operator.website.pages_content.sources.gsc'),
            'ga4' => __('operator.website.pages_content.sources.ga4'),
        ])->map(function (string $label, string $key) use ($coverage, $sourceErrors, $summary): array {
            $state = (string) data_get($coverage, $key.'.state', 'not_collected');
            $error = is_array($sourceErrors[$key] ?? null) ? $sourceErrors[$key] : [];

            return [
                'key' => $key,
                'label' => $label,
                'state' => $state,
                'state_label' => __('operator.website.pages_content.states.'.$state),
                'watermark' => data_get($coverage, $key.'.watermark'),
                'error_code' => $state === 'projection_failed' ? ($error['code'] ?? data_get($coverage, $key.'.error_code')) : null,
                'projection_run_id' => $state === 'projection_failed' ? ($summary['projection_run_id'] ?? null) : null,
            ];
        })->values()->all();
    }

    /** @param array<string,int> $familyCounts @return array<string,mixed> */
    private function present(WebsitePageProfile $profile, bool $detailed = false, array $familyCounts = []): array
    {
        $states = is_array($profile->source_states) ? $profile->source_states : [];
        $website = is_array($states['website'] ?? null) ? $states['website'] : [];
        $wordpress = is_array($states['wordpress'] ?? null) ? $states['wordpress'] : [];
        $gsc = is_array($states['gsc'] ?? null) ? $states['gsc'] : [];
        $ga4 = is_array($states['ga4'] ?? null) ? $states['ga4'] : [];
        $documentHead = is_array($website['document_head'] ?? null) ? $website['document_head'] : [];
        $headings = is_array($website['headings'] ?? null) ? $website['headings'] : [];
        $content = is_array($website['content'] ?? null) ? $website['content'] : [];
        $html = is_array($website['html'] ?? null) ? $website['html'] : [];
        $http = is_array($website['http'] ?? null) ? $website['http'] : [];
        $cmsObject = is_array($wordpress['object'] ?? null) ? $wordpress['object'] : [];
        $cmsContent = is_array($wordpress['content'] ?? null) ? $wordpress['content'] : [];
        $cmsSeo = is_array($wordpress['seo'] ?? null) ? $wordpress['seo'] : [];
        $ga4PageContent = is_array($ga4['page_content'] ?? null) ? $ga4['page_content'] : [];
        $url = (string) $profile->preferred_url;
        $family = $this->families->classify($url, is_string($cmsObject['type'] ?? null) ? $cmsObject['type'] : null);
        $family['member_count'] = $familyCounts[$family['key']] ?? 1;
        $family['grouped'] = false;
        $sources = array_values(array_filter([
            $website !== [] ? 'website' : null,
            $wordpress !== [] ? 'wordpress' : null,
            $gsc !== [] ? 'gsc' : null,
            $ga4 !== [] ? 'ga4' : null,
        ]));
        $title = $this->firstString($cmsObject['title'] ?? null, $documentHead['title'] ?? null, data_get($ga4PageContent, 'titles.0'));

        $row = [
            'id' => (int) $profile->getKey(),
            'url' => $url,
            'title' => $title,
            'family' => $family,
            'sources' => $sources,
            'last_observed_at' => $profile->last_observed_at,
            'last_observed_human' => $profile->last_observed_at?->diffForHumans(),
            'public' => [
                'available' => $website !== [],
                'url' => $website['url'] ?? null,
                'http_status' => isset($http['status_code']) ? (int) $http['status_code'] : null,
                'final_url' => $http['final_url'] ?? null,
                'title' => $documentHead['title'] ?? null,
                'meta_description' => $documentHead['meta_description'] ?? null,
                'canonical_hrefs' => is_array($documentHead['canonical_hrefs'] ?? null) ? $documentHead['canonical_hrefs'] : [],
                'robots' => $documentHead['robots'] ?? null,
                'h1' => $headings['h1'] ?? null,
                'word_count' => isset($content['word_count']) ? (int) $content['word_count'] : null,
                'language' => $content['language'] ?? null,
                'html_captured' => filled($html['html_hash'] ?? null),
                'html_hash' => $html['html_hash'] ?? null,
                'previous_html_hash' => $html['previous_html_hash'] ?? null,
                'html_bytes' => isset($html['html_bytes']) ? (int) $html['html_bytes'] : null,
                'change_state' => $html['change_state'] ?? null,
                'semantic_change_state' => $html['semantic_change_state'] ?? null,
                'semantic_changed_fields' => is_array($html['semantic_changed_fields'] ?? null) ? $html['semantic_changed_fields'] : [],
                'semantic_normalization_version' => $html['semantic_normalization_version'] ?? null,
                'raw_ingestion_object_id' => isset($html['raw_ingestion_object_id']) ? (int) $html['raw_ingestion_object_id'] : null,
                'observed_at' => $html['observed_at'] ?? $documentHead['observed_at'] ?? null,
            ],
            'wordpress' => [
                'available' => $wordpress !== [],
                'type' => $cmsObject['type'] ?? null,
                'object_id' => $cmsObject['id'] ?? null,
                'status' => $cmsObject['status'] ?? null,
                'slug' => $cmsObject['slug'] ?? null,
                'title' => $cmsObject['title'] ?? null,
                'modified_at' => $cmsObject['modified_at'] ?? null,
                'language' => $cmsObject['language'] ?? null,
                'template' => $cmsObject['template'] ?? null,
                'content_hash' => $cmsContent['content_hash'] ?? null,
                'content_length' => isset($cmsContent['content_length']) ? (int) $cmsContent['content_length'] : null,
                'builder_provider' => $cmsContent['builder_provider'] ?? null,
                'builder_content_hash' => $cmsContent['builder_content_hash'] ?? null,
                'seo' => $cmsSeo,
            ],
            'search' => [
                'available' => $gsc !== [],
                'period' => $gsc['period'] ?? null,
                'clicks' => $this->metric($gsc['metrics'] ?? [], 'gsc.clicks'),
                'impressions' => $this->metric($gsc['metrics'] ?? [], 'gsc.impressions'),
                'average_position' => $this->metric($gsc['metrics'] ?? [], 'gsc.average_position'),
                'top_queries' => is_array($gsc['top_queries'] ?? null) ? $gsc['top_queries'] : [],
            ],
            'behavior' => [
                'available' => $ga4 !== [],
                'period' => $ga4['period'] ?? null,
                'sessions' => $this->metric($ga4['metrics'] ?? [], 'ga4.sessions'),
                'engaged_sessions' => $this->metric($ga4['metrics'] ?? [], 'ga4.engaged_sessions'),
                'key_events' => $this->metric($ga4['metrics'] ?? [], 'ga4.key_events'),
                'engagement_rate' => $ga4['engagement_rate'] ?? null,
                'page_content' => $ga4PageContent,
            ],
        ];

        if ($detailed) {
            $row['public']['structured_data'] = $website['structured_data'] ?? null;
            $row['public']['links'] = $website['links'] ?? null;
            $row['public']['crawl_issues'] = $website['crawl_issues'] ?? [];
            $row['public']['performance'] = $website['performance'] ?? [];
            $row['wordpress']['translations'] = $cmsObject['translations'] ?? [];
            $row['source_states'] = $states;
        }

        return $row;
    }

    /** @param array<string,mixed> $selected @return list<array<string,mixed>> */
    private function versions(DigitalAsset $asset, array $selected): array
    {
        if (! Schema::hasTable('website_html_snapshot') || ! (bool) data_get($selected, 'public.available')) {
            return [];
        }

        $url = data_get($selected, 'public.url');
        if (! is_string($url) || $url === '') {
            return [];
        }

        return DB::table('website_html_snapshot')
            ->where('digital_asset_id', $asset->getKey())
            ->where('url', $url)
            ->orderByDesc('observed_at')
            ->limit(8)
            ->get(['id', 'html_hash', 'previous_html_hash', 'change_state', 'html_bytes', 'raw_ingestion_object_id', 'observed_at', 'metadata'])
            ->map(function (object $version): array {
                $metadata = $this->jsonArray($version->metadata ?? null);
                $observedAt = is_string($version->observed_at ?? null) ? CarbonImmutable::parse($version->observed_at) : null;

                return [
                    'id' => (int) $version->id,
                    'html_hash' => $version->html_hash,
                    'previous_html_hash' => $version->previous_html_hash,
                    'change_state' => $version->change_state,
                    'html_bytes' => (int) $version->html_bytes,
                    'raw_ingestion_object_id' => $version->raw_ingestion_object_id !== null ? (int) $version->raw_ingestion_object_id : null,
                    'observed_at' => $observedAt?->toIso8601String(),
                    'observed_human' => $observedAt?->diffForHumans(),
                    'semantic_change_state' => $metadata['semantic_change_state'] ?? null,
                    'semantic_changed_fields' => is_array($metadata['semantic_changed_fields'] ?? null) ? $metadata['semantic_changed_fields'] : [],
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string,mixed> */
    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param mixed $metrics */
    private function metric(mixed $metrics, string $metricId): int|float|string|bool|null
    {
        if (! is_array($metrics)) {
            return null;
        }

        $metric = Collection::make($metrics)->first(
            static fn (mixed $row): bool => is_array($row) && ($row['metric_id'] ?? null) === $metricId,
        );

        return is_array($metric) && in_array($metric['state'] ?? null, ['value', 'zero'], true)
            ? ($metric['value'] ?? null)
            : null;
    }

    private function firstString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
