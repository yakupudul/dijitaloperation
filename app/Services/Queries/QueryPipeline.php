<?php

namespace App\Services\Queries;

use App\Models\Brand;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sorgu hattı. Daily (and after every successful pull of one account): ingest → sector assignment → ingest again for
 * accounts whose sector just changed → rule matching → AI fallback. Weekly (and on demand): clustering → SERP
 * page-type research. Every step is incremental and idempotent.
 *
 * daily() runs every step in this process (artisan, tests). On the queue the same steps run as a chain of short jobs
 * (RunQueryPipelineJob → IngestQuerySourcesJob × chunks → AssignQuerySectorsJob → MatchQueriesJob →
 * ClassifyUnmatchedQueriesJob → FinishQueryPipelineJob), each bounded well below the queue's retry_after, with the
 * AI steps capped per job.
 */
final class QueryPipeline
{
    /** Cache key of the last finished run's summary per scope ("all" or a resource id). */
    public const string LAST_RUN_KEY = 'queries:pipeline:last:';

    /** Held by the AI steps so concurrent per-account pipelines never classify the same queries twice. */
    public const string AI_LOCK = 'queries:pipeline:ai';

    public function __construct(
        private readonly QueryIngestor $ingestor,
        private readonly AssetSectorService $sectors,
        private readonly QueryServiceMatcher $matcher,
        private readonly QueryClusterer $clusterer,
        private readonly ClusterPageResearch $research,
    ) {}

    public static function lockKey(?int $resourceId): string
    {
        return 'queries:pipeline:'.($resourceId ?? 'all');
    }

    /** @return array<string, mixed> */
    public function daily(?int $resourceId = null, bool $force = false): array
    {
        $lock = Cache::lock(self::lockKey($resourceId), 3600);
        if (! $lock->get()) {
            return ['skipped' => true, 'summary' => 'Sorgu hattı zaten çalışıyor; bu çalıştırma atlandı.'];
        }
        try {
            $stats = ['ingest' => $this->ingestor->run($resourceId, $force)];
            $stats['sectors'] = $this->safely(fn (): array => $this->sectors->assignPending());
            // Accounts whose sector was just decided are filed again (their context changed); the rest are skipped.
            $stats['refile'] = $this->ingestor->run($resourceId);
            $stats['rules_reset'] = $this->matcher->refreshRuleChanges();
            $stats['match'] = $this->matcher->matchPending();
            $stats['ai'] = $this->safely(fn (): array => $this->matcher->classifyUnmatched());
            $stats['new_queries'] = (int) ($stats['ingest']['new_queries'] ?? 0) + (int) ($stats['refile']['new_queries'] ?? 0);
            $stats['summary'] = self::summary($stats);
            $this->remember($resourceId, $stats);

            return $stats;
        } finally {
            $lock->release();
        }
    }

    // ------------------------------------------------------------- queued steps

    /**
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    public function ingestKeys(array $keys, bool $force = false): array
    {
        return $this->ingestor->runKeys($keys, $force);
    }

    /** @return list<string> */
    public function sourceKeys(?int $resourceId = null): array
    {
        return $this->ingestor->sourceKeys($resourceId);
    }

    /** @return list<string> */
    public function sourceKeysForBrand(Brand $brand): array
    {
        return $this->ingestor->sourceKeysForBrand($brand);
    }

    /**
     * Sector assignment (AI for at most $limit accounts), then the accounts whose sector just changed are filed again.
     *
     * @return array<string, mixed>
     */
    public function assignSectors(?int $resourceId, int $limit): array
    {
        $stats = ['sectors' => $this->safely(fn (): array => $this->sectors->assignPending($limit))];
        $stats['refile'] = $this->ingestor->run($resourceId);

        return $stats;
    }

    /** @return array<string, mixed> */
    public function matchRules(): array
    {
        return ['rules_reset' => $this->matcher->refreshRuleChanges(), 'match' => $this->matcher->matchPending()];
    }

