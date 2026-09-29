<?php

namespace App\Services\Operations;

use App\Jobs\PilotRefreshStepJob;
use App\Jobs\Queries\AssignQuerySectorsJob;
use App\Jobs\Queries\ClassifyUnmatchedQueriesJob;
use App\Jobs\Queries\ClusterDueServicesJob;
use App\Jobs\Queries\FinishQueryPipelineJob;
use App\Jobs\Queries\IngestQuerySourcesJob;
use App\Jobs\Queries\MatchQueriesJob;
use App\Jobs\RefreshUrlVerdictsJob;
use App\Jobs\RunSeoPlanJob;
use App\Models\AnalystRun;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\SeoPlan;
use App\Models\WebsiteUrlAudit;
use App\Services\Analyst\AnalystEngine;
use App\Services\Analyst\AnalystRegistry;
use App\Services\ContentStudio\TopicMapBuilder;
use App\Services\Demand\BrandDemandBuilder;
use App\Services\Queries\QueryPipeline;
use App\Services\SeoTasks\SeoPlanRunner;
use App\Services\Website\UrlAudit\UrlAuditService;
use App\Support\ServiceScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * moxdop:pilot:refresh — one command that runs a brand's whole analysis chain on the server, in dependency order,
 * as ONE queued chain on the heavy queue (each step starts only after the previous one finished):
 *
 *   1. Sorgu hattı for the brand's accounts (ingest chunks → sectors → rules → AI fallback) — "N yeni sorgu"
 *   2. Kümeleme (AI clusters of changed services + SERP page type)
 *   3. Marka talep tablosu (query hub; the topic map reads it)
 *   4. Konu haritası per website            5. SEO planı per website (reads the topic map)
 *   6. Sayfa Karnesi (URL verdicts) per website   7. The live analysts (Arama, Harita, Google Ads, Meta)
 *
 * Every step is idempotent; a failed step stops the chain and releases the locks, so the command can simply be run
 * again. Progress is kept for `--status`.
 */
final class PilotRefresh
{
    public const array STEPS = [
        'queries' => 'Sorgu hattı (markanın hesapları: çekim → sektör → kural → AI)',
        'clusters' => 'Sorgu kümeleme (AI) + SERP sayfa türü',
        'demand' => 'Marka talep tablosu (sorgu merkezi)',
        'topic_map' => 'Konu haritası',
        'seo_plan' => 'SEO planı',
        'url_verdicts' => 'Sayfa Karnesi (URL kararları)',
        'analysts' => 'Kanal analistleri',
    ];

    public function __construct(
        private readonly QueryPipeline $pipeline,
        private readonly ServiceScope $scope,
    ) {}

    public static function lockKey(int $brandId): string
    {
        return 'pilot-refresh:brand:'.$brandId;
    }

    public static function stateKey(int $brandId): string
    {
        return 'pilot-refresh:state:'.$brandId;
    }

    /** @return Collection<int, DigitalAsset> operational websites of the brand */
    public function websites(Brand $brand): Collection
    {
        return DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->orderBy('id')->get()
            ->filter(fn (DigitalAsset $site): bool => $this->scope->isAssetOperational($site->id))->values();
    }

