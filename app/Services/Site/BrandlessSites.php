<?php

namespace App\Services\Site;

use App\Jobs\Site\RunSiteOperationJob;
use App\Models\AiTask;
use App\Models\ContentSiteState;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Support\SchemaState;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Markaya bağlı olmayan site (yakup, 2026-10-20: "Markaya bağlı olmayan hiçbir şey için herhangi bir şey yapılmasın"):
 * a website is only connected so Claude builds it (build-site). SEO / content service starts when the site is given a
 * brand. Until then nothing automatic runs for it: no site operation (AI analysis, content ideas, articles, back-dated
 * fill), no SEO / content write to WordPress, no IndexNow from the connector. Only the site build, connector and
 * plugin updates and undo stay, because the operator asks for those.
 *
 * İçerik / SEO kapalı site (yakup, 2026-11-20: hesap.moximu.com "mağaza alanı, burada içerik yazılmayacak",
 * randevu.drserapkorkmaz.com "Google Ads landing sayfaları, SEO yapmayacağız"): a website that has a brand (its Ads,
 * measurement, alarms and the build keep working) but `digital_assets.seo_off` is on gets the same treatment as a
 * brandless one for SEO and content: no site operation, no content idea / article / repair row, no SEO or content
 * write, no IndexNow. Nothing is noindexed and nothing published is deleted.
 */
final class BrandlessSites
{
    /** Status of a skipped site operation (shown on the screen instead of a result). */
    public const string SKIPPED = 'no_brand';

    public const string REASON = 'Bu web sitesi bir markaya bağlı değil: MoxDOP markasız sitede otomatik iş yapmaz (yalnız site kurulumu). Siteyi bir markaya atayınca SEO ve içerik işleri başlar.';

    public const string SEO_OFF_REASON = 'Bu sitede içerik ve SEO kapalı (yakup kararı): MoxDOP burada yazı, içerik fikri, onarım ya da SEO işi üretmez. Reklam, ölçüm ve site kurulumu sürer.';

    private const string SEO_OFF_CACHE = 'sites.seo-off-ids';

    /** WordPress writes a brandless site may still get: the build, connector / plugin updates (asked for by the operator). */
    public const array ALLOWED_WRITES = [ExternalWriteAction::ACTION_SITE_BUILD, ExternalWriteAction::ACTION_CONNECTOR_UPDATE, ExternalWriteAction::ACTION_UPDATE_APPLY];

    /**
     * Whether MoxDOP does SEO / content work for this asset: every non-website asset has a brand; a website only once it
     * is given one and while its content and SEO are not switched off (seo_off).
     */
    public static function serviced(DigitalAsset|int $site): bool
    {
        if (is_int($site)) {
            $site = DigitalAsset::query()->find($site, ['id', 'type', 'brand_id']);
            if ($site === null) {
                return false;
            }
        }

        return $site->type !== 'website' || ($site->brand_id !== null && ! self::seoOff((int) $site->id));
    }

    /** Whether the website has a brand (the build tools and the crawl only need this, not the SEO / content service). */
    public static function branded(DigitalAsset $site): bool
    {
        return $site->type !== 'website' || $site->brand_id !== null;
    }

    /** Whether the operator switched SEO and content off for this website (hesap.moximu.com, Ads landing sites). */
    public static function seoOff(DigitalAsset|int|null $site): bool
    {
        $id = $site instanceof DigitalAsset ? (int) $site->id : (int) $site;

        return $id > 0 && in_array($id, self::seoOffIds(), true);
    }

    /** @return list<int> website ids with SEO and content switched off */
    public static function seoOffIds(): array
    {
        return app()->runningUnitTests() ? self::readSeoOff() : Cache::remember(self::SEO_OFF_CACHE, 600, fn (): array => self::readSeoOff());
    }

    public static function forgetSeoOff(): void
    {
        Cache::forget(self::SEO_OFF_CACHE);
    }

    /** The reason shown when a site is not serviced: brandless, or SEO / content off. */
    public static function reason(DigitalAsset|int|null $site): string
    {
        return self::seoOff($site) ? self::SEO_OFF_REASON : self::REASON;
    }

    /**
     * Suggestion::saving: a search (SEO / content) row of a website whose SEO and content are off never lands on a desk
     * or in the content queue; it is closed with the reason as it is saved.
     */
    public static function holdSuggestion(Suggestion $suggestion): void
    {
        if ($suggestion->channel !== 'search' || in_array($suggestion->status, [Suggestion::APPLIED, Suggestion::DISMISSED], true) || self::seoOffIds() === []) {
            return;
        }
        if (! self::seoOff(self::suggestionSite($suggestion))) {
            return;
        }
        $suggestion->status = Suggestion::DISMISSED;
        $suggestion->resolved_at ??= now()->toImmutable();
        $suggestion->action = array_merge((array) $suggestion->action, ['auto_closed' => self::SEO_OFF_REASON]);
    }

