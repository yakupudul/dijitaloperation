<?php

namespace App\Services\WhatsApp;

use App\Ai\Agents\WhatsAppBrainAgent;
use App\Jobs\WhatsApp\LearnWhatsAppBrain;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiProviderOptions;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * What the business has learned from its own WhatsApp chats (integration config `brain`): services, quoted prices,
 * frequent questions, tone, rules and the questions the operator should answer. Learned once after the first backup
 * and again on the operator's click; reply drafts read it next to the operator's instructions (business_context),
 * which always win.
 */
final class WhatsAppBrain
{
    /** Conversations read per learning, most recent first, and messages per conversation. */
    private const CONVERSATIONS = 80;

    private const MESSAGES = 30;

    private const CHARACTERS = 150000;

    public const ERROR_LABELS = [
        'ai_not_configured' => 'OpenAI bağlantısı yok ya da kapalı (Entegrasyonlar › OpenAI).',
        'ai_budget_blocked' => 'Günlük AI tavanı doldu; yarın ya da tavanı artırınca "Yeniden öğren"e basın.',
        'ai_request_failed' => 'OpenAI isteği başarısız oldu; "Yeniden öğren"e basın.',
        'invalid_ai_output' => 'AI geçersiz bir sonuç döndürdü; "Yeniden öğren"e basın.',
        'no_conversations' => 'Öğrenecek görüşme yok: önce yedeği yükleyip çıkarın.',
        'worker_interrupted' => 'Öğrenme işi yarıda kaldı; "Yeniden öğren"e basın.',
    ];

    public function __construct(private WhatsAppConnection $connection) {}

    /** The operator's "Yeniden öğren". */
    public function request(User $user): void
    {
        $this->connection->authorize($user);
        $integration = $this->connection->integration();
        if ($integration === null || ! WhatsAppConversation::query()->where('integration_id', $integration->id)->exists()) {
            throw ValidationException::withMessages(['brain' => self::ERROR_LABELS['no_conversations']]);
        }
        if (in_array(data_get($integration->config, 'brain_status'), ['queued', 'running'], true)) {
            throw ValidationException::withMessages(['brain' => 'Beyin şu an öğreniyor; bitince sonucu burada görürsünüz.']);
        }
        $this->queue($user->id);
    }

    /** After a backup is extracted: the first time there is nothing learned yet, learning starts by itself. */
    public function learnAfterFirstImport(?int $userId): void
    {
        $config = $this->connection->integration()?->config ?? [];
        if (empty($config['brain']) && ! in_array($config['brain_status'] ?? null, ['queued', 'running'], true)) {
            $this->queue($userId);
        }
    }

