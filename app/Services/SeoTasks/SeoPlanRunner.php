<?php

namespace App\Services\SeoTasks;

use App\Jobs\RunSeoPlanJob;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\SeoPlan;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Orchestrates one SEO plan run: A) collect → B) rules → C) LLM (optional) → D) write.
 * Runs on the queue; the operator never waits on the page.
 */
final class SeoPlanRunner
{
    public const string OPERATION_TYPE = 'seo_plan';

    public function __construct(
        private readonly SeoPlanInputCollector $collector,
        private readonly SeoSiteUnderstanding $understanding,
        private readonly SeoTaskRuleEngine $rules,
        private readonly SeoPlanAiEnricher $enricher,
        private readonly SeoPlanWriter $writer,
        private readonly AsyncOperationService $async,
    ) {}

    /**
     * Queue a plan for one website. Returns the existing queued/running plan if one is pending.
     * Plans are rule-based; AI (site understanding + content briefs) runs only when $useAi is true, i.e. the
     * operator clicked "Briefleri AI ile hazırla". Scheduled and bulk plans never spend tokens.
     */
    public function queue(DigitalAsset $site, ?User $actor = null, string $trigger = 'manual', bool $useAi = false): SeoPlan
    {
        if ($site->type !== 'website') {
            throw ValidationException::withMessages(['asset' => 'SEO planı yalnızca web sitesi varlıkları için üretilir.']);
        }
        if (! (bool) config('moxdop-seo-tasks.enabled', true)) {
            throw ValidationException::withMessages(['asset' => 'SEO Görevleri devre dışı (SEO_TASKS_ENABLED).']);
        }

        return Cache::lock('seo-plan:'.$site->id, 15)->block(5, function () use ($site, $actor, $trigger, $useAi): SeoPlan {
            $pending = SeoPlan::query()
                ->where('digital_asset_id', $site->id)
                ->whereIn('status', [SeoPlan::STATUS_QUEUED, SeoPlan::STATUS_RUNNING])
                ->where('updated_at', '>=', now()->subMinutes(20))
                ->latest('id')
                ->first();
            if ($pending !== null) {
                return $pending;
            }

            $site->loadMissing('brand');
            $version = (int) SeoPlan::query()->where('digital_asset_id', $site->id)->max('version') + 1;

            return DB::transaction(function () use ($site, $actor, $trigger, $version, $useAi): SeoPlan {
                $activity = Run::query()->create([
                    'digital_asset_id' => $site->id,
                    'module_id' => 'website',
                    'status' => 'queued',
                    'started_at' => now(),
                    'metadata' => [
                        'async' => true,
                        'operation_type' => self::OPERATION_TYPE,
                        'human_title' => 'SEO planı: '.($site->domain ?: $site->name),
                        'phase' => 'queued',
                        'phase_label' => 'Kuyrukta',
                        'progress_at' => now()->toIso8601String(),
                        'triggered_by_user_id' => $actor?->id,
                        'provider_calls' => 0,
                        'ai_calls' => 0,
                    ],
                ]);

                $plan = SeoPlan::query()->create([
                    'customer_id' => $site->brand?->customer_id,
                    'brand_id' => $site->brand_id,
                    'digital_asset_id' => $site->id,
                    'status' => SeoPlan::STATUS_QUEUED,
                    'trigger' => $trigger,
                    'version' => $version,
                    'requested_by' => $actor?->id,
                    'input_summary' => ['activity_run_id' => $activity->id, 'use_ai' => $useAi],
                ]);

                $connection = (string) config('moxdop-seo-tasks.queue_connection', config('queue.default'));
                $queue = (string) config('moxdop-seo-tasks.queue', 'default');
                dispatch(new RunSeoPlanJob($plan->id))
                    ->onConnection($connection)
                    ->onQueue($queue)
                    ->afterCommit();

                return $plan;
            });
        });
    }

