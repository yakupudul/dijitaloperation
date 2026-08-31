<?php

namespace App\Services\IntelligenceProjection\Website;

use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class WebsitePagesContentReadService
{
    private const int PER_PAGE = 25;

    public function __construct(
        private readonly WebsiteProjectionReadService $projection,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function workspace(
        DigitalAsset $asset,
        string $search = '',
        string $filter = 'all',
        string $source = 'all',
        int $page = 1,
        ?int $selectedProfileId = null,
    ): array {
        $summary = $this->projection->summary($asset);
        $base = $this->projection->pages($asset);

        $counts = $this->counts($base, (bool) $summary['available']);
        $query = $this->applyFilters($base, $search, $filter, $source);
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);

        $profiles = $query
            ->orderByDesc('last_observed_at')
            ->orderBy('preferred_url')
            ->forPage($page, self::PER_PAGE)
            ->get();

        $selected = null;
        if ($selectedProfileId !== null) {
            $profile = $this->projection->pages($asset)->whereKey($selectedProfileId)->first();
            $selected = $profile instanceof WebsitePageProfile ? $this->present($profile, true) : null;
        }

        return [
            'projection' => $summary,
            'coverage' => $this->coverage($summary),
            'counts' => $counts,
            'rows' => $profiles->map(fn (WebsitePageProfile $profile): array => $this->present($profile))->values()->all(),
            'selected' => $selected,
            'pagination' => [
                'page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total === 0 ? 0 : (($page - 1) * self::PER_PAGE) + 1,
                'to' => min($total, $page * self::PER_PAGE),
            ],
            'filters' => [
                'search' => trim($search),
                'filter' => $filter,
                'source' => $source,
            ],
        ];
    }

    /** @return array<string, int|null> */
    private function counts(Builder $base, bool $available): array
    {
        if (! $available) {
            return [
                'pages' => null,
                'html_captured' => null,
                'wordpress_objects' => null,
                'changed' => null,
                'matched' => null,
                'cms_without_html' => null,
                'public_without_cms' => null,
            ];
        }

        return [
            'pages' => (clone $base)->count(),
            'html_captured' => (clone $base)->whereNotNull('source_states->website->html->html_hash')->count(),
            'wordpress_objects' => (clone $base)->whereNotNull('source_states->wordpress->object->id')->count(),
            'changed' => (clone $base)->where('source_states->website->html->change_state', 'changed')->count(),
            'matched' => (clone $base)
                ->whereNotNull('source_states->website->url')
                ->whereNotNull('source_states->wordpress->object->id')
                ->count(),
            'cms_without_html' => (clone $base)
                ->whereNotNull('source_states->wordpress->object->id')
                ->whereNull('source_states->website->html->html_hash')
                ->count(),
            'public_without_cms' => (clone $base)
                ->whereNotNull('source_states->website->url')
                ->whereNull('source_states->wordpress->object->id')
                ->count(),
        ];
    }

    private function applyFilters(Builder $query, string $search, string $filter, string $source): Builder
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
            'matched' => $query
                ->whereNotNull('source_states->website->url')
                ->whereNotNull('source_states->wordpress->object->id'),
            'changed' => $query->where('source_states->website->html->change_state', 'changed'),
            'published' => $query->where('source_states->wordpress->object->status', 'publish'),
            'draft' => $query->where('source_states->wordpress->object->status', 'draft'),
            'cms_without_html' => $query
                ->whereNotNull('source_states->wordpress->object->id')
                ->whereNull('source_states->website->html->html_hash'),
            'public_without_cms' => $query
                ->whereNotNull('source_states->website->url')
                ->whereNull('source_states->wordpress->object->id'),
            default => null,
        };

        return $query;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<array<string, mixed>>
     */
    private function coverage(array $summary): array
    {
        $coverage = is_array($summary['coverage_state'] ?? null) ? $summary['coverage_state'] : [];

        return collect([
            'website' => __('operator.website.pages_content.sources.website'),
            'wordpress' => __('operator.website.pages_content.sources.wordpress'),
            'gsc' => __('operator.website.pages_content.sources.gsc'),
            'ga4' => __('operator.website.pages_content.sources.ga4'),
        ])->map(function (string $label, string $key) use ($coverage): array {
            $state = (string) data_get($coverage, $key.'.state', 'not_collected');

            return [
                'key' => $key,
                'label' => $label,
                'state' => $state,
                'state_label' => __('operator.website.pages_content.states.'.$state),
                'watermark' => data_get($coverage, $key.'.watermark'),
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function present(WebsitePageProfile $profile, bool $detailed = false): array
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

        $sources = array_values(array_filter([
            $website !== [] ? 'website' : null,
            $wordpress !== [] ? 'wordpress' : null,
            $gsc !== [] ? 'gsc' : null,
            $ga4 !== [] ? 'ga4' : null,
        ]));
        $title = $this->firstString(
            $cmsObject['title'] ?? null,
            $documentHead['title'] ?? null,
            data_get($ga4PageContent, 'titles.0'),
        );

        $row = [
            'id' => (int) $profile->getKey(),
            'url' => (string) $profile->preferred_url,
            'title' => $title,
            'sources' => $sources,
            'last_observed_at' => $profile->last_observed_at,
            'last_observed_human' => $profile->last_observed_at?->diffForHumans(),
            'public' => [
                'available' => $website !== [],
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
