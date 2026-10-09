<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\SiteAgent;
use App\Services\Ai\AiCancellation;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\AiTasks\AiTaskQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One structured call of a website-screen AI operation: route (budget, providers) → DATA_JSON → structured output.
 * An operation delegated to Claude (MCP) waits in the AI iş kuyruğu instead (status queued; the job runs again with
 * the answer); called inline (outside such a job) it fails rather than use the provider. Never throws; the caller validates the output against its own data pack before storing anything.
 */
final class SiteAi
{
    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly AiTaskQueue $tasks,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  string|null  $slot  the call's stable name in a delegated run (AiTaskQueue::answer): found again although the pack moved
     * @return array{status: string, data: array<string, mixed>, prompt_version_id: ?int, message?: string} status: ready | queued | no_provider | error;
     *                                                                                                      message says why a call failed
     */
    public function run(SiteAgent $agent, array $data, int $timeout = 180, ?string $slot = null): array
    {
        AiCancellation::throwIfRequested();
        try {
            if ($this->tasks->delegated($agent->promptOperation()) && ($answer = $this->tasks->answer($agent, $data, $slot)) !== null) {
                return $answer;
            }
            if ($this->tasks->blocksInline($agent->promptOperation())) {
                return ['status' => 'error', 'data' => [], 'prompt_version_id' => null];
            }
            $route = $this->routes->resolve($agent->promptOperation());
            if ($route->isEmpty()) {
                return $this->claudeTakesOver($agent, $data, $slot)
                    ?? ['status' => 'no_provider', 'data' => [], 'prompt_version_id' => null, 'message' => 'Bu işlem için kullanılabilir AI sağlayıcısı yok (bütçe ya da bağlantı).'];
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $output = $agent->prompt(
                "DATA_JSON\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
                timeout: $timeout,
            )->toArray();

            return ['status' => 'ready', 'data' => is_array($output) ? $output : [], 'prompt_version_id' => $agent->promptVersionId()];
        } catch (Throwable $exception) {
            Log::warning('Site AI operation failed.', ['operation' => $agent->promptOperation(), 'error' => $exception->getMessage()]);

            return $this->claudeTakesOver($agent, $data, $slot)
                ?? ['status' => 'error', 'data' => [], 'prompt_version_id' => null, 'message' => self::reason($exception)];
        }
    }

    /**
     * The provider route failed or has nothing left (credit, budget, a provider error): an operation the Claude (MCP)
     * queue can take waits there instead of failing (yakup, 2026-10-09: the idea pool must not stay empty because one
     * provider is down). Null when the queue cannot take it (not configured, not a resumable run, not supported).
     *
     * @param  array<string, mixed>  $data
     * @return array{status: string, data: array<string, mixed>, prompt_version_id: ?int, message: string}|null
     */
    private function claudeTakesOver(SiteAgent $agent, array $data, ?string $slot): ?array
    {
        if (! AiTaskQueue::enabled() || ! $this->tasks->supports($agent->promptOperation())) {
            return null;
        }
        try {
            $answer = $this->tasks->answer($agent, $data, $slot);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return $answer === null ? null : $answer + ['message' => 'AI sağlayıcısı yanıt vermedi; iş Claude kuyruğuna verildi.'];
    }

    /** A short Turkish reason of a failed provider call (credit, limit, time, the provider's own message). */
    private static function reason(Throwable $exception): string
    {
        $message = trim(preg_replace('/\s+/u', ' ', $exception->getMessage()) ?? '');
        $lower = mb_strtolower($message);

        return match (true) {
            str_contains($lower, 'credit balance') || str_contains($lower, 'insufficient_quota') || str_contains($lower, 'billing') => 'AI sağlayıcısının kredisi bitti: '.mb_substr($message, 0, 160),
            str_contains($lower, 'rate limit') || str_contains($lower, '429') || str_contains($lower, 'overloaded') => 'AI sağlayıcısı yoğun ya da istek sınırı doldu: '.mb_substr($message, 0, 160),
            str_contains($lower, 'timed out') || str_contains($lower, 'timeout') => 'AI yanıtı zaman aşımına uğradı.',
            default => 'AI hata verdi: '.mb_substr($message !== '' ? $message : class_basename($exception), 0, 200),
        };
    }
}