    /**
     * Dry run: the steps with what each will work on and the current state of its output.
     *
     * @return list<array{step: string, label: string, target: string, current: string}>
     */
    public function plan(Brand $brand): array
    {
        $sites = $this->websites($brand);
        $keys = $this->pipeline->sourceKeysForBrand($brand);
        $last = QueryPipeline::lastRun('brand:'.$brand->id) ?? QueryPipeline::lastRun();
        $siteNames = $sites->map(fn (DigitalAsset $s): string => '#'.$s->id.' '.($s->domain ?: $s->name))->implode(', ') ?: 'operasyonel web sitesi yok';
        $perSite = function (callable $state) use ($sites): string {
            return $sites->map(fn (DigitalAsset $s): string => ($s->domain ?: '#'.$s->id).': '.$state($s))->implode(' · ') ?: '—';
        };
        $channels = app(AnalystRegistry::class)->liveChannels();

        return [
            ['step' => 'queries', 'label' => self::STEPS['queries'], 'target' => count($keys).' kaynak hesap/site',
                'current' => $last['summary'] ?? 'henüz çalışmadı'],
            ['step' => 'clusters', 'label' => self::STEPS['clusters'], 'target' => 'üyeliği değişen hizmetler', 'current' => '—'],
            ['step' => 'demand', 'label' => self::STEPS['demand'], 'target' => '#'.$brand->id.' '.$brand->name,
                'current' => DB::table('brand_demand_queries')->where('brand_id', $brand->id)->count().' sorgu'],
            ['step' => 'topic_map', 'label' => self::STEPS['topic_map'], 'target' => $siteNames,
                'current' => $perSite(fn (DigitalAsset $s): string => app(TopicMapBuilder::class)->latest($s)?->status ?? 'yok')],
            ['step' => 'seo_plan', 'label' => self::STEPS['seo_plan'], 'target' => $siteNames,
                'current' => $perSite(fn (DigitalAsset $s): string => (string) (SeoPlan::query()->where('digital_asset_id', $s->id)->latest('id')->value('status') ?? 'yok'))],
            ['step' => 'url_verdicts', 'label' => self::STEPS['url_verdicts'], 'target' => $siteNames,
                'current' => $perSite(fn (DigitalAsset $s): string => (string) (WebsiteUrlAudit::query()->where('digital_asset_id', $s->id)->value('status') ?? 'yok'))],
            ['step' => 'analysts', 'label' => self::STEPS['analysts'], 'target' => implode(', ', $channels),
                'current' => collect($channels)->map(fn (string $c): string => $c.': '.(AnalystRun::query()->where('brand_id', $brand->id)->where('channel', $c)->latest('id')->value('status') ?? 'yok'))->implode(' · ')],
        ];
    }

    /**
     * Queue the chain. Refused while a pilot refresh of this brand is still running.
     *
     * @return array{queued: bool, message: string, jobs: int}
     */
    public function dispatch(Brand $brand): array
    {
        $this->scope->ensureBrandServed($brand, 'brand');
        $lock = Cache::lock(self::lockKey($brand->id), 4 * 3600);
        if (! $lock->get()) {
            return ['queued' => false, 'message' => 'Bu marka için pilot yenileme zaten sürüyor (--status ile izleyin).', 'jobs' => 0];
        }
        try {
            $owner = $lock->owner();
            $pipelineLock = Cache::lock('queries:pipeline:brand:'.$brand->id, 3600);
            $pipelineOwner = $pipelineLock->get() ? $pipelineLock->owner() : null;
            $runId = (string) Str::uuid();
            $keys = $this->pipeline->sourceKeysForBrand($brand);
            $jobs = [];
            foreach (array_chunk($keys, max(1, (int) config('moxdop-queries.sources_per_job', 10))) as $chunk) {
                $jobs[] = new IngestQuerySourcesJob($runId, $chunk);
            }
            $jobs[] = new AssignQuerySectorsJob($runId, null);
            $jobs[] = new MatchQueriesJob($runId);
            $jobs[] = new ClassifyUnmatchedQueriesJob($runId, max(0, (int) config('moxdop-queries.classify_per_run', 600)));
            $jobs[] = new FinishQueryPipelineJob($runId, null, (string) $pipelineOwner, count($keys), 'queries:pipeline:brand:'.$brand->id, 'brand:'.$brand->id);
            $jobs[] = new PilotRefreshStepJob('mark:queries', $brand->id);
            $jobs[] = new ClusterDueServicesJob;
            $jobs[] = new PilotRefreshStepJob('demand', $brand->id);
            $sites = $this->websites($brand);
            foreach (['topic_map', 'seo_plan', 'url_verdicts'] as $step) {
                foreach ($sites as $site) {
                    $jobs[] = new PilotRefreshStepJob($step, $brand->id, $site->id);
                }
            }
            $jobs[] = new PilotRefreshStepJob('analysts', $brand->id);
            $jobs[] = new PilotRefreshStepJob('finish', $brand->id, null, $owner);

            Cache::put(self::stateKey($brand->id), ['started_at' => now()->toIso8601String(), 'status' => 'running', 'steps' => []], now()->addDays(7));
            $brandId = $brand->id;
            Bus::chain($jobs)
                ->onQueue((string) config('queue.heavy_queue', 'default'))
                ->catch(function (Throwable $exception) use ($brandId, $owner, $pipelineOwner): void {
                    PilotRefresh::record($brandId, 'failed', 'failed', mb_substr($exception->getMessage(), 0, 300));
                    Cache::restoreLock(PilotRefresh::lockKey($brandId), $owner)->release();
                    if ($pipelineOwner !== null) {
                        Cache::restoreLock('queries:pipeline:brand:'.$brandId, $pipelineOwner)->release();
                    }
                })
                ->dispatch();

            return ['queued' => true, 'message' => count($jobs).' adımlık zincir heavy kuyruğuna alındı.', 'jobs' => count($jobs)];
        } catch (Throwable $exception) {
            $lock->release();

            throw $exception;
        }
    }