    private static function suggestionSite(Suggestion $suggestion): ?int
    {
        $site = data_get($suggestion->action, 'site_id');
        if (is_numeric($site)) {
            return (int) $site;
        }
        if ($suggestion->target_type === 'site' && $suggestion->target_id !== null) {
            return (int) $suggestion->target_id;
        }
        $pageId = $suggestion->page_id ?? ($suggestion->target_type === 'page' ? $suggestion->target_id : null);
        if ($pageId !== null) {
            $owner = Page::query()->whereKey((int) $pageId)->value('website_asset_id');

            return $owner !== null ? (int) $owner : null;
        }

        return null;
    }

    /** @return list<int> */
    private static function readSeoOff(): array
    {
        try {
            if (! SchemaState::hasColumn('digital_assets', 'seo_off')) {
                return [];
            }

            return DigitalAsset::query()->where('type', 'website')->where('seo_off', true)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        } catch (Throwable $error) {
            report($error);

            return [];
        }
    }

    public static function writeAllowed(ExternalWriteAction $action, ?DigitalAsset $asset): bool
    {
        return $asset === null || self::serviced($asset) || in_array($action->action, self::ALLOWED_WRITES, true);
    }

    /**
     * Stops what was already waiting for brandless websites: queued / scheduled / waiting SEO and content writes end as
     * failed with the reason, the one-off back-dated fill is switched off, and the AI tasks of their site operations
     * are closed so Claude does not work on them.
     *
     * @return array{writes: int, backfills: int, tasks: int, suggestions: int}
     */
    public static function stopPending(): array
    {
        self::forgetSeoOff();
        $seoOff = self::readSeoOff();
        $siteIds = array_values(array_unique([...DigitalAsset::query()->where('type', 'website')->whereNull('brand_id')->pluck('id')->map(fn ($id): int => (int) $id)->all(), ...$seoOff]));
        if ($siteIds === []) {
            return ['writes' => 0, 'backfills' => 0, 'tasks' => 0, 'suggestions' => 0];
        }
        $writes = 0;
        $service = app(ExternalWriteService::class);
        ExternalWriteAction::query()->whereIn('digital_asset_id', $siteIds)->whereIn('status', ['queued', 'scheduled'])
            ->whereNotIn('action', self::ALLOWED_WRITES)->orderBy('id')
            ->each(function (ExternalWriteAction $action) use ($service, &$writes): void {
                $service->abandon($action, 'Gönderilmedi: '.self::reason((int) $action->digital_asset_id));
                $writes++;
            });
        $backfills = ContentSiteState::query()->whereIn('digital_asset_id', $siteIds)->where('backfill', ContentSiteState::BACKFILL_ELIGIBLE)
            ->update(['backfill' => ContentSiteState::BACKFILL_OFF]);
        $tasks = 0;
        $seoOffBrands = $seoOff === [] ? [] : DigitalAsset::query()->whereIn('id', $seoOff)->whereNotNull('brand_id')->pluck('brand_id')->map(fn ($id): int => (int) $id)->all();
        AiTask::query()->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])
            ->where(fn ($q) => $q->whereNull('brand_id')->when($seoOffBrands !== [], fn ($q) => $q->orWhereIn('brand_id', $seoOffBrands)))->orderBy('id')
            ->each(function (AiTask $task) use ($siteIds, &$tasks): void {
                $job = is_string($task->resume) ? @unserialize($task->resume, ['allowed_classes' => [RunSiteOperationJob::class]]) : null;
                if ($job instanceof RunSiteOperationJob && in_array($job->siteId, $siteIds, true)) {
                    $task->forceFill(['status' => AiTask::CONSUMED, 'consumed_at' => now(), 'error' => self::reason($job->siteId)])->save();
                    $tasks++;
                }
            });
        // Content titles, articles waiting to be sent and repair rows of SEO-off sites close (the saving hook does it).
        $suggestions = 0;
        if ($seoOffBrands !== []) {
            Suggestion::query()->where('channel', 'search')->whereNotIn('status', [Suggestion::APPLIED, Suggestion::DISMISSED])
                ->whereIn('brand_id', $seoOffBrands)->orderBy('id')
                ->each(function (Suggestion $suggestion) use (&$suggestions): void {
                    self::holdSuggestion($suggestion);
                    if ($suggestion->isDirty('status')) {
                        $suggestion->save();
                        $suggestions++;
                    }
                });
        }

        return ['writes' => $writes, 'backfills' => $backfills, 'tasks' => $tasks, 'suggestions' => $suggestions];
    }
}
