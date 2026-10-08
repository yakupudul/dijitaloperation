<?php

namespace App\Services\Repair;

use App\Models\Suggestion;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteSuggestionTypes;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hazırla (Onarım masası): website suggestions that have no prepared value yet are queued for "AI ile yap" each night,
 * so they reach the desk ready to approve. Field fixes (title / description, internal links, schema) first; page-text
 * fixes in a smaller batch. Each run is one queued job per suggestion (unique), budget and credit rules apply.
 */
final class RepairPreparer
{
    /** Field fixes queued per night. */
    public const int FIELDS_PER_RUN = 400;

    /** Page-text fixes queued per night (longer and costlier). */
    public const int CONTENT_PER_RUN = 20;

    public const array FIELD_TYPES = [SiteAudit::TYPE, 'title_description', 'internal_links', 'technical_seo'];

    /** @return array{fields: int, content: int} */
    public function queue(?int $fields = null, ?int $content = null): array
    {
        $contentTypes = array_values(array_diff(SiteSuggestionTypes::APPLICABLE, self::FIELD_TYPES));

        return [
            'fields' => $this->dispatch(self::FIELD_TYPES, $fields ?? self::FIELDS_PER_RUN),
            'content' => $this->dispatch($contentTypes, $content ?? self::CONTENT_PER_RUN),
        ];
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
