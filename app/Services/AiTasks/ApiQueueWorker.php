<?php

namespace App\Services\AiTasks;

use App\Models\AiTask;
use App\Services\Ai\AiPricing;
use App\Services\Ai\AiProviderRuntimeConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Claude API / ChatGPT API as AI workers (Ayarlar › AI): when the order puts an API first among the alive workers, the
 * server answers queue tasks itself every few minutes — the same instructions as a system prompt, the same DATA_JSON
 * input, the task's own JSON schema forced as the answer shape — and stores the answer like an MCP worker would, with
 * who answered and what it cost. A key, credit or rate error stops the worker for a while (the next one takes over);
 * an answer that does not fit the schema is asked once more with the errors, then the task goes back to the queue.
 */
final class ApiQueueWorker
{
    /** Tasks one round answers at most (a round runs every five minutes). */
    public const int ROUND = 20;

    /** A task an API could not answer this many times is marked failed (the operator sees why). */
    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly AiWorkers $workers,
        private readonly AiTaskQueue $queue,
        private readonly AiPricing $pricing,
        private readonly AiProviderRuntimeConfig $runtime,
    ) {}

    /** One round: answers up to ROUND tasks when an API is the working worker; returns how many were stored. */
    public function round(): int
    {
        $worker = $this->workers->active();
        if ($worker === null || AiWorkers::isMcp($worker)) {
            return 0;
        }
        $this->workers->markSeen($worker);
        $stored = 0;
        for ($i = 0; $i < self::ROUND; $i++) {
            if ($this->workers->active() !== $worker) {
                break;
            }
            $task = AiWorkers::servable(AiTask::query(), $worker)->orderByRaw(AiTaskPriority::orderSql())->orderBy('id')->first();
            if ($task === null) {
                break;
            }
            $this->queue->claim($task, $worker);
            $result = $this->answer($worker, $task);
            if ($result === 'stop') {
                $this->release($task);
                break;
            }
            $stored += $result === 'stored' ? 1 : 0;
        }

        return $stored;
    }

    /** @return 'stored'|'retry'|'stop' */
    private function answer(string $worker, AiTask $task): string
    {
        $model = $this->workers->settings()['api'][$worker]['model'];
        $feedback = null;
        for ($try = 0; $try < 2; $try++) {
            try {
                [$output, $cost] = $this->call($worker, $model, $task, $feedback);
            } catch (ApiWorkerStop $stop) {
                $this->workers->apiFailed($worker, $stop->getMessage(), $stop->minutes);

                return 'stop';
            } catch (Throwable $exception) {
                Log::warning('AI queue API call failed.', ['worker' => $worker, 'task' => $task->id, 'error' => $exception->getMessage()]);
                $this->workers->apiFailed($worker, 'Bağlantı hatası: '.mb_substr($exception->getMessage(), 0, 160), 10);

                return 'stop';
            }
            $errors = $this->queue->submit($task, $output, $worker.':'.$model, $cost);
            if ($errors === []) {
                return 'stored';
            }
            $feedback = $errors;
            $task->refresh();
        }
        if ($task->attempts >= self::MAX_ATTEMPTS) {
            $this->queue->fail($task, 'API şemaya uyan cevap üretemedi: '.implode(' ', array_slice($feedback ?? [], 0, 3)), $worker.':'.$model);
        } else {
            $this->release($task);
        }

        return 'retry';
    }

    /**
     * @param  list<string>|null  $feedback  schema errors of the previous answer
     * @return array{0: mixed, 1: ?float} the answer object and its cost in USD
     */
    private function call(string $worker, string $model, AiTask $task, ?array $feedback): array
    {
        $input = (string) $task->input;
        if ($feedback !== null) {
            $input .= "\n\nÖnceki cevabın şemaya uymadı, düzeltip yeniden ver:\n- ".implode("\n- ", array_slice($feedback, 0, 10));
        }
        $schema = (array) $task->output_schema;

        return $worker === AiWorkers::CLAUDE_API ? $this->anthropic($model, (string) $task->instructions, $input, $schema)
            : $this->openAi($model, (string) $task->instructions, $input, $schema);
    }

    /** @return array{0: mixed, 1: ?float} */
    private function anthropic(string $model, string $system, string $input, array $schema): array
    {
        if (! $this->runtime->prepareAnthropic()) {
            throw new ApiWorkerStop('Claude API anahtarı girilmemiş.', 360);
        }
        $response = $this->send(fn () => Http::withHeaders([
            'x-api-key' => (string) config('ai.providers.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])->timeout(300)->post(rtrim((string) config('ai.providers.anthropic.url', 'https://api.anthropic.com/v1'), '/').'/messages', [
            'model' => $model,
            'max_tokens' => 16000,
            'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => [['role' => 'user', 'content' => $input]],
            'tools' => [['name' => 'cevap', 'description' => 'Görevin cevabı; şemaya birebir uyan tek JSON nesnesi.', 'input_schema' => $schema]],
            'tool_choice' => ['type' => 'tool', 'name' => 'cevap'],
        ]));
        $output = collect((array) $response->json('content'))->firstWhere('type', 'tool_use')['input'] ?? null;
        $usage = (array) $response->json('usage');

        return [$output, $this->pricing->cost('anthropic', $model, (int) ($usage['input_tokens'] ?? 0), (int) ($usage['output_tokens'] ?? 0),
            (int) ($usage['cache_read_input_tokens'] ?? 0), (int) ($usage['cache_creation_input_tokens'] ?? 0))];
    }

    /** @return array{0: mixed, 1: ?float} */
    private function openAi(string $model, string $system, string $input, array $schema): array
    {
        if (! $this->runtime->prepareOpenAi()) {
            throw new ApiWorkerStop('ChatGPT API anahtarı girilmemiş.', 360);
        }
        $response = $this->send(fn () => Http::withToken((string) config('ai.providers.openai.key'))->timeout(300)
            ->post(rtrim((string) config('ai.providers.openai.url', 'https://api.openai.com/v1'), '/').'/chat/completions', [
                'model' => $model,
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $input]],
                'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'cevap', 'schema' => $schema, 'strict' => false]],
            ]));
        $content = (string) $response->json('choices.0.message.content');
        $usage = (array) $response->json('usage');

        return [json_decode($content, true), $this->pricing->cost('openai', $model, (int) ($usage['prompt_tokens'] ?? 0), (int) ($usage['completion_tokens'] ?? 0),
            (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0))];
    }

    /** The provider's answer; key, credit and rate errors stop the worker (the next one in the order takes over). */
    private function send(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            throw new ApiWorkerStop('Sağlayıcıya ulaşılamadı.', 10, $exception);
        }
        if ($response->successful()) {
            return $response;
        }
        $message = mb_strtolower((string) ($response->json('error.message') ?? $response->body()));
        throw match (true) {
            in_array($response->status(), [401, 403], true) => new ApiWorkerStop('Anahtar geçersiz ya da yetkisiz.', 360),
            $response->status() === 402 || str_contains($message, 'credit') || str_contains($message, 'quota') || str_contains($message, 'billing') => new ApiWorkerStop('Kredi ya da kota bitti.', 360),
            $response->status() === 429 => new ApiWorkerStop('Hız sınırına takıldı.', 15),
            $response->status() >= 500 => new ApiWorkerStop('Sağlayıcı geçici hata verdi ('.$response->status().').', 10),
            default => new ApiWorkerStop('İstek reddedildi ('.$response->status().'): '.mb_substr((string) ($response->json('error.message') ?? ''), 0, 120), 30),
        };
    }

    /** Back to the queue untaken, so any worker can take it. */
    private function release(AiTask $task): void
    {
        AiTask::query()->whereKey($task->id)->where('status', AiTask::CLAIMED)->update(['status' => AiTask::PENDING, 'claimed_at' => null, 'claimed_by' => null]);
    }
}
