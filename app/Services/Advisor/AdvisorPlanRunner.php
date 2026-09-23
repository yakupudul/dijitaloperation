<?php

namespace App\Services\Advisor;

use App\Jobs\RunAdvisorPlanJob;
use App\Models\AdvisorPlan;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Orchestrates one advisor run for one asset: collect stored data → rules → write. No AI, no provider
 * calls; runs on the queue. Channel = asset type (Google Ads, Meta Ads).
 */
final class AdvisorPlanRunner
{
    public const string OPERATION_TYPE = 'advisor_plan';

    public function __construct(
        private readonly AdvisorChannels $channels,
        private readonly AdvisorPlanWriter $writer,
        private readonly AsyncOperationService $async,
    ) {}

    public function queue(DigitalAsset $asset, ?User $actor = null, string $trigger = 'manual'): AdvisorPlan
    {
        $channel = $this->channels->forAssetType((string) $asset->type)?->channel();
        if ($channel === null) {
            throw ValidationException::withMessages(['asset' => 'Danışman bu varlık türü için henüz yok.']);
        }
        if (! (bool) config('moxdop-advisor.enabled', true)) {
            throw ValidationException::withMessages(['asset' => 'Danışman devre dışı (ADVISOR_ENABLED).']);
        }

        return Cache::lock('advisor-plan:'.$asset->id, 15)->block(5, function () use ($asset, $actor, $trigger, $channel): AdvisorPlan {
            $pending = AdvisorPlan::query()
                ->where('digital_asset_id', $asset->id)
                ->where('channel', $channel)
                ->whereIn('status', [AdvisorPlan::STATUS_QUEUED, AdvisorPlan::STATUS_RUNNING])
                ->where('updated_at', '>=', now()->subMinutes(20))
                ->latest('id')
                ->first();
            if ($pending !== null) {
                return $pending;
            }
            $asset->loadMissing('brand');
            $version = (int) AdvisorPlan::query()->where('digital_asset_id', $asset->id)->where('channel', $channel)->max('version') + 1;

            return DB::transaction(function () use ($asset, $actor, $trigger, $channel, $version): AdvisorPlan {
                $activity = Run::query()->create([
                    'digital_asset_id' => $asset->id,
                    'module_id' => $channel,
                    'status' => 'queued',
                    'started_at' => now(),
                    'metadata' => [
                        'async' => true,
                        'operation_type' => self::OPERATION_TYPE,
                        'human_title' => $this->channels->get($channel)->label().' danışmanı: '.$asset->name,
                        'phase' => 'queued',
                        'phase_label' => 'Kuyrukta',
                        'progress_at' => now()->toIso8601String(),
                        'triggered_by_user_id' => $actor?->id,
                        'provider_calls' => 0,
                        'ai_calls' => 0,
                    ],
                ]);
                $plan = AdvisorPlan::query()->create([
                    'channel' => $channel,
                    'customer_id' => $asset->brand?->customer_id,
                    'brand_id' => $asset->brand_id,
                    'digital_asset_id' => $asset->id,
                    'status' => AdvisorPlan::STATUS_QUEUED,
                    'trigger' => $trigger,
                    'version' => $version,
                    'requested_by' => $actor?->id,
                    'input_summary' => ['activity_run_id' => $activity->id],
                ]);
                dispatch(new RunAdvisorPlanJob($plan->id))
                    ->onConnection((string) config('moxdop-advisor.queue_connection', config('queue.default')))
                    ->onQueue((string) config('moxdop-advisor.queue', 'default'))
                    ->afterCommit();

                return $plan;
            });
        });
    }

    /**
     * Queue every active advertising asset; $onlyConnected limits to assets with an active binding.
     *
     * @return Collection<int, AdvisorPlan>
     */
    public function queueAll(?User $actor = null, bool $onlyConnected = true, string $trigger = 'bulk'): Collection
    {
        $query = DigitalAsset::query()->whereIn('type', $this->channels->assetTypes())->where('status', 'active')->orderBy('id');
        if ($onlyConnected) {
            $capabilities = array_values(array_map(static fn (AdvisorChannel $c): string => $c->bindingCapability(), $this->channels->all()));
            $query->whereIn('id', CoreAssetBinding::query()->whereIn('capability', $capabilities)->where('status', CoreAssetBinding::STATUS_ACTIVE)->select('digital_asset_id'));
        }
        $plans = collect();
        foreach ($query->get() as $asset) {
            try {
                $plans->push($this->queue($asset, $actor, $trigger));
            } catch (ValidationException) {
                continue;
            }
        }

        return $plans;
    }

