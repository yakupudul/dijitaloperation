<?php

namespace App\Services\Repair;

use App\Jobs\Site\RunSiteOperationJob;
use App\Models\Suggestion;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteSuggestionTypes;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hazırla (Onarım masası): website suggestions that have no prepared value yet are queued for "AI ile yap" each night,
 * so they reach the desk ready to approve. Title / description fixes are prepared every hour in batches of 15 pages
 * per AI call (SeoFieldsBatch), so thousands of pages do not wait months. Field fixes (title / description, internal links, schema) first; page-text
 * fixes in a smaller batch. Each run is one queued job per suggestion (unique), budget and credit rules apply.
 */
final class RepairPreparer
{
    /** Field fixes (internal links, technical) queued per run, one AI call each. */
    public const int FIELDS_PER_RUN = 400;

    /** Title / description pages queued per hourly run, 15 per AI call (SeoFieldsBatch). */
    public const int BATCH_PAGES_PER_RUN = 300;

    /** Page-text fixes queued per night (longer and costlier). */
    public const int CONTENT_PER_RUN = 20;

    public const array FIELD_TYPES = [SiteAudit::TYPE, 'title_description', 'internal_links', 'technical_seo'];

    /**
     * @param  bool  $batchOnly  the hourly run: only the batched title / description fixes
     * @return array{batch: int, fields: int, content: int}
     */
    public function queue(?int $fields = null, ?int $content = null, ?int $batch = null, bool $batchOnly = false): array
    {
        $contentTypes = array_values(array_diff(SiteSuggestionTypes::APPLICABLE, self::FIELD_TYPES));

        return [
            'batch' => $this->dispatchBatches($batch ?? self::BATCH_PAGES_PER_RUN),
            'fields' => $batchOnly ? 0 : $this->dispatch(array_values(array_diff(self::FIELD_TYPES, SeoFieldsBatch::TYPES)), $fields ?? self::FIELDS_PER_RUN),
            'content' => $batchOnly ? 0 : $this->dispatch($contentTypes, $content ?? self::CONTENT_PER_RUN),
        ];
    }

    /**
     * Title / description fixes in jobs of 15 pages of one site, most urgent first (missing before duplicate before
     * length); each job is one AI call.
     */
    private function dispatchBatches(int $pages): int
    {
        if ($pages <= 0) {
            return 0;
        }
        $bySite = [];
        SeoFieldsBatch::waitingQuery()->with('page:id,website_asset_id')->orderBy('priority')->orderBy('id')->limit($pages)->get(['id', 'page_id', 'priority'])
            ->each(function (Suggestion $s) use (&$bySite): void {
                if ($s->page?->website_asset_id !== null) {
                    $bySite[(int) $s->page->website_asset_id][] = (int) $s->id;
                }
            });
        $queued = 0;
        foreach ($bySite as $siteId => $ids) {
            foreach (array_chunk($ids, SeoFieldsBatch::PAGES_PER_CALL) as $chunk) {
                RunSiteOperationJob::dispatch($siteId, SiteOperations::SEO_FIELDS_BATCH, ['suggestion_ids' => $chunk]);
                $queued += count($chunk);
            }
        }

        return $queued;
    }

    /** @param  list<string>  $types */
    private function dispatch(array $types, int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }
        $queued = 0;
        Suggestion::query()->with('page:id,website_asset_id')
            ->whereHas('brand', fn (Builder $b): Builder => $b->operational())
            ->where('channel', 'search')->whereIn('action_type', $types)->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK])
            ->whereNotNull('page_id')->orderBy('priority')->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$queued, $limit): bool {
                foreach ($chunk as $suggestion) {
                    $action = (array) $suggestion->action;
                    $siteId = $suggestion->page?->website_asset_id;
                    if ($siteId === null || isset($action['proposal']) || isset($action['proposal_blocked']) || isset($action['writes'])) {
                        continue;
                    }
                    SiteOperations::dispatch((int) $siteId, SiteOperations::APPLY_CHANGE, ['suggestion_id' => (int) $suggestion->id]);
                    if (++$queued >= $limit) {
                        return false;
                    }
                }

                return true;
            });

        return $queued;
    }
}
