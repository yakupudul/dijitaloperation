<?php

namespace App\Services\WhatsApp;

use App\Ai\Agents\WhatsAppReplyAgent;
use App\Models\CoreIntegration;
use App\Models\WhatsAppConversation;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class WhatsAppSuggestions
{
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
        $settings = hash('sha256', (string) data_get($integration->config, 'business_context', ''));
        $claimed = WhatsAppConversation::query()->whereKey($conversationId)->where('revision', $revision)
            ->whereIn('suggestion_status', ['pending', 'requested'])
            ->update(['suggestion_status' => 'running', 'error_code' => null, 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        try {
            $route = app(AiRouteResolver::class)->resolve(WhatsAppReplyAgent::ROUTE);
            if ($route->isEmpty()) {
                $this->fail($conversationId, $revision, 'ai_not_configured');
                return;
            }
            app(AiProviderRuntimeConfig::class)->prepare(array_keys($route->providerModels));
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
                    'contact_name' => $conversation->contact_name,
                    'messages' => array_reverse($context),
                    'context_truncated' => $truncated,
                    'history_coverage' => 'Only received provider events. Complete history not verified.',
                    'outgoing_echo_observed' => filled(data_get($integration->config, 'echo_seen_at')),
                    'message_delivery_status' => 'Not verified. An outgoing event does not prove delivery or reading.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
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
            DB::transaction(function () use ($integration, $conversationId, $revision, $settings, $result, $route, $context, $truncated): void {
                $currentIntegration = CoreIntegration::query()->lockForUpdate()->find($integration->id);
                $current = WhatsAppConversation::query()->lockForUpdate()->find($conversationId);
                if (! $current || $current->revision !== $revision || $current->suggestion_status !== 'running') {
                    return;
                }
                if (! $currentIntegration?->isActive() || $settings !== hash('sha256', (string) data_get($currentIntegration->config, 'business_context', ''))) {
                    $current->update(['suggestion_status' => 'pending']);
                    return;
                }
                $current->update([
                    'suggestion_status' => 'ready', 'suggested_revision' => $revision,
                    'suggestion_action' => $result['action'],
                    'suggestion' => $result['action'] === 'wait' ? '' : trim($result['reply']),
                    'rationale' => $result['rationale'], 'summary' => $result['summary'],
                    'context_message_count' => count($context), 'context_truncated' => $truncated,
                    'agent_version' => WhatsAppReplyAgent::VERSION, 'route_signature' => $route->signature,
                    'settings_fingerprint' => $settings, 'suggested_at' => now(), 'error_code' => null,
                ]);
            });
        } catch (Throwable $exception) {
            // Provider exceptions can contain prompts/tokens; persist only a bounded error code.
            $this->fail($conversationId, $revision, 'ai_request_failed');
        }
    }

    private function fail(int $id, int $revision, string $code): void
    {
        WhatsAppConversation::query()->whereKey($id)->where('revision', $revision)->where('suggestion_status', 'running')
            ->update(['suggestion_status' => 'failed', 'error_code' => $code, 'updated_at' => now()]);
    }
}
