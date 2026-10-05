<?php

namespace App\Services\WhatsApp;

use App\Ai\Agents\WhatsAppReplyAgent;
use App\Models\CoreIntegration;
use App\Models\WhatsAppConversation;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\WhatsApp\Backup\WhatsAppBackupImporter;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiProviderOptions;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Reply suggestions for one WhatsApp conversation, drafted on the spot through the OpenAI API (never the Claude MCP
 * queue: a WhatsApp reply is needed within minutes). The model is chosen on the WhatsApp screen and stored on the
 * integration config; MoxDOP never sends the reply.
 */
final class WhatsAppSuggestions
{
    /** Operator-facing text of a stored error code. */
    public const ERROR_LABELS = [
        'ai_not_configured' => 'OpenAI bağlantısı yok ya da kapalı (Entegrasyonlar › OpenAI).',
        'ai_budget_blocked' => 'Günlük AI tavanı doldu ya da otomatik öneri kapalı; düğmeyle yeniden isteyin veya tavanı Ayarlar › AI işlemleri\'nden artırın.',
        'ai_request_failed' => 'OpenAI isteği başarısız oldu; yeniden deneyin.',
        'invalid_ai_output' => 'AI geçersiz bir öneri döndürdü; yeniden deneyin.',
        'no_messages' => 'Görüşmede okunacak mesaj yok.',
        'worker_interrupted' => 'Öneri işi yarıda kaldı; yeniden deneyin.',
    ];

    /** Automatic suggestions are on (WhatsApp screen): the daily AI cap still applies, the "click only" rule does not. */
    public static function automaticEnabled(): bool
    {
        return (bool) data_get(app(WhatsAppConnection::class)->integration()?->config, 'automatic_suggestions', false);
    }

