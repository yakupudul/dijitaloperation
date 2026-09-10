<?php

namespace App\Services\WhatsApp;

use App\Models\CoreIntegration;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppWebhookReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class WhatsAppIngestion
{
    public function process(int $receiptId): void
    {
        DB::transaction(function () use ($receiptId): void {
            $receipt = WhatsAppWebhookReceipt::query()->lockForUpdate()->find($receiptId);
            if ($receipt === null || $receipt->status !== 'pending') {
                return;
            }
            $integration = CoreIntegration::query()->lockForUpdate()->find($receipt->integration_id);
            if (! $integration?->isActive()) {
                return;
            }
            $config = $integration->config ?? [];
            $accepted = 0;
            $ignored = 0;
            $historySeen = false;
            $echoSeen = false;
            $historyError = false;
            foreach (($receipt->payload['entry'] ?? []) as $entry) {
                if (! is_array($entry) || (string) ($entry['id'] ?? '') !== (string) ($config['waba_id'] ?? '')) {
                    $ignored++;
                    continue;
                }
                foreach (($entry['changes'] ?? []) as $change) {
                    $value = $change['value'] ?? [];
                    if (! is_array($value) || ($value['messaging_product'] ?? '') !== 'whatsapp'
                        || (string) data_get($value, 'metadata.phone_number_id', '') !== (string) ($config['phone_number_id'] ?? '')) {
                        $ignored++;
                        continue;
                    }
                    $names = [];
                    foreach (($value['contacts'] ?? []) as $contact) {
                        if (is_array($contact) && is_string($contact['wa_id'] ?? null)) {
                            $name = data_get($contact, 'profile.name');
                            $names[$contact['wa_id']] = is_string($name) ? mb_substr($name, 0, 255) : null;
                        }
                    }
                    $field = $change['field'] ?? '';
                    if ($field === 'messages' || $field === 'smb_message_echoes') {
                        $echo = $field === 'smb_message_echoes';
                        $echoSeen = $echoSeen || $echo;
                        foreach (($value[$echo ? 'message_echoes' : 'messages'] ?? []) as $message) {
                            $result = $this->message($integration, $config, $message, $names, $echo, false);
                            $accepted += $result === 1 ? 1 : 0;
                            $ignored += $result === -1 ? 1 : 0;
                        }
                    } elseif ($field === 'history') {
                        $historySeen = true;
                        foreach (($value['history'] ?? []) as $chunk) {
                            if (! is_array($chunk)) {
                                $ignored++;
                                continue;
                            }
                            $historyError = $historyError || ! empty($chunk['errors']);
                            foreach (($chunk['threads'] ?? []) as $thread) {
                                $contactId = $thread['id'] ?? '';
                                foreach (($thread['messages'] ?? []) as $message) {
                                    if (! is_array($message)) {
                                        $ignored++;
                                        continue;
                                    }
                                    $from = (string) ($message['from'] ?? '');
                                    $outgoing = $from === (string) ($config['business_phone'] ?? '');
                                    if (! $outgoing && $from !== (string) $contactId) {
                                        $ignored++;
                                        continue;
                                    }
                                    if ($outgoing && (string) ($message['to'] ?? '') !== (string) $contactId) {
                                        $ignored++;
                                        continue;
                                    }
                                    $result = $this->message($integration, $config, $message, $names, $outgoing, true);
                                    $accepted += $result === 1 ? 1 : 0;
                                    $ignored += $result === -1 ? 1 : 0;
                                }
                            }
                        }
                    } elseif ($field !== 'smb_app_state_sync') {
                        $ignored++;
                    }
                }
            }
            if ($historySeen) {
                $config['history_state'] = $historyError ? 'provider_error' : 'received_partial';
            }
            if ($echoSeen) {
                $config['echo_seen_at'] = now()->toIso8601String();
            }
            $config['last_receipt_at'] = now()->toIso8601String();
            $integration->update(['config' => $config]);
            $receipt->update([
                'status' => 'completed', 'accepted_count' => $accepted, 'ignored_count' => $ignored,
                'payload' => null, 'processed_at' => now(), 'error_code' => null,
            ]);
        }, 3);
    }

    /** Returns 1 for a new message, 0 for redelivery, -1 for unsupported/invalid envelope. */
    private function message(CoreIntegration $integration, array $config, mixed $message, array $names, bool $outgoing, bool $history): int
    {
        if (! is_array($message)) {
            return -1;
        }
        $id = $message['id'] ?? null;
        $contact = $message[$outgoing ? 'to' : 'from'] ?? null;
        $timestamp = $message['timestamp'] ?? null;
        $type = $message['type'] ?? null;
        if (! is_string($id) || $id === '' || strlen($id) > 255 || ! is_string($contact)
            || ! preg_match('/^[0-9]{7,20}$/', $contact) || ! is_scalar($timestamp)
            || ! ctype_digit((string) $timestamp) || (int) $timestamp < 946684800
            || (int) $timestamp > now()->addDay()->timestamp || ! is_string($type) || strlen($type) > 40) {
            return -1;
        }
        if ($outgoing && (string) ($message['from'] ?? '') !== (string) ($config['business_phone'] ?? '')) {
            return -1;
        }
        $body = match ($type) {
            'text' => data_get($message, 'text.body'),
            'button' => data_get($message, 'button.text'),
            'interactive' => data_get($message, 'interactive.button_reply.title', data_get($message, 'interactive.list_reply.title')),
            'image', 'video', 'document' => '['.$type.' — ekin içeriği okunmadı] '.(string) data_get($message, $type.'.caption', ''),
            default => '['.$type.' — metin olarak çözümlenmedi]',
        };
        if (! is_string($body) || $body === '') {
            return -1;
        }
        $conversation = WhatsAppConversation::query()->firstOrCreate([
            'integration_id' => $integration->id, 'phone_number_id' => $config['phone_number_id'], 'contact_id' => $contact,
        ], ['contact_name' => $names[$contact] ?? null]);
        $conversation = WhatsAppConversation::query()->lockForUpdate()->findOrFail($conversation->id);
        $sentAt = CarbonImmutable::createFromTimestampUTC((int) $timestamp);
        $replyId = data_get($message, 'context.id');
        $stored = $conversation->messages()->firstOrCreate(['message_id' => $id], [
            'direction' => $outgoing ? 'outgoing' : 'incoming', 'message_type' => $type,
            'body' => $body, 'sent_at' => $sentAt, 'is_history' => $history,
            'reply_to_message_id' => is_string($replyId) ? substr($replyId, 0, 255) : null,
        ]);
        if (! $stored->wasRecentlyCreated) {
            return 0;
        }
        $conversation->update([
            'contact_name' => $names[$contact] ?? $conversation->contact_name,
            'last_message_at' => $conversation->last_message_at?->greaterThan($sentAt) ? $conversation->last_message_at : $sentAt,
            'revision' => $conversation->revision + 1, 'suggestion_status' => 'pending', 'error_code' => null,
        ]);

        return 1;
    }
}
