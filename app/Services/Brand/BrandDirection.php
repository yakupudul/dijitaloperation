<?php

namespace App\Services\Brand;

use App\Ai\Agents\Site\BrandDirectionFitAgent;
use App\Jobs\Brand\ApplyBrandDirectionJob;
use App\Jobs\Brand\RefreshBrandFilesJob;
use App\Jobs\Brand\RunBrandEpicrisisJob;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandDirectionChange;
use App\Models\Cluster;
use App\Models\DigitalAsset;
use App\Models\GbpQueuedPost;
use App\Models\Suggestion;
use App\Services\Queries\KeywordPlanner;
use App\Services\Site\BrandMemoryService;
use App\Services\Site\ClusterPageMapper;
use App\Services\Site\SiteAi;
use App\Services\Site\SiteScope;
use App\Services\Site\SiteSuggestionTypes;
use App\Services\Website\Pages\PageStore;
use App\Services\Work\ContentCoverage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Marka rotası (yakup, 2026-11-09: "markanın rotası değiştiğinde diğer çalışan şeyler değişmiyor… kümeler, içerik
 * fikirleri, dijital varlıklarla alakalı çalışan diğer şeyler hep eskiyi işaret ediyor").
 *
 * Every brand-dependent feature already reads the brand's live lists (BrandPack, SiteScope::offerings, the epikriz),
 * but some work was MADE from the old direction and stays: content ideas (written, even approved and drafted), the
 * cluster rows of a service the brand still sells but for another audience, the cached brand memory profile, the
 * Business Profile post drafts of the next 30 days and the search demand (queries) of the old services.
 *
 * The direction is a fingerprint of the active services, the ★ services and the business summary / positioning /
 * audiences. When it changes (a service saved, the İş bağlamı edited, the nightly check, or the operator's "Rota
 * değişti" button) a BrandDirectionChange row is written and ApplyBrandDirectionJob realigns the brand:
 *  - always: the brand memory profile is rebuilt, the cluster rows follow the active services, unsent content ideas
 *    of a removed service are closed (restorable on İçerik);
 *  - major change (half of the services changed, the positioning text is new, or the operator said so): Claude reads
 *    the brand's clusters and unsent ideas once against the new direction (`brand.direction_fit`); a cluster that no
 *    longer fits is set aside for the brand (excluded, restorable on İçerik fikirleri), an idea is closed; Business
 *    Profile post drafts of the old direction are skipped (the queue writes new ones); the Keyword Planner is asked
 *    with the new service names (new demand → library → clusters → ideas); the epikriz reads the site again; the
 *    content pool fills from the current clusters.
 */
final class BrandDirection
{
    /** Below this share of services in common the change is a new direction. */
    public const float MAJOR_SERVICE_OVERLAP = 0.5;

    /** Below this share of words in common the summary / positioning is a new text. */
    public const float MAJOR_TEXT_OVERLAP = 0.35;

    /** Items per Claude call. */
    public const int BATCH = 80;

    private const array UNSENT = [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::SNOOZED, Suggestion::RECHECK];

    public function __construct(private readonly SiteAi $ai) {}

    /**
     * The brand's direction now.
     *
     * @return array{services: list<string>, main: list<string>, summary: string, positioning: string, audiences: list<string>, constraints: string}
     */
    public static function snapshot(Brand $brand): array
    {
        $services = BrandPack::services($brand);
        $context = $brand->intelligenceContext()->first();

        return [
            'services' => array_values(array_column($services, 'name')),
            'main' => array_values(array_column(array_filter($services, fn (array $s): bool => $s['priority'] === 'main'), 'name')),
            'summary' => trim((string) ($context?->business_summary ?? '')),
            'positioning' => trim((string) ($context?->positioning ?? '')),
            'audiences' => array_values(array_filter(array_map(fn ($a): string => trim(is_array($a) ? (string) ($a['name'] ?? $a['label'] ?? '') : (string) $a), (array) ($context?->target_audiences ?? [])))),
            'constraints' => trim((string) ($context?->important_constraints ?? '')),
        ];
    }

    /** @param  array<string, mixed>  $snapshot */
    public static function fingerprint(array $snapshot): string
    {
        $norm = fn (array $list): array => collect($list)->map(fn ($v): string => mb_strtolower(trim((string) $v)))->filter()->unique()->sort()->values()->all();

        return hash('sha256', json_encode([
            $norm((array) ($snapshot['services'] ?? [])), $norm((array) ($snapshot['main'] ?? [])),
            mb_strtolower((string) ($snapshot['summary'] ?? '')), mb_strtolower((string) ($snapshot['positioning'] ?? '')), $norm((array) ($snapshot['audiences'] ?? [])),
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Compares the brand's direction with the last one seen; on a change writes the row and queues the realignment.
     * The first look writes the baseline only. $force = the operator (or a one-off) says the direction changed.
     */
    public function detect(Brand $brand, bool $force = false, string $trigger = 'auto', ?string $reason = null, ?int $userId = null): ?BrandDirectionChange
    {
        if (! Schema::hasTable('brand_direction_changes')) {
            return null;
        }
        $snapshot = self::snapshot($brand);
        $hash = self::fingerprint($snapshot);
        $last = BrandDirectionChange::latestFor((int) $brand->id);
        if (! $force && $last !== null && $last->fingerprint === $hash) {
            return null;
        }
        $before = (array) ($last?->snapshot ?? []);
        $lower = fn (array $l): array => array_map(fn ($v): string => mb_strtolower((string) $v), $l);
        $added = $last === null ? [] : array_values(array_filter($snapshot['services'], fn (string $s): bool => ! in_array(mb_strtolower($s), $lower((array) ($before['services'] ?? [])), true)));
        $removed = $last === null ? [] : array_values(array_filter((array) ($before['services'] ?? []), fn ($s): bool => ! in_array(mb_strtolower((string) $s), $lower($snapshot['services']), true)));
        $magnitude = match (true) {
            $force => BrandDirectionChange::MAJOR,
            $last === null => BrandDirectionChange::BASELINE,
            default => self::magnitude($before, $snapshot),
        };
        $change = BrandDirectionChange::query()->create([
            'brand_id' => $brand->id, 'fingerprint' => $hash, 'magnitude' => $magnitude, 'trigger' => $trigger,
            'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null, 'snapshot' => $snapshot, 'added' => $added, 'removed' => array_map('strval', $removed),
            'created_by' => $userId, 'applied_at' => $magnitude === BrandDirectionChange::BASELINE ? now() : null,
        ]);
        if ($magnitude !== BrandDirectionChange::BASELINE) {
            ApplyBrandDirectionJob::dispatch((int) $change->id)->afterCommit();
        }

        return $change;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function magnitude(array $before, array $after): string
    {
        $old = array_map(fn ($s): string => mb_strtolower((string) $s), (array) ($before['services'] ?? []));
        $new = array_map(fn ($s): string => mb_strtolower((string) $s), (array) ($after['services'] ?? []));
        if ($old !== [] && $new !== [] && self::overlap($old, $new) < self::MAJOR_SERVICE_OVERLAP) {
            return BrandDirectionChange::MAJOR;
        }
        $text = fn (array $s): string => trim(($s['summary'] ?? '').' '.($s['positioning'] ?? ''));
        $oldWords = self::words($text($before));
        $newWords = self::words($text($after));
        if (count($oldWords) >= 8 && count($newWords) >= 8 && self::overlap($oldWords, $newWords) < self::MAJOR_TEXT_OVERLAP) {
            return BrandDirectionChange::MAJOR;
        }

        return BrandDirectionChange::MINOR;
    }

    /**
     * Realigns the brand to the change. Safe to run again (the job runs again after Claude answered): every step skips
     * what it already did.
     *
     * @return array<string, mixed> the change's result
     */
    public function apply(BrandDirectionChange $change): array
    {
        $brand = Brand::query()->find($change->brand_id);
        if ($brand === null) {
            return [];
        }
        $result = (array) ($change->result ?? []);
        $save = function () use ($change, &$result): void {
            $change->forceFill(['result' => $result])->save();
        };
        $major = $change->magnitude === BrandDirectionChange::MAJOR;
        $sites = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->orderBy('id')->get();

        if (! isset($result['memory'])) {
            $result['memory'] = $this->step(fn () => app(BrandMemoryService::class)->refreshProfile($brand) !== [] ? 'ok' : 'empty');
            $result['clusters_mapped'] = $sites->map(fn (DigitalAsset $site): string => $this->step(fn () => (string) app(ClusterPageMapper::class)->refresh($site, judge: false)['status']))->all();
            $result['ideas_of_removed_services'] = $this->closeRemovedServiceIdeas($brand, $change);
            // A rebuilt site: work items of pages that left it close now, not at the night's audit.
            $result['orphan_items_closed'] = $this->step(fn () => (string) PageStore::closeOrphanFixes());
            $save();
        }

        if ($major && ! isset($result['drafts_closed'])) {
            // A draft written before the change used the old brand facts: it is closed; a topic that still fits comes back
            // from the pool and is written again on the current direction.
            $result['drafts_closed'] = 0;
            self::unsentIdeas($brand)->where('created_at', '<=', $change->created_at)->whereNotNull('action->article')->get()
                ->each(function (Suggestion $s) use (&$result): void {
                    $this->close($s, 'Marka rotası değişti: taslak eski rotaya göre yazılmıştı; konu hâlâ uygunsa havuz yeniden önerir.');
                    $result['drafts_closed']++;
                });
            $save();
        }

        if ($major) {
            $fit = $this->fit($brand, $change, $result);
            $result['fit'] = $fit['verdicts'];
            $result['fit_waiting'] = $fit['waiting'];
            $result['fit_status'] = $fit['status'];
            $result['clusters_set_aside'] = count(array_filter($fit['verdicts'], fn (array $v, string $k): bool => str_starts_with($k, 'cluster:') && $v['verdict'] === 'off_direction', ARRAY_FILTER_USE_BOTH));
            $result['ideas_closed'] = count(array_filter($fit['verdicts'], fn (array $v, string $k): bool => str_starts_with($k, 'idea:') && $v['verdict'] === 'off_direction', ARRAY_FILTER_USE_BOTH));
            $save();
            if ($fit['waiting'] > 0) {
                return $result; // the job runs again once Claude answered
            }
            if (! isset($result['gbp_drafts_skipped'])) {
                $result['gbp_drafts_skipped'] = $this->skipGbpDrafts($brand, $change);
                $result['keyword_planner'] = $this->step(fn () => json_encode(app(KeywordPlanner::class)->run((int) $brand->id, force: true)['skipped'] ?: 'ok'));
                $result['epicrisis'] = $this->step(function () use ($brand): string {
                    RunBrandEpicrisisJob::dispatch((int) $brand->id, true);

                    return 'queued';
                });
                $save();
            }
        }

        if (! isset($result['content_pool'])) {
            $result['content_pool'] = ContentCoverage::automaticAllowed()
                ? $sites->map(fn (DigitalAsset $site): string => $this->step(fn () => (string) Artisan::call('moxdop:content:weekly-titles', ['--site' => (int) $site->id])))->all()
                : 'otomatik içerik kapalı';
        }
        RefreshBrandFilesJob::soon((int) $brand->id);
        $change->forceFill(['result' => $result, 'applied_at' => now()])->save();

        return $result;
    }

    /** Unsent content ideas whose cluster belongs to a service the brand no longer sells. @return int closed */
    private function closeRemovedServiceIdeas(Brand $brand, BrandDirectionChange $change): int
    {
        $serviceIds = SiteScope::offerings($brand)->pluck('service_catalog_item_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $removed = array_map(fn ($s): string => mb_strtolower((string) $s), (array) ($change->removed ?? []));
        $closed = 0;
        self::unsentIdeas($brand)->get()
            ->filter(function (Suggestion $s) use ($serviceIds, $removed): bool {
                if ($s->cluster_id !== null) {
                    $serviceId = Cluster::query()->whereKey($s->cluster_id)->value('service_id');

                    return $serviceId !== null && ! in_array((int) $serviceId, $serviceIds, true);
                }

                return $removed !== [] && in_array(mb_strtolower(trim((string) data_get($s->action, 'service', ''))), $removed, true);
            })
            ->each(function (Suggestion $s) use (&$closed): void {
                $this->close($s, 'Marka rotası değişti: bu fikrin hizmeti artık markada yok.');
                $closed++;
            });

        return $closed;
    }

    /**
     * Claude reads the brand's cluster rows and the ideas made before the change against the current direction.
     *
     * @param  array<string, mixed>  $result
     * @return array{verdicts: array<string, array{verdict: string, reason: string}>, waiting: int, status: string}
     */
    private function fit(Brand $brand, BrandDirectionChange $change, array $result): array
    {
        $verdicts = (array) ($result['fit'] ?? []);
        if (! SiteScope::aiAllowed($brand)) {
            return ['verdicts' => $verdicts, 'waiting' => 0, 'status' => 'ai_off'];
        }
        $items = $this->fitItems($brand, $change)->reject(fn (array $i): bool => isset($verdicts[$i['kind'].':'.$i['id']]))->values();
        $waiting = 0;
        $status = 'ready';
        $snapshot = self::snapshot($brand);
        foreach ($items->chunk(self::BATCH) as $batch) {
            $batch = $batch->values();
            $answer = $this->ai->run(new BrandDirectionFitAgent, [
                'brand' => ['name' => $brand->name, 'summary' => $snapshot['summary'], 'positioning' => $snapshot['positioning'], 'audiences' => $snapshot['audiences'],
                    'services' => $snapshot['services'], 'constraints' => mb_substr($snapshot['constraints'], 0, 1500)],
                'items' => $batch->map(fn (array $i): array => array_diff_key($i, ['created_at' => true]))->all(),
            ], 240, 'brand-direction-fit-'.$change->id.'-'.md5($batch->map(fn (array $i): string => $i['kind'].$i['id'])->implode(',')));
            if ($answer['status'] === 'queued') {
                $waiting += $batch->count();

                continue;
            }
            if ($answer['status'] !== 'ready') {
                $status = (string) $answer['status'];

                continue;
            }
            $byKey = collect((array) ($answer['data']['items'] ?? []))->filter(fn ($a): bool => is_array($a) && is_int($a['id'] ?? null) && is_string($a['kind'] ?? null))
                ->keyBy(fn (array $a): string => $a['kind'].':'.$a['id']);
            foreach ($batch as $item) {
                $key = $item['kind'].':'.$item['id'];
                $got = (array) $byKey->get($key, []);
                $verdict = in_array($got['verdict'] ?? null, BrandDirectionFitAgent::VERDICTS, true) ? (string) $got['verdict'] : 'unsure';
                $reason = mb_substr(trim((string) ($got['reason'] ?? '')), 0, 160);
                $verdicts[$key] = ['verdict' => $verdict, 'reason' => $reason];
                if ($verdict === 'off_direction') {
                    $item['kind'] === 'cluster' ? $this->setAside($brand, (int) $item['id'], $reason) : $this->closeIdea((int) $item['id'], $reason);
                }
            }
        }

        return ['verdicts' => $verdicts, 'waiting' => $waiting, 'status' => $status];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function fitItems(Brand $brand, BrandDirectionChange $change): Collection
    {
        $clusterIds = BrandClusterPage::query()->where('brand_id', $brand->id)->where('excluded', false)->where('locked', false)->distinct()->pluck('cluster_id');
        $clusters = Cluster::query()->with(['mainQuery', 'service.primaryName'])->whereIn('id', $clusterIds->all() ?: [0])->orderBy('id')->get()
            ->map(fn (Cluster $c): array => ['kind' => 'cluster', 'id' => (int) $c->id, 'service' => (string) ($c->service?->primaryName?->raw_label ?? ''),
                'name' => (string) $c->name, 'main_query' => (string) ($c->mainQuery?->text ?? '')]);
        $ideas = self::unsentIdeas($brand)->where('created_at', '<=', $change->created_at)->orderBy('id')->get(['id', 'title', 'action'])
            ->map(fn (Suggestion $s): array => ['kind' => 'idea', 'id' => (int) $s->id, 'service' => (string) data_get($s->action, 'service', ''), 'title' => (string) $s->title]);

        return $clusters->concat($ideas)->values();
    }

    /** The brand no longer targets this cluster: its unlocked rows are excluded (restorable on İçerik fikirleri). */
    private function setAside(Brand $brand, int $clusterId, string $reason): void
    {
        BrandClusterPage::query()->where('brand_id', $brand->id)->where('cluster_id', $clusterId)->where('locked', false)
            ->update(['excluded' => true, 'decided_by' => 'rota', 'reason' => mb_substr('Marka rotası dışında'.($reason !== '' ? ': '.$reason : '.'), 0, 300), 'updated_at' => now()]);
    }

    private function closeIdea(int $id, string $reason): void
    {
        $idea = Suggestion::query()->find($id);
        if ($idea !== null && in_array($idea->status, self::UNSENT, true) && ! isset(((array) $idea->action)['article_write_id'])) {
            $this->close($idea, 'Marka rotası dışında'.($reason !== '' ? ': '.$reason : '.'));
        }
    }

    private function close(Suggestion $idea, string $note): void
    {
        $idea->forceFill(['status' => Suggestion::DISMISSED, 'resolved_at' => now(), 'operator_note' => mb_substr($note, 0, 500)])->save();
    }

    /** Business Profile post drafts (not approved) of the coming days written before the change: the queue writes new ones. */
    private function skipGbpDrafts(Brand $brand, BrandDirectionChange $change): int
    {
        if (! Schema::hasTable('gbp_post_queue')) {
            return 0;
        }

        return GbpQueuedPost::query()->where('brand_id', $brand->id)->where('status', GbpQueuedPost::DRAFT)
            ->where('created_at', '<=', $change->created_at)->where('publish_on', '>=', now('Europe/Istanbul')->toDateString())
            ->update(['status' => GbpQueuedPost::SKIPPED, 'note' => 'Marka rotası değişti; yeni rotaya göre yeniden yazılır.', 'updated_at' => now()]);
    }

    /** @return Builder<Suggestion> content ideas not sent to the site yet */
    public static function unsentIdeas(Brand $brand): Builder
    {
        return Suggestion::query()->where('brand_id', $brand->id)->where('action_type', SiteSuggestionTypes::CONTENT)
            ->whereIn('status', self::UNSENT)->whereNull('action->article_write_id');
    }

    private function step(callable $run): string
    {
        try {
            return (string) $run();
        } catch (Throwable $e) {
            report($e);

            return 'hata: '.mb_substr($e->getMessage(), 0, 120);
        }
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{4,}/u', mb_strtolower($text), $m);

        return array_values(array_unique($m[0]));
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function overlap(array $a, array $b): float
    {
        $a = array_unique($a);
        $b = array_unique($b);
        $union = count(array_unique([...$a, ...$b]));

        return $union === 0 ? 1.0 : count(array_intersect($a, $b)) / $union;
    }
}