    public function run(int $planId): AdvisorPlan
    {
        /** @var AdvisorPlan $plan */
        $plan = AdvisorPlan::query()->with('digitalAsset.brand')->findOrFail($planId);
        if (in_array($plan->status, [AdvisorPlan::STATUS_COMPLETED, AdvisorPlan::STATUS_FAILED], true)) {
            return $plan;
        }
        $activity = $this->activity($plan);
        $plan->forceFill(['status' => AdvisorPlan::STATUS_RUNNING, 'started_at' => now()])->save();
        if ($activity !== null) {
            $this->async->markRunning($activity, 'collecting', 'Reklam verisi okunuyor');
        }

        try {
            $channel = $this->channels->get($plan->channel);
            $input = $channel->collect($plan->digitalAsset);
            if ($activity !== null) {
                $this->async->setPhase($activity, 'rules', 'Kurallar değerlendiriliyor');
            }
            $result = $channel->evaluate($input);
            $written = $this->writer->write($plan, $result['items']);
            $summary = $this->summaryText($result, $input, $channel);

            $plan->forceFill([
                'status' => AdvisorPlan::STATUS_COMPLETED,
                'completed_at' => now(),
                'period_start' => $input['period']['start'] ?? null,
                'period_end' => $input['period']['end'] ?? null,
                'input_summary' => array_merge($plan->input_summary ?? [], [
                    'bound' => $input['bound'],
                    'binding_reason' => $input['binding_reason'],
                    'currency' => $input['currency'] ?? null,
                    'period' => $input['period'] ?? null,
                    'account' => isset($input['account']) ? array_intersect_key($input['account'], array_flip(['cost', 'clicks', 'impressions', 'conversions', 'cpa', 'auto_tagging_enabled', 'name', 'reach', 'frequency'])) : null,
                    'sources' => $input['bound'] ? $channel->sources($input) : null,
                    'rules' => $result['summary'],
                    'silenced' => $result['silenced'],
                ]),
                'result_summary' => $written,
                'summary_text' => $summary,
            ])->save();

            if ($activity !== null) {
                $this->async->markFinished($activity, 'completed', 'Tamamlandı', ['result_summary' => $summary, 'advisor_plan_id' => $plan->id]);
            }
        } catch (Throwable $exception) {
            $this->markFailed($plan, $exception);

            throw $exception;
        }

        return $plan->fresh() ?? $plan;
    }

    public function markFailed(AdvisorPlan|int $plan, Throwable $exception): void
    {
        $plan = $plan instanceof AdvisorPlan ? $plan : AdvisorPlan::query()->find($plan);
        if ($plan === null || $plan->status === AdvisorPlan::STATUS_FAILED) {
            return;
        }
        $plan->forceFill(['status' => AdvisorPlan::STATUS_FAILED, 'failed_at' => now(), 'error_summary' => mb_substr($exception->getMessage(), 0, 500)])->save();
        $activity = $this->activity($plan);
        if ($activity !== null && ! in_array($activity->status, ['completed', 'partial', 'failed'], true)) {
            $this->async->markFailed($activity, $exception);
        }
    }

    /** @param array{items: list<array<string, mixed>>, summary: array<string, mixed>} $result */
    private function summaryText(array $result, array $input, AdvisorChannel $channel): string
    {
        $reason = $result['summary']['reason'] ?? null;
        if ($reason === 'not_bound') {
            return $channel->label().' hesabı bağlı değil.';
        }
        if ($reason === 'no_campaign_data') {
            return 'Son 30 günde kampanya verisi yok; önce veri toplanmalı.';
        }
        if ($reason === 'low_spend') {
            return 'Son 30 günde harcama çok düşük; anlamlı öneri yok.';
        }
        $count = count($result['items']);
        if ($count === 0) {
            return 'Bu hafta önemli bir iş yok.';
        }
        $waste = (float) ($result['summary']['waste'] ?? 0);

        return $count.' öneri'.($waste > 0 ? sprintf(' · %s israf tespit edildi', ($input['currency'] ?? '') === 'TRY' ? '₺'.number_format($waste, 0, ',', '.') : number_format($waste, 0, ',', '.').' '.($input['currency'] ?? '')) : '');
    }

    private function activity(AdvisorPlan $plan): ?Run
    {
        $id = data_get($plan->input_summary, 'activity_run_id');

        return is_numeric($id) ? Run::query()->find((int) $id) : null;
    }
}