    /** @return array<string, int|string> */
    public function classifyBatch(int $limit): array
    {
        return $this->safely(fn (): array => $this->matcher->classifyUnmatched($limit));
    }

    /**
     * One line for the operator: "0 yeni sorgu · 3 hesap okundu, 0 değişti …" — an empty Search Console still ends
     * the pipeline cleanly with "0 yeni sorgu".
     *
     * @param  array<string, mixed>  $stats
     */
    public static function summary(array $stats): string
    {
        $ingest = (array) ($stats['ingest'] ?? []);
        $match = (array) ($stats['match'] ?? []);
        $ai = (array) ($stats['ai'] ?? []);

        return sprintf('%d yeni sorgu · %d hesap okundu, %d hesapta değişiklik, %d hata · kuralla %d eşleşti, %d eşleşmedi · AI %d eşleşti, %d alakasız',
            (int) ($stats['new_queries'] ?? 0), (int) ($ingest['sources'] ?? 0), (int) ($ingest['ingested'] ?? 0), (int) ($ingest['failed'] ?? 0),
            (int) ($match['matched'] ?? 0), (int) ($match['unmatched'] ?? 0), (int) ($ai['matched'] ?? 0), (int) ($ai['irrelevant'] ?? 0));
    }

    /**
     * Accumulates one queued run's step results (chain jobs run one after another, so no race).
     *
     * @param  array<string, mixed>  $stats
     */
    public static function addRunStats(string $runId, string $step, array $stats): void
    {
        $key = 'queries:pipeline:run:'.$runId;
        $current = (array) Cache::get($key, []);
        if (in_array($step, ['ingest', 'refile'], true)) {
            foreach ($stats as $field => $value) {
                $current[$step][$field] = (int) ($current[$step][$field] ?? 0) + (int) $value;
            }
        } elseif (in_array($step, ['match', 'ai', 'sectors'], true)) {
            foreach ($stats as $field => $value) {
                $current[$step][$field] = is_numeric($value) ? (int) ($current[$step][$field] ?? 0) + (int) $value : $value;
            }
        } else {
            $current[$step] = $stats;
        }
        Cache::put($key, $current, now()->addDay());
    }

    /** @return array<string, mixed> */
    public static function runStats(string $runId): array
    {
        return (array) Cache::get('queries:pipeline:run:'.$runId, []);
    }

    /** @param  array<string, mixed>  $stats */
    public function remember(?int $resourceId, array $stats): void
    {
        Cache::put(self::LAST_RUN_KEY.($resourceId ?? 'all'), $stats + ['finished_at' => now()->toIso8601String()], now()->addDays(14));
    }

    /**
     * Summary under another scope ("brand:5" for moxdop:pilot:refresh).
     *
     * @param  array<string, mixed>  $stats
     */
    public static function rememberAs(string $scope, array $stats): void
    {
        Cache::put(self::LAST_RUN_KEY.$scope, $stats + ['finished_at' => now()->toIso8601String()], now()->addDays(14));
    }

    /** @return array<string, mixed>|null */
    public static function lastRun(int|string|null $scope = null): ?array
    {
        $value = Cache::get(self::LAST_RUN_KEY.($scope ?? 'all'));

        return is_array($value) ? $value : null;
    }

    /** @return array<string, mixed> */
    public function weekly(?int $serviceId = null): array
    {
        $stats = ['clusters' => $serviceId !== null ? $this->clusterer->clusterService($serviceId) : $this->clusterer->clusterDue()];
        $stats['research'] = $this->safely(fn (): array => $this->research->researchDue());

        return $stats;
    }

    /** @return array<string, mixed> */
    private function safely(callable $step): array
    {
        try {
            return $step();
        } catch (Throwable $exception) {
            report($exception);

            return ['error' => mb_substr($exception->getMessage(), 0, 200)];
        }
    }
}
