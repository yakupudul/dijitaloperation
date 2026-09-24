<?php

namespace App\Services\SiteFixes;

use App\Models\Collection\CollectionRun;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\SiteFixItem;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use Illuminate\Support\Collection;
use Throwable;

/**
 * 1.4.1: right after an approved fix or content update reaches WordPress, the changed pages are crawled again (targeted,
 * not the whole site). When that crawl and its projection are done, the finder runs once more: an applied item whose
 * problem is gone is marked verified; one that still shows (a cache, a theme or another SEO plugin overriding it)
 * is marked "still on the site" so the operator sees it at once.
 */
final class SiteFixVerification
{
    /** The projection is rebuilt by a queued listener after the crawl; give it this long. */
    private const int PROJECTION_GRACE_MINUTES = 3;

    public function __construct(private readonly SiteFixFinder $finder) {}

    public function start(ExternalWriteAction $action): void
    {
        if (! in_array($action->action, [ExternalWriteAction::ACTION_SITE_FIX, ExternalWriteAction::ACTION_CONTENT_APPLY], true)
            || ! in_array($action->status, ['succeeded', 'partial'], true)) {
            return;
        }
        $site = DigitalAsset::query()->find($action->digital_asset_id);
        if ($site === null) {
            return;
        }
        $urls = $this->urls($site, $this->items($action));
        if ($urls === []) {
            return;
        }
        try {
            $run = app(WebsiteCollectionOrchestrator::class)->start(asset: $site, requestFamilyIds: [WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL], context: [
                'force_refresh' => true,
                'idempotency_key' => 'site-fix-verify:'.$action->id,
                'collection_intent' => 'site_fix_verification',
                'collection_intent_label' => 'Site fix verification',
                'targeted_verification' => ['version' => 1, 'urls' => array_slice($urls, 0, 100), 'candidate_url_count' => count($urls),
                    'truncated' => count($urls) > 100, 'issue_code' => 'SITE_FIX', 'relation_key' => 'site-fix-'.$action->id],
            ]);
            $state = ['state' => 'crawling', 'run_id' => (int) $run->id, 'urls' => count($urls)];
        } catch (Throwable $exception) {
            $state = ['state' => 'not_started', 'error' => mb_substr($exception->getMessage(), 0, 200)];
        }
        $action->forceFill(['result' => array_merge((array) $action->result, ['verification' => $state])])->save();
    }

    /** Called every minute from the WordPress reconciliation tick. @return int actions settled */
    public function settle(): int
    {
        $settled = 0;
        $actions = ExternalWriteAction::query()
            ->whereIn('action', [ExternalWriteAction::ACTION_SITE_FIX, ExternalWriteAction::ACTION_CONTENT_APPLY])
            ->whereIn('status', ['succeeded', 'partial'])->where('finished_at', '>=', now()->subDays(2))
            ->orderBy('id')->limit(200)->get()
            ->filter(fn (ExternalWriteAction $a): bool => data_get($a->result, 'verification.state') === 'crawling');
        foreach ($actions as $action) {
            $run = CollectionRun::query()->find((int) data_get($action->result, 'verification.run_id'));
            $status = $run?->status?->value;
            if ($run !== null && ! in_array($status, ['completed', 'partial', 'failed', 'cancelled', 'skipped', 'not_eligible'], true)) {
                continue;
            }
            if ($run !== null && in_array($status, ['completed', 'partial'], true) && $run->finished_at !== null
                && $run->finished_at->gt(now()->subMinutes(self::PROJECTION_GRACE_MINUTES))) {
                continue;
            }
            $this->conclude($action, $run !== null && in_array($status, ['completed', 'partial'], true));
            $settled++;
        }

        return $settled;
    }

    private function conclude(ExternalWriteAction $action, bool $crawled): void
    {
        $verification = (array) data_get($action->result, 'verification', []);
        $site = DigitalAsset::query()->find($action->digital_asset_id);
        if (! $crawled || $site === null) {
            $action->forceFill(['result' => array_merge((array) $action->result, ['verification' => ['state' => 'crawl_failed'] + $verification])])->save();

            return;
        }
        $present = array_flip($this->finder->find($site)['keys']);
        $verified = 0;
        $still = 0;
        foreach ($this->items($action)->where('status', 'applied') as $item) {
            // Alt text and new pages are not visible in a page crawl; they are verified by WordPress data instead.
            if (in_array($item->type, ['alt_text', 'new_page'], true)) {
                continue;
            }
            $gone = ! isset($present[$item->item_key]);
            $gone ? $verified++ : $still++;
            $item->forceFill(['current' => array_merge((array) $item->current, ['verification' => ['state' => $gone ? 'verified' : 'still_present', 'at' => now()->toIso8601String()]])])->save();
        }
        $action->forceFill(['result' => array_merge((array) $action->result, ['verification' => ['state' => 'done', 'verified' => $verified, 'still_present' => $still, 'at' => now()->toIso8601String()] + $verification])])->save();
    }

    /** @return Collection<int, SiteFixItem> */
    private function items(ExternalWriteAction $action)
    {
        $ids = $action->action === ExternalWriteAction::ACTION_CONTENT_APPLY
            ? [(int) data_get($action->request_payload, 'item_id')]
            : array_map('intval', (array) data_get($action->request_payload, 'item_ids', []));

        return SiteFixItem::query()->whereIn('id', $ids)->get();
    }

    /**
     * @param  Collection<int, SiteFixItem>  $items
     * @return list<string>
     */
    private function urls(DigitalAsset $site, $items): array
    {
        $base = rtrim((string) ($site->primary_url ?: 'https://'.$site->domain), '/');
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        $urls = [];
        foreach ($items as $item) {
            if (in_array($item->type, ['alt_text', 'new_page'], true) || blank($item->url)) {
                continue;
            }
            $url = str_starts_with((string) $item->url, '/') ? $base.$item->url : (string) $item->url;
            if (in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) && strtolower((string) parse_url($url, PHP_URL_HOST)) === $host) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }
}
