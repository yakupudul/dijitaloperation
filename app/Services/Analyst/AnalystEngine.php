<?php

namespace App\Services\Analyst;

use App\Ai\Agents\Analyst\ChannelAnalystAgent;
use App\Jobs\RunChannelAnalystJob;
use App\Models\AnalystRun;
use App\Models\Brand;
use App\Models\User;
use App\Services\Ai\AiPricing;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Archive\ProductionArchive;
use App\Support\ServiceScope;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs one channel analyst for one brand: pack (rule code) → AI decisions → validation (rule code) → decisions
 * persisted by fingerprint. Weekly for every operational brand (moxdop:analyst:weekly) and on demand
 * ("Yeniden analiz et"); always queued. Brands without data for the channel are skipped with the one-line reason.
 */
final class AnalystEngine
{
    public function __construct(
        private readonly AnalystRegistry $registry,
        private readonly AnalystDecisionStore $store,
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly ProductionArchive $archive,
        private readonly AiPricing $pricing,
    ) {}

    /** Queue a run (one active run per brand × channel). */
    public function queue(Brand $brand, string $channel, ?User $user = null, string $trigger = 'manual', int $delaySeconds = 0): AnalystRun
    {
        app(ServiceScope::class)->ensureBrandServed($brand, 'analyst');
        if (! $this->registry->has($channel)) {
            throw ValidationException::withMessages(['analyst' => 'Bu kanalın analizi henüz hazır değil.']);
        }
        $active = AnalystRun::query()->where('brand_id', $brand->id)->where('channel', $channel)
            ->whereIn('status', [AnalystRun::QUEUED, AnalystRun::RUNNING])->where('created_at', '>=', now()->subMinutes(30))->latest('id')->first();
        if ($active !== null) {
            return $active;
        }
        $run = AnalystRun::query()->create(['brand_id' => $brand->id, 'channel' => $channel, 'status' => AnalystRun::QUEUED, 'trigger' => $trigger, 'requested_by' => $user?->id]);
        $delaySeconds > 0 ? RunChannelAnalystJob::dispatch((int) $run->id)->delay(now()->addSeconds($delaySeconds)) : RunChannelAnalystJob::dispatch((int) $run->id);

        return $run->refresh();
    }

    /**
     * Weekly: every live channel × operational brand, staggered (brands spread over the hour, channels offset) so the
     * heavy queue and the AI provider are not hit at once.
     *
     * @return array{queued: int, channels: list<string>}
     */
    public function queueWeekly(int $staggerSeconds = 90): array
    {
        $queued = 0;
        $channels = $this->registry->liveChannels();
        $brands = Brand::query()->operational()->orderBy('id')->get();
        foreach ($channels as $c => $channel) {
            foreach ($brands as $b => $brand) {
                try {
                    $this->queue($brand, $channel, null, 'weekly', ($b * count($channels) + $c) * $staggerSeconds);
                    $queued++;
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        return ['queued' => $queued, 'channels' => $channels];
    }

    public function run(int $runId): void
    {
        $run = AnalystRun::query()->find($runId);
        if ($run === null || ! in_array($run->status, [AnalystRun::QUEUED, AnalystRun::RUNNING], true)) {
            return;
        }
        $run->forceFill(['status' => AnalystRun::RUNNING, 'started_at' => now()])->save();
        try {
            $brand = Brand::query()->find($run->brand_id);
            if ($brand === null || ! app(ServiceScope::class)->isBrandOperational($brand->id)) {
                $this->finish($run, AnalystRun::SKIPPED, ServiceScope::NOT_SERVED);

                return;
            }
            $analyst = $this->registry->get($run->channel);
            $pack = $analyst->buildPack($brand)->trimTo(AnalystPack::DEFAULT_TOKEN_BUDGET);
            $run->forceFill(['pack_hash' => $pack->hash(), 'pack_tokens' => $pack->tokens(), 'stats' => $pack->stats])->save();
            if (! $pack->hasData()) {
                $this->finish($run, AnalystRun::SKIPPED, $pack->missing);

                return;
            }
            $route = $this->routes->resolve($analyst->routeKey());
            if ($route->isEmpty()) {
                $this->finish($run, AnalystRun::SKIPPED, 'AI kapalı ya da aylık AI bütçesi doldu (Ayarlar → AI).');

                return;
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $agent = new ChannelAnalystAgent($analyst->routeKey(), $analyst->instructions(), array_keys($analyst->allowedActions()));
            $response = $agent->prompt("INPUT_JSON\n".$pack->json(), provider: $route->providerModels, timeout: 300);
            $answer = (array) $response->toArray();
            $raw = array_values(array_filter((array) ($answer['decisions'] ?? []), 'is_array'));
            $result = $analyst->validate(array_slice($raw, 0, 20), $pack);
            foreach ($result['dropped'] as $drop) {
                Log::info('analyst.decision_dropped', ['brand_id' => $brand->id, 'channel' => $run->channel, 'run_id' => $run->id] + $drop);
            }
            $this->store->persist($run, $result['kept'], $pack);

            $usage = $response->usage;
            $provider = (string) ($response->meta->provider ?? $route->primaryProvider() ?? '');
            $model = (string) ($response->meta->model ?? $route->primaryModel() ?? '');
            $run->forceFill([
                'decisions_received' => count($raw), 'decisions_kept' => count($result['kept']), 'dropped' => $result['dropped'],
                'input_tokens' => $usage->promptTokens, 'output_tokens' => $usage->completionTokens,
                'cost_usd' => $this->pricing->cost($provider, $model, $usage->promptTokens, $usage->completionTokens, $usage->cacheReadInputTokens, $usage->cacheWriteInputTokens),
                'provider' => mb_substr($provider, 0, 48) ?: null, 'model' => mb_substr($model, 0, 190) ?: null,
            ])->save();
            $this->archive->record($analyst->routeKey(), $brand, [
                'decisions' => $raw, 'kept' => array_column($result['kept'], 'key'), 'dropped' => $result['dropped'],
                'pack_hash' => $pack->hash(), 'prompt_version' => ChannelAnalystAgent::PROMPT_VERSION,
            ], ['brand_id' => $brand->id, 'title' => $this->registry->label($run->channel).' analizi · '.count($result['kept']).' karar', 'provider' => $provider ?: null, 'model' => $model ?: null]);
            $this->finish($run, AnalystRun::DONE);
        } catch (Throwable $exception) {
            report($exception);
            $this->finish($run, AnalystRun::FAILED, mb_substr($exception->getMessage(), 0, 500));
        }
    }

    /** The latest finished run of the brand × channel (for the "son analiz" line). */
    public function latest(int $brandId, string $channel): ?AnalystRun
    {
        return AnalystRun::query()->where('brand_id', $brandId)->where('channel', $channel)->latest('id')->first();
    }

    private function finish(AnalystRun $run, string $status, ?string $error = null): void
    {
        $run->forceFill(['status' => $status, 'error' => $error, 'finished_at' => now()])->save();
    }
}
