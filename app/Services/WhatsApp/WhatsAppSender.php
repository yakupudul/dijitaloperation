<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppConversation;
use App\Services\WhatsApp\Backup\WhatsAppBackupImporter;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * "Gönder" in the inbox (yakup 2026-11-08: "benim onayıma sun"): the operator's click sends one text through the Cloud
 * API from the live number (coexistence: it shows on the phone too). Only inside WhatsApp's 24-hour window after the
 * customer's last message, never to a chat that asked to stop. Meta sends no echo for API messages, so the sent text is
 * stored here as the chat's outgoing message.
 */
final class WhatsAppSender
{
    public function __construct(private WhatsAppConnection $connection, private WhatsAppGraph $graph) {}

    /** Whether a free-form text may go to this chat now (live number, window open, no opt-out). */
    public static function canSend(WhatsAppConversation $conversation): bool
    {
        return $conversation->phone_number_id !== WhatsAppBackupImporter::LINE
            && $conversation->opted_out_at === null
            && ctype_digit((string) $conversation->contact_id)
            && $conversation->last_incoming_at !== null
            && $conversation->last_incoming_at->greaterThan(now()->subHours(24));
    }

    public function sendText(WhatsAppConversation $conversation, string $text): void
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 4096) {
            throw ValidationException::withMessages(['reply' => 'Metin boş olamaz ve 4096 karakteri geçemez.']);
        }
        $integration = $this->connection->integration();
        if ($integration === null || ! $integration->isActive() || (int) $integration->id !== (int) $conversation->integration_id) {
            throw ValidationException::withMessages(['reply' => 'Canlı WhatsApp bağlantısı etkin değil.']);
        }
        if (! self::canSend($conversation)) {
            throw ValidationException::withMessages(['reply' => $conversation->opted_out_at !== null
                ? 'Bu kişi mesaj almak istemediğini yazdı (DUR); gönderilmez.'
                : '24 saat penceresi kapalı; WhatsApp kuralı gereği metni telefondan yazın.']);
        }
        $secrets = $this->connection->secrets($integration);
        $token = (string) ($secrets['access_token'] ?? '');
        $phoneId = (string) data_get($integration->config, 'phone_number_id', '');
        if ($token === '' || ! ctype_digit($phoneId)) {
            throw ValidationException::withMessages(['reply' => 'Numara Meta ile bağlı değil (erişim anahtarı yok).']);
        }
        try {
            $body = $this->graph->request('POST', $phoneId.'/messages', [
                'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => (string) $conversation->contact_id,
                'type' => 'text', 'text' => json_encode(['preview_url' => false, 'body' => $text], JSON_UNESCAPED_UNICODE),
            ], $token, (string) ($secrets['app_secret'] ?? ''));
        } catch (WhatsAppGraphException $exception) {
            throw ValidationException::withMessages(['reply' => 'Meta gönderimi reddetti: '.(string) ($exception->details['message'] ?? $exception->getMessage())]);
        }
        $messageId = (string) data_get($body, 'messages.0.id', '');
        $now = CarbonImmutable::now();
        $conversation->messages()->firstOrCreate(['message_id' => $messageId !== '' ? mb_substr($messageId, 0, 255) : 'moxdop.'.$now->getTimestampMs()], [
            'direction' => 'outgoing', 'message_type' => 'text', 'body' => $text, 'sent_at' => $now, 'is_history' => false,
        ]);
        $conversation->update([
            'last_message_at' => $now, 'done_at' => $now, 'snoozed_until' => null,
            'revision' => $conversation->revision + 1, 'suggestion_status' => 'ready', 'error_code' => null,
        ]);
    }
}
