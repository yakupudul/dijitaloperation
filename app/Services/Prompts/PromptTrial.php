<?php

namespace App\Services\Prompts;

use App\Ai\Contracts\RegistryPrompted;
use App\Jobs\Prompts\RunPromptTrialJob;
use App\Models\User;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiPricing;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Ai\AiUsageRecorder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

/**
 * "Örnekte dene" (Ayarlar › AI işlemleri, admin only): runs the editor's DRAFT template (not published) once through the
 * operation's agent — same output schema, the draft's model or the route — on a real past input (kept 90 days on the
 * usage record) or a pasted sample. Shows the output, duration and cost; nothing is written to suggestions and the run
 * is not counted as a run of the operation (its cost is, as "prompt_trial").
 */
final class PromptTrial
{
    public function __construct(
        private readonly PromptRegistry $registry,
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly AiPricing $pricing,
    ) {}

    /** @return list<array{id: int, at: string, version: ?int, chars: int}> recent real inputs of the operation, newest first */
    public function samples(string $operation, int $limit = 5): array
    {
        if (! Schema::hasColumn('ai_usage_records', 'input_text')) {
            return [];
        }

        return DB::table('ai_usage_records as r')->leftJoin('prompt_versions as v', 'v.id', '=', 'r.prompt_version_id')
            ->where('v.operation', $operation)->whereNotNull('r.input_text')
            ->orderByDesc('r.created_at')->orderByDesc('r.id')->limit($limit)
            ->get(['r.id', 'r.created_at', 'v.version', DB::raw('length(r.input_text) as chars')])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'at' => (string) $row->created_at, 'version' => $row->version !== null ? (int) $row->version : null, 'chars' => (int) $row->chars])
            ->all();
    }

    public function sample(string $operation, int $id): ?string
    {
        $text = DB::table('ai_usage_records as r')->join('prompt_versions as v', 'v.id', '=', 'r.prompt_version_id')
            ->where('v.operation', $operation)->where('r.id', $id)->value('r.input_text');

        return is_string($text) ? $text : null;
    }

    /** Validates the draft and queues the trial; the screen polls state(). */
    public function queue(string $operation, string $template, string $model, string $input, User $user): void
    {
        $this->registry->draft($operation, ['template' => $template, 'model' => $model]);
        if (trim($input) === '') {
            throw new InvalidArgumentException('Denenecek bir girdi seçin ya da yapıştırın.');
        }
        $agent = $this->registry->definition($operation)['agent'];
        if ($agent === null || ! is_subclass_of($agent, RegistryPrompted::class)) {
            throw new InvalidArgumentException('Bu işlem örnekte denenemez.');
        }
        Cache::put(self::stateKey((int) $user->id, $operation), ['status' => 'running'], now()->addMinutes(15));
        RunPromptTrialJob::dispatch($operation, $template, $model, $input, (int) $user->id);
    }

    /** Executed by the job; the result is kept for the screen. */
    public function run(string $operation, string $template, string $model, string $input, int $userId): void
    {
        $started = hrtime(true);
        try {
            $draft = $this->registry->draft($operation, ['template' => $template, 'model' => $model]);
            $class = (string) $this->registry->definition($operation)['agent'];
            $providers = $this->providers($operation, $draft->model);
            $this->runtime->prepare(array_keys($providers));
            $agent = (new $class)->usePromptVersion($draft);
            Context::addHidden(AiUsageRecorder::TRIAL_CONTEXT, true);
            try {
                $response = $agent->prompt($input, provider: $providers, timeout: 180);
            } finally {
                Context::forgetHidden(AiUsageRecorder::TRIAL_CONTEXT);
            }
            $provider = (string) ($response->meta->provider ?? '');
            $usedModel = (string) ($response->meta->model ?? '');
            $usage = $response->usage;
            $output = $response instanceof StructuredAgentResponse
                ? (string) json_encode($response->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) $response->text;
            Cache::put(self::stateKey($userId, $operation), [
                'status' => 'ready', 'output' => mb_substr($output, 0, 60000), 'model' => trim($provider.':'.$usedModel, ':'),
                'duration_ms' => (int) round((hrtime(true) - $started) / 1e6),
                'cost' => $this->pricing->cost($provider, $usedModel, $usage->promptTokens, $usage->completionTokens, $usage->cacheReadInputTokens, $usage->cacheWriteInputTokens),
            ], now()->addDay());
        } catch (Throwable $exception) {
            Cache::put(self::stateKey($userId, $operation), ['status' => 'failed', 'message' => mb_substr($exception->getMessage(), 0, 300),
                'duration_ms' => (int) round((hrtime(true) - $started) / 1e6)], now()->addDay());
        }
    }

    /** @return array<string, mixed>|null */
    public function state(int $userId, string $operation): ?array
    {
        $state = Cache::get(self::stateKey($userId, $operation));

        return is_array($state) ? $state : null;
    }

    public static function stateKey(int $userId, string $operation): string
    {
        return 'prompt-trial:'.$userId.':'.$operation;
    }

    /** @return array<string, string> provider => model: the draft's model when it pins one, else the operation's route */
    private function providers(string $operation, ?string $model): array
    {
        if ($model !== null && str_contains($model, ':')) {
            [$provider, $name] = explode(':', $model, 2);
            if (! $this->routes->providerReady($provider)) {
                throw new RuntimeException('Seçilen modelin sağlayıcısı bağlı değil.');
            }
            if (! app(AiBudget::class)->allows($provider, $name)) {
                throw new RuntimeException('Aylık AI bütçesi doldu.');
            }

            return [$provider => $name];
        }
        $route = $this->routes->resolve($operation);
        if ($route->isEmpty()) {
            throw new RuntimeException('Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu.');
        }

        return $route->providerModels;
    }
}