    /**
     * Executes one step (called by PilotRefreshStepJob); may return a job to run right after it.
     */
    public function runStep(string $step, Brand $brand, ?DigitalAsset $site, ?string $lockOwner): ?object
    {
        $label = $site !== null ? $step.':'.$site->id : $step;
        switch ($step) {
            case 'mark:queries':
                self::record($brand->id, 'queries', 'done', (string) (QueryPipeline::lastRun('brand:'.$brand->id)['summary'] ?? ''));

                return null;
            case 'demand':
                self::record($brand->id, 'clusters', 'done');
                $stats = app(BrandDemandBuilder::class)->build($brand);
                self::record($brand->id, 'demand', 'done', sprintf('%d sorgu, %d hizmete atandı', $stats['queries'] ?? 0, $stats['assigned'] ?? 0));

                return null;
            case 'topic_map':
                $build = app(TopicMapBuilder::class)->buildRecorded($site, 'pilot');
                $stats = (array) $build->stats;
                self::record($brand->id, $label, (string) $build->status, $build->status === 'done'
                    ? sprintf('%d sorgu, %d konu kümesi', $stats['queries'] ?? 0, $stats['clusters'] ?? 0) : (string) $build->error);

                return null;
            case 'seo_plan':
                $plan = app(SeoPlanRunner::class)->queue($site, null, 'pilot', false, dispatchJob: false);
                if (! $plan->wasRecentlyCreated) {
                    self::record($brand->id, $label, 'skipped', 'Plan #'.$plan->id.' zaten sırada/çalışıyor.');

                    return null;
                }
                self::record($brand->id, $label, 'queued', 'Plan #'.$plan->id);

                return (new RunSeoPlanJob($plan->id))->onQueue((string) config('moxdop-seo-tasks.queue', config('queue.heavy_queue', 'default')));
            case 'url_verdicts':
                $result = app(UrlAuditService::class)->refresh($site, 'pilot');
                if ($result['status'] === 'busy') {
                    Cache::put(RefreshUrlVerdictsJob::rerunKey($site->id), 'pilot', now()->addHours(2));
                }
                self::record($brand->id, $label, $result['status'], $result['urls'].' URL');

                return null;
            case 'analysts':
                $engine = app(AnalystEngine::class);
                $queued = [];
                foreach (app(AnalystRegistry::class)->liveChannels() as $channel) {
                    try {
                        $run = $engine->queue($brand, $channel, null, 'manual');
                        $queued[] = $channel.' #'.$run->id;
                    } catch (Throwable $exception) {
                        report($exception);
                        $queued[] = $channel.' (hata: '.mb_substr($exception->getMessage(), 0, 80).')';
                    }
                }
                self::record($brand->id, 'analysts', 'queued', implode(', ', $queued));

                return null;
            case 'finish':
                $state = (array) Cache::get(self::stateKey($brand->id), []);
                Cache::put(self::stateKey($brand->id), ['status' => 'completed', 'finished_at' => now()->toIso8601String()] + $state, now()->addDays(7));
                if ($lockOwner !== null) {
                    Cache::restoreLock(self::lockKey($brand->id), $lockOwner)->release();
                }

                return null;
        }

        return null;
    }

    public static function record(int $brandId, string $step, string $status, string $note = ''): void
    {
        $state = (array) Cache::get(self::stateKey($brandId), []);
        $state['steps'][$step] = ['status' => $status, 'at' => now()->toIso8601String(), 'note' => $note];
        if ($status === 'failed') {
            $state['status'] = 'failed';
        }
        Cache::put(self::stateKey($brandId), $state, now()->addDays(7));
    }

    /** @return array<string, mixed>|null */
    public static function state(int $brandId): ?array
    {
        $state = Cache::get(self::stateKey($brandId));

        return is_array($state) ? $state : null;
    }
}
