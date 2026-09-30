<?php

namespace App\Services\DataPool;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Latest-state upkeep of the per-page Website tables. New data still goes through the DatasetWritePipeline; this
 * only (a) moves an unchanged page's stored rows to the new observation instead of appending a copy and
 * (b) removes a page's link edges from earlier fetches once its current edges are stored.
 */
final class WebsitePageStateStore
{
    /** Per-page tables: table => page URL column. */
    public const PAGE_TABLES = [
        'website_http_snapshot' => 'url',
        'website_html_snapshot' => 'url',
        'website_metadata_snapshot' => 'url',
        'website_heading_snapshot' => 'url',
        'website_schema_snapshot' => 'url',
        'website_content_stats' => 'url',
        'website_crawl_issue_snapshot' => 'url',
        'website_link_edge' => 'source_url',
    ];

    /** @var array<string, bool> */
    private array $tables = [];

    /**
     * The latest stored HTTP response of a page, unless this observation already wrote one (a retried step).
     *
     * @return array<string, mixed>|null its metadata
     */
    public function latestResponse(int $assetId, string $httpUrl, string $observedAt): ?array
    {
        if (! $this->hasTable('website_http_snapshot')) {
            return null;
        }
        $query = fn () => DB::table('website_http_snapshot')->where('digital_asset_id', $assetId)->where('url', $httpUrl);
        if ($query()->where('observed_at', '>=', $observedAt)->exists()) {
            return null;
        }
        $latest = $query()->orderByDesc('observed_at')->orderByDesc('id')->first(['metadata']);
        if ($latest === null) {
            return null;
        }
        $metadata = is_string($latest->metadata) ? json_decode($latest->metadata, true) : $latest->metadata;

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * ETag / Last-Modified of each page's latest stored 200 response, for conditional requests (a 304 means unchanged).
     *
     * @param  list<string>  $httpUrls
     * @return array<string, array{etag: ?string, last_modified: ?string}>
     */
    public function validators(int $assetId, array $httpUrls): array
    {
        if ($httpUrls === [] || ! $this->hasTable('website_http_snapshot')) {
            return [];
        }
        $validators = [];
        foreach ($httpUrls as $url) {
            $latest = DB::table('website_http_snapshot')->where('digital_asset_id', $assetId)->where('url', $url)
                ->orderByDesc('observed_at')->orderByDesc('id')->value('metadata');
            $metadata = is_string($latest) ? json_decode($latest, true) : $latest;
            if (! is_array($metadata) || (int) ($metadata['status_code'] ?? 0) !== 200 || empty($metadata['body_sha256'])) {
                continue;
            }
            $etag = is_string($metadata['etag'] ?? null) ? $metadata['etag'] : null;
            $lastModified = is_string($metadata['last_modified'] ?? null) ? $metadata['last_modified'] : null;
            if ($etag !== null || $lastModified !== null) {
                $validators[$url] = ['etag' => $etag, 'last_modified' => $lastModified];
            }
        }

        return $validators;
    }

    public function latestHtmlHash(int $assetId, string $url): ?string
    {
        if (! $this->hasTable('website_html_snapshot')) {
            return null;
        }
        $hash = DB::table('website_html_snapshot')->where('digital_asset_id', $assetId)->where('url', $url)
            ->orderByDesc('observed_at')->orderByDesc('id')->value('html_hash');

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * Moves the latest rows of an unchanged page (every per-page table, any of its URL spellings) to this observation.
     *
     * @param  list<string>  $urls
     */
    public function touchUnchangedPage(int $assetId, array $urls, string $observedAt, int $collectionRunId, int $datasetRunId): void
    {
        $touch = [
            'observed_at' => $observedAt,
            'last_collected_at' => now(),
            'last_collection_run_id' => $collectionRunId,
            'last_dataset_run_id' => $datasetRunId,
            'updated_at' => now(),
        ];
        foreach (self::PAGE_TABLES as $table => $column) {
            if (! $this->hasTable($table)) {
                continue;
            }
            $latestRows = DB::table($table)->where('digital_asset_id', $assetId)->whereIn($column, $urls)
                ->groupBy($column)->selectRaw($column.' as page_url, MAX(observed_at) as latest_observed_at')->get();
            foreach ($latestRows as $row) {
                $rows = DB::table($table)->where('digital_asset_id', $assetId)->where($column, $row->page_url)
                    ->where('observed_at', $row->latest_observed_at)->where('observed_at', '<', $observedAt);
                if ($table !== 'website_html_snapshot') {
                    $rows->update($touch);

                    continue;
                }
                // The refetch found the same HTML: record it as unchanged, like an appended copy would have been.
                foreach ($rows->get(['id', 'html_hash', 'metadata']) as $html) {
                    $metadata = is_string($html->metadata) ? json_decode($html->metadata, true) : $html->metadata;
                    $metadata = is_array($metadata) ? $metadata : [];
                    $metadata['semantic_change_state'] = 'no_meaningful_change';
                    $metadata['semantic_changed_fields'] = [];
                    DB::table($table)->where('id', $html->id)->update($touch + [
                        'change_state' => 'unchanged',
                        'previous_html_hash' => $html->html_hash,
                        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    ]);
                }
            }
        }
    }

    /** Removes a page's link edges from fetches before this observation (its current edges are already stored). */
    public function dropEarlierLinkEdges(int $assetId, string $sourceUrl, string $observedAt): void
    {
        if (! $this->hasTable('website_link_edge')) {
            return;
        }
        DB::table('website_link_edge')->where('digital_asset_id', $assetId)->where('source_url', $sourceUrl)
            ->where('observed_at', '<', $observedAt)->delete();
    }

    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= Schema::hasTable($table);
    }
}