    public function learn(): void
    {
        $integration = $this->connection->integration();
        if ($integration === null || ! $this->setStatus(['queued', 'failed', 'ready', null], 'running')) {
            return;
        }
        try {
            $route = app(AiRouteResolver::class)->resolve(WhatsAppBrainAgent::ROUTE);
            if (! isset($route->providerModels[AiProviderCatalog::OPENAI])) {
                $reason = collect($route->steps)->firstWhere('provider', AiProviderCatalog::OPENAI)['reason'] ?? null;
                $this->markFailed($reason === 'budget_exhausted' ? 'ai_budget_blocked' : 'ai_not_configured');

                return;
            }
            [$conversations, $messageCount] = $this->sample($integration);
            if ($conversations === []) {
                $this->markFailed('no_conversations');

                return;
            }
            $model = WhatsAppSuggestions::model($integration);
            Context::addHidden(AiProviderOptions::OPENAI_MODEL_CONTEXT, $model);
            app(AiProviderRuntimeConfig::class)->prepare([AiProviderCatalog::OPENAI]);
            $response = (new WhatsAppBrainAgent)->prompt(json_encode([
                'today' => now('Europe/Istanbul')->toDateString(),
                'operator_instructions' => (string) data_get($integration->config, 'business_context', ''),
                'conversations' => $conversations,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), provider: [AiProviderCatalog::OPENAI => $model]);
            $brain = $this->validated($response->toArray());
            if ($brain === null) {
                $this->markFailed('invalid_ai_output');

                return;
            }
            $this->update(function (array $config) use ($brain, $model, $conversations, $messageCount): array {
                $config['brain'] = [...$brain, 'learned_at' => now()->toIso8601String(), 'model' => $model,
                    'version' => WhatsAppBrainAgent::VERSION, 'conversations' => count($conversations), 'messages' => $messageCount];
                $config['brain_status'] = 'ready';
                $config['brain_error'] = null;

                return $config;
            });
        } catch (Throwable $exception) {
            // Provider exceptions can carry prompt text: only a code is stored.
            report($exception);
            $this->markFailed('ai_request_failed');
        }
    }

    public function markFailed(string $code): void
    {
        $this->update(function (array $config) use ($code): array {
            if (($config['brain_status'] ?? null) !== 'running' && $code === 'worker_interrupted') {
                return $config;
            }
            $config['brain_status'] = 'failed';
            $config['brain_error'] = $code;

            return $config;
        });
    }

    /**
     * The part reply drafts read (no bookkeeping fields), or null when nothing is learned.
     *
     * @return array<string, mixed>|null
     */
    public static function forPrompt(?CoreIntegration $integration): ?array
    {
        $brain = data_get($integration?->config, 'brain');
        if (! is_array($brain)) {
            return null;
        }

        return array_intersect_key($brain, array_flip(['summary', 'services', 'prices', 'faq', 'tone', 'policies', 'avoid', 'learned_at']));
    }

    private function queue(?int $userId): void
    {
        if (! $this->setStatus(['failed', 'ready', null], 'queued')) {
            return;
        }
        try {
            LearnWhatsAppBrain::dispatch($userId);
        } catch (Throwable $exception) {
            report($exception);
            $this->markFailedFrom('queued', 'worker_interrupted');
        }
    }

    private function markFailedFrom(string $status, string $code): void
    {
        $this->update(function (array $config) use ($status, $code): array {
            if (($config['brain_status'] ?? null) === $status) {
                $config['brain_status'] = 'failed';
                $config['brain_error'] = $code;
            }

            return $config;
        });
    }

    /** Moves brain_status from one of $from to $to; false when it was in another state. */
    private function setStatus(array $from, string $to): bool
    {
        $changed = false;
        $this->update(function (array $config) use ($from, $to, &$changed): array {
            if (in_array($config['brain_status'] ?? null, $from, true)) {
                $config['brain_status'] = $to;
                $config['brain_error'] = null;
                $changed = true;
            }

            return $config;
        });

        return $changed;
    }

    /** @param  callable(array<string, mixed>): array<string, mixed>  $change */
    private function update(callable $change): void
    {
        DB::transaction(function () use ($change): void {
            $row = CoreIntegration::query()->where('provider', WhatsAppConnection::PROVIDER)->lockForUpdate()->first();
            if ($row !== null) {
                $row->update(['config' => $change($row->config ?? [])]);
            }
        });
    }

    /**
     * Recent conversations where both sides wrote, contacts replaced by a number (no names or phone numbers).
     *
     * @return array{0: list<array{contact: string, messages: list<array{dir: string, date: string, text: string}>}>, 1: int}
     */
    private function sample(CoreIntegration $integration): array
    {
        $conversations = [];
        $characters = 0;
        $count = 0;
        $rows = WhatsAppConversation::query()->where('integration_id', $integration->id)
            ->whereHas('messages', fn ($query) => $query->where('direction', 'outgoing'))
            ->whereHas('messages', fn ($query) => $query->where('direction', 'incoming'))
            ->orderByDesc('last_message_at')->limit(self::CONVERSATIONS)->get(['id']);
        foreach ($rows as $index => $row) {
            $messages = [];
            foreach ($row->messages()->orderByDesc('sent_at')->orderByDesc('id')->limit(self::MESSAGES)->get()->reverse() as $message) {
                $text = mb_substr(trim((string) $message->body), 0, 500);
                if ($text === '' || $characters + mb_strlen($text) > self::CHARACTERS) {
                    continue;
                }
                $characters += mb_strlen($text);
                $messages[] = ['dir' => $message->direction === 'outgoing' ? 'out' : 'in', 'date' => $message->sent_at->toDateString(), 'text' => $text];
            }
            if ($messages !== []) {
                $conversations[] = ['contact' => 'Kişi '.($index + 1), 'messages' => $messages];
                $count += count($messages);
            }
            if ($characters >= self::CHARACTERS) {
                break;
            }
        }

        return [$conversations, $count];
    }

    /** @return array<string, mixed>|null */
    private function validated(array $output): ?array
    {
        $validator = Validator::make($output, [
            'summary' => ['required', 'string', 'max:2000'],
            'services' => ['present', 'array', 'max:40'], 'services.*' => ['string', 'max:300'],
            'prices' => ['present', 'array', 'max:60'],
            'prices.*.item' => ['required', 'string', 'max:300'], 'prices.*.price' => ['required', 'string', 'max:200'],
            'prices.*.last_quoted' => ['required', 'string', 'max:40'],
            'faq' => ['present', 'array', 'max:40'],
            'faq.*.question' => ['required', 'string', 'max:500'], 'faq.*.answer' => ['required', 'string', 'max:1500'],
            'tone' => ['required', 'string', 'max:2000'],
            'policies' => ['present', 'array', 'max:40'], 'policies.*' => ['string', 'max:500'],
            'avoid' => ['present', 'array', 'max:40'], 'avoid.*' => ['string', 'max:500'],
            'open_questions' => ['present', 'array', 'max:30'], 'open_questions.*' => ['string', 'max:500'],
        ]);

        return $validator->fails() ? null : $validator->validated();
    }
}
