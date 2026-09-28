<?php

namespace App\Services\Queries;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sorgu hattı. Daily (and after every successful pull of one account): ingest → sector assignment → ingest again for
 * accounts whose sector just changed → rule matching → AI fallback. Weekly (and on demand): clustering → SERP
 * page-type research. Every step is incremental and idempotent.
 */
final class QueryPipeline
{
    public function __construct(
        private readonly QueryIngestor $ingestor,
        private readonly AssetSectorService $sectors,
        private readonly QueryServiceMatcher $matcher,
        private readonly QueryClusterer $clusterer,
        private readonly ClusterPageResearch $research,
    ) {}

    /** @return array<string, mixed> */
    public function daily(?int $resourceId = null, bool $force = false): array
    {
        $lock = Cache::lock('queries:pipeline:'.($resourceId ?? 'all'), 3600);
        if (! $lock->get()) {
            return ['skipped' => true];
        }
        try {
            $stats = ['ingest' => $this->ingestor->run($resourceId, $force)];
            $stats['sectors'] = $this->safely(fn (): array => $this->sectors->assignPending());
            // Accounts whose sector was just decided are filed again (their context changed); the rest are skipped.
            $stats['refile'] = $this->ingestor->run($resourceId);
            $stats['rules_reset'] = $this->matcher->refreshRuleChanges();
            $stats['match'] = $this->matcher->matchPending();
            $stats['ai'] = $this->safely(fn (): array => $this->matcher->classifyUnmatched());

            return $stats;
        } finally {
            $lock->release();
        }
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