    /** The OpenAI model chosen on the WhatsApp screen, else the default OpenAI model. */
    public static function model(?CoreIntegration $integration): string
    {
        $model = trim((string) data_get($integration?->config, 'ai_model', ''));

        return $model !== '' ? $model : AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI);
    }

    /**
     * OpenAI chat models with a known price (moxdop-ai-pricing) plus the default; a stored model stays listed.
     *
     * @return array<string, string> model => label
     */
    public static function modelOptions(?string $current = null): array
    {
        $models = array_keys((array) config('moxdop-ai-pricing.models.'.AiProviderCatalog::OPENAI, []));
        $models[] = AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI);
        if (filled($current)) {
            $models[] = (string) $current;
        }
        $options = [];
        foreach (array_unique($models) as $model) {
            $model = (string) $model;
            if ($model === '*' || str_starts_with($model, 'text-embedding')) {
                continue;
            }
            $label = AiProviderCatalog::humanModelLabel($model);
            $options[$model] = $label !== $model ? $label.' ('.$model.')' : $model;
        }

        return $options;
    }

    public function generate(int $conversationId): void
    {
        $conversation = WhatsAppConversation::query()->find($conversationId);
        $integration = $conversation ? CoreIntegration::query()->find($conversation->integration_id) : null;
        if (! $conversation || ! $integration?->isActive() || ! in_array($conversation->suggestion_status, ['pending', 'requested'], true)) {
            return;
        }
        if ($conversation->suggestion_status === 'pending' && ! data_get($integration->config, 'automatic_suggestions', false)) {
            return;
        }
        $revision = $conversation->revision;
        $settings = self::fingerprint($integration);
        $claimed = WhatsAppConversation::query()->whereKey($conversationId)->where('revision', $revision)
            ->whereIn('suggestion_status', ['pending', 'requested'])
            ->update(['suggestion_status' => 'running', 'error_code' => null, 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        try {
            $route = app(AiRouteResolver::class)->resolve(WhatsAppReplyAgent::ROUTE);
            // OpenAI only: no key, the daily AI cap or a disabled OpenAI integration means no suggestion.
            if (! isset($route->providerModels[AiProviderCatalog::OPENAI])) {
                $reason = collect($route->steps)->firstWhere('provider', AiProviderCatalog::OPENAI)['reason'] ?? null;
                $this->fail($conversationId, $revision, $reason === 'budget_exhausted' ? 'ai_budget_blocked' : 'ai_not_configured');

                return;
            }
            $model = self::model($integration);
            Context::addHidden(AiProviderOptions::OPENAI_MODEL_CONTEXT, $model);
            app(AiProviderRuntimeConfig::class)->prepare([AiProviderCatalog::OPENAI]);
            $rows = $conversation->messages()->orderByDesc('sent_at')->orderByDesc('id')->limit(201)->get();
            $truncated = $rows->count() > 200;
            $rows = $rows->take(200);
            $context = [];
            $characters = 0;
            foreach ($rows as $row) {
                $body = (string) $row->body;
                $remaining = 60000 - $characters;
                if ($remaining <= 0) {
                    $truncated = true;
                    break;
                }
                if (mb_strlen($body) > $remaining) {
                    $body = mb_substr($body, 0, $remaining).' [mesajın devamı bağlam sınırı nedeniyle alınmadı]';
                    $truncated = true;
                }
                $characters += mb_strlen($body);
                $context[] = [
                    'id' => $row->message_id, 'direction' => $row->direction,
                    'time' => $row->sent_at->toIso8601String(), 'type' => $row->message_type,
                    'text' => $body, 'reply_to' => $row->reply_to_message_id,
                ];
            }
            if ($context === []) {
                $this->fail($conversationId, $revision, 'no_messages');

                return;
            }
            $response = (new WhatsAppReplyAgent)->prompt(
                json_encode([
                    'current_time' => now('Europe/Istanbul')->toIso8601String(),
                    'business_context' => data_get($integration->config, 'business_context', ''),
                    'learned_profile' => WhatsAppBrain::forPrompt($integration),
                    'contact_name' => $conversation->contact_name,
                    'messages' => array_reverse($context),
                    'context_truncated' => $truncated,
                    'history_coverage' => $conversation->phone_number_id === WhatsAppBackupImporter::LINE
                        ? 'Imported from the business phone\'s WhatsApp backup taken around '.data_get($integration->config, 'backup_snapshot_at', 'an unknown time').'. Messages after the backup are not included; the operator may already have replied on the phone.'
                        : 'Only received provider events. Complete history not verified.',
                    'outgoing_echo_observed' => filled(data_get($integration->config, 'echo_seen_at')),
                    'message_delivery_status' => 'Not verified. An outgoing event does not prove delivery or reading.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: [AiProviderCatalog::OPENAI => $model],
            );
            $result = Validator::make($response->toArray(), [
                'action' => ['required', 'in:reply,wait,clarify'],
                'reply' => ['present', 'string', 'max:4000'],
                'rationale' => ['required', 'string', 'max:2000'],
                'summary' => ['required', 'string', 'max:2000'],
            ])->validate();
            if ($result['action'] === 'reply' && trim($result['reply']) === '') {
                $this->fail($conversationId, $revision, 'invalid_ai_output');

                return;
            }
            DB::transaction(function () use ($integration, $conversationId, $revision, $settings, $result, $model, $context, $truncated): void {
                $currentIntegration = CoreIntegration::query()->lockForUpdate()->find($integration->id);
                $current = WhatsAppConversation::query()->lockForUpdate()->find($conversationId);
                if (! $current || $current->revision !== $revision || $current->suggestion_status !== 'running') {
                    return;
                }
                if (! $currentIntegration?->isActive() || $settings !== self::fingerprint($currentIntegration)) {
                    $current->update(['suggestion_status' => 'pending']);

                    return;
                }
                $current->update([
                    'suggestion_status' => 'ready', 'suggested_revision' => $revision,
                    'suggestion_action' => $result['action'],
                    'suggestion' => $result['action'] === 'wait' ? '' : trim($result['reply']),
                    'rationale' => $result['rationale'], 'summary' => $result['summary'],
                    'context_message_count' => count($context), 'context_truncated' => $truncated,
                    'agent_version' => WhatsAppReplyAgent::VERSION, 'route_signature' => AiProviderCatalog::OPENAI.':'.$model,
                    'settings_fingerprint' => $settings, 'suggested_at' => now(), 'error_code' => null,
                ]);
            });
        } catch (Throwable $exception) {
            // Provider exceptions can contain prompts/tokens; persist only a bounded error code.
            $this->fail($conversationId, $revision, 'ai_request_failed');
        }
    }

    /** A draft is stale when the operator's instructions or the learned brain changed after it was written. */
    public static function fingerprint(?CoreIntegration $integration): string
    {
        return hash('sha256', (string) data_get($integration?->config, 'business_context', '').'|'.(string) data_get($integration?->config, 'brain.learned_at', ''));
    }

    private function fail(int $id, int $revision, string $code): void
    {
        WhatsAppConversation::query()->whereKey($id)->where('revision', $revision)->where('suggestion_status', 'running')
            ->update(['suggestion_status' => 'failed', 'error_code' => $code, 'updated_at' => now()]);
    }
}
