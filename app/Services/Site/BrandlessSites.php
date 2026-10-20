<?php

namespace App\Services\Site;

use App\Jobs\Site\RunSiteOperationJob;
use App\Models\AiTask;
use App\Models\ContentSiteState;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Services\ExternalWrites\ExternalWriteService;

/**
 * Markaya bağlı olmayan site (yakup, 2026-10-20: "Markaya bağlı olmayan hiçbir şey için herhangi bir şey yapılmasın"):
 * a website is only connected so Claude builds it (build-site). SEO / content service starts when the site is given a
 * brand. Until then nothing automatic runs for it: no site operation (AI analysis, content ideas, articles, back-dated
 * fill), no SEO / content write to WordPress, no IndexNow from the connector. Only the site build, connector and
 * plugin updates and undo stay, because the operator asks for those.
 */
final class BrandlessSites
{
    /** Status of a skipped site operation (shown on the screen instead of a result). */
    public const string SKIPPED = 'no_brand';

    public const string REASON = 'Bu web sitesi bir markaya bağlı değil: MoxDOP markasız sitede otomatik iş yapmaz (yalnız site kurulumu). Siteyi bir markaya atayınca SEO ve içerik işleri başlar.';

    /** WordPress writes a brandless site may still get: the build, connector / plugin updates (asked for by the operator). */
    public const array ALLOWED_WRITES = [ExternalWriteAction::ACTION_SITE_BUILD, ExternalWriteAction::ACTION_CONNECTOR_UPDATE, ExternalWriteAction::ACTION_UPDATE_APPLY];

    /** Whether MoxDOP serves this asset: every non-website asset has a brand; a website only once it is given one. */
    public static function serviced(DigitalAsset|int $site): bool
    {
        if (is_int($site)) {
            $site = DigitalAsset::query()->find($site, ['id', 'type', 'brand_id']);
            if ($site === null) {
                return false;
            }
        }

        return $site->type !== 'website' || $site->brand_id !== null;
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
     * @return array{writes: int, backfills: int, tasks: int}
     */
    public static function stopPending(): array
    {
        $siteIds = DigitalAsset::query()->where('type', 'website')->whereNull('brand_id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($siteIds === []) {
            return ['writes' => 0, 'backfills' => 0, 'tasks' => 0];
        }
        $writes = 0;
        $service = app(ExternalWriteService::class);
        ExternalWriteAction::query()->whereIn('digital_asset_id', $siteIds)->whereIn('status', ['queued', 'scheduled'])
            ->whereNotIn('action', self::ALLOWED_WRITES)->orderBy('id')
            ->each(function (ExternalWriteAction $action) use ($service, &$writes): void {
                $service->abandon($action, 'Gönderilmedi: '.self::REASON);
                $writes++;
            });
        $backfills = ContentSiteState::query()->whereIn('digital_asset_id', $siteIds)->where('backfill', ContentSiteState::BACKFILL_ELIGIBLE)
            ->update(['backfill' => ContentSiteState::BACKFILL_OFF]);
        $tasks = 0;
        AiTask::query()->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])->whereNull('brand_id')->orderBy('id')
            ->each(function (AiTask $task) use ($siteIds, &$tasks): void {
                $job = is_string($task->resume) ? @unserialize($task->resume, ['allowed_classes' => [RunSiteOperationJob::class]]) : null;
                if ($job instanceof RunSiteOperationJob && in_array($job->siteId, $siteIds, true)) {
                    $task->forceFill(['status' => AiTask::CONSUMED, 'consumed_at' => now(), 'error' => self::REASON])->save();
                    $tasks++;
                }
            });

        return ['writes' => $writes, 'backfills' => $backfills, 'tasks' => $tasks];
    }
}
