<?php

namespace App\Services\Collection\Website;

use App\Services\DataPool\DataPoolStorageRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only operator projection for Website Intelligence.
 *
 * Keeps storage/provenance details out of the operator UI and translates the latest
 * collection into page inventory and site-health facts. When the physical tables expose
 * last_collection_run_id, the projection is scoped to the selected production run so old
 * materialized rows do not inflate the current result.
 */
final class WebsiteOperatorReadModel
{
    public function __construct(
        private readonly DataPoolStorageRegistry $storageRegistry,
    ) {}

    /** @return array<string, mixed> */
    public function forAsset(int $assetId, ?int $runId): array
    {
        return [
            'latest_pages' => $this->countLatestRows('website_url', $assetId, $runId),
            'site_health' => $this->siteHealth($assetId, $runId),
        ];
    }

    /** @return array{count:int,run_scoped:bool,available:bool} */
    private function countLatestRows(string $datasetId, int $assetId, ?int $runId): array
    {
        try {
            if (! $this->storageRegistry->hasPhysicalTable($datasetId)) {
                return ['count' => 0, 'run_scoped' => false, 'available' => false];
            }

            $table = $this->storageRegistry->tableName($datasetId);
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'digital_asset_id')) {
                return ['count' => 0, 'run_scoped' => false, 'available' => false];
            }

            $query = DB::table($table)->where('digital_asset_id', $assetId);
            $runScoped = $runId !== null && Schema::hasColumn($table, 'last_collection_run_id');
            if ($runScoped) {
                $query->where('last_collection_run_id', $runId);
            }

            return [
                'count' => (int) $query->count(),
                'run_scoped' => $runScoped,
                'available' => true,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['count' => 0, 'run_scoped' => false, 'available' => false];
        }
    }

    /** @return array<string, mixed> */
    private function siteHealth(int $assetId, ?int $runId): array
    {
        $empty = [
            'available' => false,
            'run_scoped' => false,
            'total' => 0,
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low_info' => 0,
            'redirect' => 0,
            'seo' => 0,
            'availability' => 0,
            'issues' => [],
        ];

        try {
            if (! $this->storageRegistry->hasPhysicalTable('website_crawl_issue_snapshot')) {
                return $empty;
            }

            $table = $this->storageRegistry->tableName('website_crawl_issue_snapshot');
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'digital_asset_id')) {
                return $empty;
            }

            $columns = Schema::getColumnListing($table);
            foreach (['url', 'issue_code', 'severity', 'message'] as $required) {
                if (! in_array($required, $columns, true)) {
                    return $empty;
                }
            }

            $query = DB::table($table)
                ->where('digital_asset_id', $assetId)
                ->select(['url', 'issue_code', 'severity', 'message']);

            $runScoped = $runId !== null && in_array('last_collection_run_id', $columns, true);
            if ($runScoped) {
                $query->where('last_collection_run_id', $runId);
            }

            /** @var Collection<int, object> $rows */
            $rows = $query->get();
            $normalized = $rows->map(function (object $row): array {
                $code = strtoupper(trim((string) ($row->issue_code ?? '')));
                $severity = strtolower(trim((string) ($row->severity ?? 'info')));

                return [
                    'url' => (string) ($row->url ?? ''),
                    'code' => $code,
                    'severity' => $severity,
                    'title' => $this->issueTitle($code),
                    'message' => (string) ($row->message ?? ''),
                ];
            });

            $seoCodes = [
                'MISSING_TITLE',
                'MISSING_META_DESCRIPTION',
                'MISSING_H1',
                'MULTIPLE_H1',
                'CANONICAL_MISSING',
                'CANONICAL_MULTIPLE',
                'NOINDEX',
            ];
            $availabilityCodes = [
                'HTTP_5XX',
                'HTTP_4XX',
                'FETCH_FAILED',
                'SOFT_404',
                'WORDPRESS_CRITICAL_ERROR',
                'WORDPRESS_DATABASE_ERROR',
                'APPLICATION_ERROR_PAGE',
            ];
            $priority = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'info' => 4];

            $issues = $normalized
                ->sortBy(fn (array $issue): string => sprintf('%02d-%s-%s', $priority[$issue['severity']] ?? 9, $issue['code'], $issue['url']))
                ->take(12)
                ->values()
                ->all();

            return [
                'available' => true,
                'run_scoped' => $runScoped,
                'total' => $normalized->count(),
                'critical' => $normalized->where('severity', 'critical')->count(),
                'high' => $normalized->where('severity', 'high')->count(),
                'medium' => $normalized->where('severity', 'medium')->count(),
                'low_info' => $normalized->filter(fn (array $issue): bool => in_array($issue['severity'], ['low', 'info'], true))->count(),
                'redirect' => $normalized->whereIn('code', ['REDIRECT_CHAIN', 'EXTERNAL_REDIRECT'])->count(),
                'seo' => $normalized->filter(fn (array $issue): bool => in_array($issue['code'], $seoCodes, true))->count(),
                'availability' => $normalized->filter(fn (array $issue): bool => in_array($issue['code'], $availabilityCodes, true))->count(),
                'issues' => $issues,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return $empty;
        }
    }

    private function issueTitle(string $code): string
    {
        $tr = app()->getLocale() === 'tr';

        return match ($code) {
            'WORDPRESS_CRITICAL_ERROR' => $tr ? 'WordPress kritik hata ekranı' : 'WordPress critical error page',
            'WORDPRESS_DATABASE_ERROR' => $tr ? 'WordPress veritabanı hatası' : 'WordPress database error',
            'APPLICATION_ERROR_PAGE' => $tr ? 'Uygulama hata/bakım sayfası' : 'Application error/maintenance page',
            'SOFT_404' => $tr ? 'Geçersiz URL / Soft 404' : 'Invalid URL / Soft 404',
            'HTTP_5XX' => $tr ? 'Sunucu hatası (5xx)' : 'Server error (5xx)',
            'HTTP_4XX' => $tr ? 'Erişilemeyen sayfa (4xx)' : 'Unavailable page (4xx)',
            'FETCH_FAILED' => $tr ? 'Sayfa alınamadı' : 'Page fetch failed',
            'REDIRECT_CHAIN' => $tr ? 'Yönlendirme zinciri' : 'Redirect chain',
            'MISSING_TITLE' => $tr ? 'Title eksik' : 'Missing title',
            'MISSING_META_DESCRIPTION' => $tr ? 'Meta açıklaması eksik' : 'Missing meta description',
            'MISSING_H1' => $tr ? 'H1 eksik' : 'Missing H1',
            'MULTIPLE_H1' => $tr ? 'Birden fazla H1' : 'Multiple H1 headings',
            'CANONICAL_MISSING' => $tr ? 'Canonical eksik' : 'Missing canonical',
            'CANONICAL_MULTIPLE' => $tr ? 'Birden fazla canonical' : 'Multiple canonicals',
            'NOINDEX' => $tr ? 'Noindex sayfa' : 'Noindex page',
            default => str($code)->replace('_', ' ')->title()->toString(),
        };
    }
}