    /**
     * Queue every eligible website. $onlyConnected limits to sites with an active Search Console binding.
     *
     * @return Collection<int, SeoPlan>
     */
    public function queueAll(?User $actor = null, bool $onlyConnected = false, string $trigger = 'bulk', ?int $limit = null): Collection
    {
        // Only operational sites (asset + customer active), least recently planned first so a
        // per-tick limit rotates through the whole portfolio instead of the first N ids.
        $query = DigitalAsset::query()
            ->operational()
            ->where('type', 'website')
            ->whereNotNull('brand_id')
            ->orderBy(SeoPlan::query()->selectRaw('max(created_at)')->whereColumn('seo_plans.digital_asset_id', 'digital_assets.id'))
            ->orderBy('id');

        if ($onlyConnected) {
            $query->whereIn('id', CoreAssetBinding::query()
                ->where('capability', 'search_console')
                ->where('status', CoreAssetBinding::STATUS_ACTIVE)
                ->select('digital_asset_id'));
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        $plans = collect();
        foreach ($query->get() as $site) {
            try {
                $plans->push($this->queue($site, $actor, $trigger));
            } catch (ValidationException) {
                continue;
            }
        }

        return $plans;
    }

    /** Execute a queued plan (called by the job). */
    public function run(int $planId): SeoPlan
    {
        /** @var SeoPlan $plan */
        $plan = SeoPlan::query()->with('digitalAsset.brand')->findOrFail($planId);
        if ($plan->isTerminal()) {
            return $plan;
        }
        $activity = $this->activity($plan);
        $plan->forceFill(['status' => SeoPlan::STATUS_RUNNING, 'started_at' => now()])->save();
        if ($activity !== null) {
            $this->async->markRunning($activity, 'collecting', 'Veri paketi toplanıyor');
        }

        try {
            $site = $plan->digitalAsset;
            $input = $this->collector->collect($site);
            $plan->forceFill([
                'period_start' => $input['period']['start'],
                'period_end' => $input['period']['end'],
            ])->save();

            if ($input['offerings'] === [] && $activity !== null) {
                $this->async->setPhase($activity, 'understanding', 'Marka hizmetleri siteden çıkarılıyor');
            }
            $useAi = (bool) ($plan->input_summary['use_ai'] ?? false);
            $resolved = $this->understanding->resolve($plan, $input, $useAi);
            $input['offerings'] = $resolved['offerings'];
            $input['understanding'] = $resolved['understanding'];

            if ($activity !== null) {
                $this->async->setPhase($activity, 'rules', 'Kurallar değerlendiriliyor');
            }
            $result = $this->rules->evaluate($input);

            if ($activity !== null && $useAi) {
                $this->async->setPhase($activity, 'llm', 'İçerik briefleri hazırlanıyor');
            }
            $enriched = $this->enricher->enrich($plan, $input, $result['tasks'], $useAi);

            if ($activity !== null) {
                $this->async->setPhase($activity, 'writing', 'Görevler yazılıyor');
            }
            $written = $this->writer->write($plan, $enriched['tasks'], $result['assignments']);

            // Keep index status of the pages that matter fresh; findings appear in the next plan.
            $inspectionQueue = SeoTaskConfig::get('indexing.queue_inspection', true)
                ? app(SeoUrlInspectionQueue::class)->queue($site, $result['inspection_targets'] ?? [])
                : ['status' => 'disabled', 'targets' => 0];

            $summary = $this->summaryText($written['counts']);
            $plan->forceFill([
                'status' => SeoPlan::STATUS_COMPLETED,
                'completed_at' => now(),
                'input_summary' => array_merge($plan->input_summary ?? [], [
                    'period' => $input['period'],
                    'gsc' => ['available' => $input['gsc']['available'], 'reason' => $input['gsc']['reason'], 'query_count' => $input['gsc']['query_count'], 'page_count' => $input['gsc']['page_count'], 'truncated' => $input['gsc']['truncated']],
                    'pages' => count($input['pages']),
                    'findings' => count($input['findings']),
                    'offerings' => $result['stats']['offerings'],
                    'priority_offerings' => $result['stats']['priority_offerings'],
                    'matched_queries' => $result['stats']['matched_queries'],
                    'ga4_available' => $input['ga4']['available'],
                    'robots_available' => $input['robots']['available'],
                    'html' => $input['html'] ?? null,
                    'depth' => [
                        'page_traffic_pages' => count($input['traffic']['pages'] ?? []),
                        'history_days' => $input['traffic']['history_days'] ?? 0,
                        'inspected_pages' => count($input['inspections'] ?? []),
                        'sitemaps' => count($input['sitemaps'] ?? []),
                        'link_graph' => (bool) ($input['links']['available'] ?? false),
                        'speed_measured_pages' => count($input['performance'] ?? []),
                        'business_profile' => ($input['gbp'] ?? null) !== null,
                        'inspection_queue' => $inspectionQueue,
                    ],
                    'site_understanding' => $resolved['understanding'],
                ]),
                'llm_summary' => $enriched['summary'],
                'result_summary' => $written,
                'summary_text' => $summary,
            ])->save();

            if ($activity !== null) {
                $this->async->markFinished($activity, 'completed', 'Tamamlandı', [
                    'result_summary' => $summary,
                    'seo_plan_id' => $plan->id,
                    'ai_calls' => (int) ($enriched['summary']['calls'] ?? 0) + $resolved['calls'],
                ]);
            }
        } catch (Throwable $exception) {
            $this->markFailed($plan, $exception);

            throw $exception;
        }

        return $plan->fresh() ?? $plan;
    }

    public function markFailed(SeoPlan|int $plan, Throwable $exception): void
    {
        $plan = $plan instanceof SeoPlan ? $plan : SeoPlan::query()->find($plan);
        if ($plan === null || $plan->status === SeoPlan::STATUS_FAILED) {
            return;
        }
        $plan->forceFill([
            'status' => SeoPlan::STATUS_FAILED,
            'failed_at' => now(),
            'error_summary' => mb_substr($exception->getMessage(), 0, 500),
        ])->save();
        $activity = $this->activity($plan);
        if ($activity !== null && ! in_array($activity->status, ['completed', 'partial', 'failed'], true)) {
            $this->async->markFailed($activity, $exception);
        }
    }

    /** @param array<string, int> $counts */
    public function summaryText(array $counts): string
    {
        $labels = ['fix' => 'düzelt', 'strengthen' => 'güçlendir', 'create' => 'oluştur', 'ai_visibility' => 'AI görünürlük', 'question' => 'soru'];
        $total = array_sum($counts);
        $parts = [];
        foreach ($labels as $key => $label) {
            if (($counts[$key] ?? 0) > 0) {
                $parts[] = $counts[$key].' '.$label;
            }
        }

        return $total.' görev'.($parts !== [] ? ': '.implode(', ', $parts) : '');
    }

    private function activity(SeoPlan $plan): ?Run
    {
        $id = data_get($plan->input_summary, 'activity_run_id');

        return is_numeric($id) ? Run::query()->find((int) $id) : null;
    }
}
