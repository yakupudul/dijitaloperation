<?php

namespace App\Services\WhatsApp;

use App\Jobs\Finance\FinanceReceiptJob;
use App\Models\CoreIntegration;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppWebhookReceipt;
use App\Services\Inbox\InboxAlerts;
use App\Support\SchemaState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class WhatsAppIngestion
{
    /** @var array<int, true> new live incoming messages of this receipt, told to the phone once it is saved */
    private array $fresh = [];

    /** @var array<int, true> the fresh ones that carry a photo or a document */
    private array $media = [];

    public function process(int $receiptId): void
    {
        $this->fresh = [];
        $this->ingest($receiptId);
        // After the commit: the phone push (yakup 2026-11-09, "Gelen kutusuna yeni mesaj geldiğinde telefona bildirim").
        foreach (array_keys($this->fresh) as $messageId) {
            try {
                app(InboxAlerts::class)->whatsapp($messageId);
            } catch (Throwable $exception) {
                report($exception);
            }
            // Finans: a photo or document may be a payer's dekont; it is read in the background (ReceiptCapture).
            if (isset($this->media[$messageId])) {
                rescue(fn () => FinanceReceiptJob::dispatch($messageId), report: false);
            }
        }
        $this->media = [];
    }

    private function ingest(int $receiptId): void
    {
        DB::transaction(function () use ($receiptId): void {
            $this->fresh = [];
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
                    // Account-level events (no phone metadata): kept so a silent disconnect is visible. 2026-11-08 the
                    // phone dropped the coexistence link, messages stopped and the panel still said "Bağlı".
                    if (is_array($value) && ! isset($value['messaging_product']) && is_string($change['field'] ?? null)) {
                        $this->accountEvent($config, (string) $change['field'], $value);
                        $ignored++;

                        continue;
                    }
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
                            if ($echo && is_array($message)) {
                                $message['from'] = $this->digits($message['from'] ?? '');
                                $message['to'] = $this->digits($message['to'] ?? data_get($value, 'contacts.0.wa_id', ''));
                            }
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
                                $contactId = $this->digits($thread['id'] ?? data_get($thread, 'context.wa_id', ''));
                                foreach (($thread['messages'] ?? []) as $message) {
                                    if (! is_array($message) || $contactId === '') {
                                        $ignored++;

                                        continue;
                                    }
                                    $from = $this->digits($message['from'] ?? '');
                                    $outgoing = $from !== '' && $from === $this->digits($config['business_phone'] ?? '');
                                    if (! $outgoing && $from !== $contactId) {
                                        $ignored++;

                                        continue;
                                    }
                                    // Meta's history has no "to": a message from the business number was sent to the
                                    // thread's customer (2026-11-08: every own message was dropped for lacking it).
                                    $message['from'] = $from;
                                    if ($outgoing) {
                                        $message['to'] = $contactId;
                                    }
                                    $result = $this->message($integration, $config, $message, $names, $outgoing, true);
                                    $accepted += $result === 1 ? 1 : 0;
                                    $ignored += $result === -1 ? 1 : 0;
                                }
                            }
                        }
                    } elseif ($field === 'smb_app_state_sync') {
                        foreach (($value['state_sync'] ?? []) as $sync) {
                            $applied = is_array($sync) && ($sync['type'] ?? '') === 'contact' && $this->contactName($integration->id, $sync);
                            $ignored += $applied ? 0 : 1;
                        }
                        $config['contacts_synced_at'] = now()->toIso8601String();
                    } else {
                        $this->accountEvent($config, (string) $field, $value);
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
            if ($accepted > 0) {
                $config['last_message_received_at'] = now()->toIso8601String();
            }
            $integration->update(['config' => $config]);
            $receipt->update([
                'status' => 'completed', 'accepted_count' => $accepted, 'ignored_count' => $ignored,
                'payload' => null, 'processed_at' => now(), 'error_code' => null,
            ]);
        }, 3);
    }

    /**
     * The file a photo or document message carries (Meta's media id), kept so Finans can read a payer's dekont.
     *
     * @return array{media_id?: string, media_mime?: ?string}
     */
    private function mediaOf(array $message, string $type): array
    {
        $id = in_array($type, ['image', 'document'], true) ? data_get($message, $type.'.id') : null;
        if (! is_string($id) || $id === '' || strlen($id) > 190 || ! SchemaState::hasColumn('whatsapp_messages', 'media_id')) {
            return [];
        }
        $mime = data_get($message, $type.'.mime_type');

        return ['media_id' => $id, 'media_mime' => is_string($mime) ? substr($mime, 0, 120) : null];
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
            'image', 'video', 'document' => trim('['.['image' => 'Fotoğraf', 'video' => 'Video', 'document' => 'Belge'][$type].'] '.(string) data_get($message, $type.'.caption', '')),
            'audio' => '[Sesli mesaj]',
            'sticker' => '[Çıkartma]',
            'media_placeholder' => '[Fotoğraf, video ya da dosya]',
            'location' => trim('[Konum] '.(string) data_get($message, 'location.name', '').' '.(string) data_get($message, 'location.address', '')),
            'contacts' => '[Kişi kartı] '.(string) data_get($message, 'contacts.0.name.formatted_name', ''),
            'reaction' => '[Tepki] '.(string) data_get($message, 'reaction.emoji', ''),
            'edit' => '[Düzenlendi] '.(string) data_get($message, 'edit.message.text.body', ''),
            'revoke' => '[Silinen mesaj]',
            // Meta could not hand the content over (a message type the API does not read, or one WhatsApp keeps on the phone).
            'errors', 'unsupported' => '[WhatsApp bu mesajın içeriğini paylaşmadı'.(is_string(data_get($message, 'errors.0.title')) ? ': '.mb_substr((string) data_get($message, 'errors.0.title'), 0, 120) : '').' · telefonda görün]',
            default => '['.$type.' — metin olarak çözümlenmedi]',
        };
        if (! is_string($body) || $body === '') {
            return -1;
        }
        $saved = DB::table('whatsapp_contact_names')->where('integration_id', $integration->id)->where('contact_id', $contact)->value('name');
        $conversation = WhatsAppConversation::query()->firstOrCreate([
            'integration_id' => $integration->id, 'phone_number_id' => $config['phone_number_id'], 'contact_id' => $contact,
        ], ['contact_name' => $saved ?? $names[$contact] ?? null]);
        $conversation = WhatsAppConversation::query()->lockForUpdate()->findOrFail($conversation->id);
        $sentAt = CarbonImmutable::createFromTimestampUTC((int) $timestamp);
        $replyId = data_get($message, 'context.id');
        $stored = $conversation->messages()->firstOrCreate(['message_id' => $id], [
            'direction' => $outgoing ? 'outgoing' : 'incoming', 'message_type' => $type,
            'body' => $body, 'sent_at' => $sentAt, 'is_history' => $history,
            'reply_to_message_id' => is_string($replyId) ? substr($replyId, 0, 255) : null,
        ] + $this->mediaOf($message, $type));
        if (! $stored->wasRecentlyCreated) {
            return 0;
        }
        if (! $outgoing && ! $history) {
            $this->fresh[$stored->id] = true;
            if (filled($stored->media_id)) {
                $this->media[$stored->id] = true;
            }
        }
        $update = [
            // The name in the phone's address book wins over the WhatsApp profile name.
            'contact_name' => $saved ?? $names[$contact] ?? $conversation->contact_name,
            'last_message_at' => $conversation->last_message_at?->greaterThan($sentAt) ? $conversation->last_message_at : $sentAt,
            'revision' => $conversation->revision + 1, 'suggestion_status' => 'pending', 'error_code' => null,
        ];
        if (! $outgoing) {
            // Track the last inbound time for the 24-hour reply-window indicator, and flag a KVKK opt-out (STOP/DUR).
            $update['last_incoming_at'] = $conversation->last_incoming_at?->greaterThan($sentAt) ? $conversation->last_incoming_at : $sentAt;
            // A new customer message takes a conversation marked done (or put off with "Sonra") back to the open list.
            $update['done_at'] = null;
            $update['snoozed_until'] = null;
            // Click-to-WhatsApp ad: Meta sends the ad with the first message; the sales record reads its source from it.
            $referral = data_get($message, 'referral');
            if (is_array($referral) && $referral !== [] && $conversation->referral === null) {
                $update['referral'] = $this->referral($referral, $sentAt);
            }
            if ($this->isOptOut($body)) {
                $update['opted_out_at'] = $sentAt;
            }
        }
        $conversation->update($update);

        return 1;
    }

    /** Events MoxDOP does not ingest, newest first; an offboarding one marks the connection as broken. */
    public const array DISCONNECT_EVENTS = ['PARTNER_REMOVED', 'ACCOUNT_OFFBOARDED', 'ACCOUNT_DELETED', 'ACCOUNT_DISCONNECTED', 'DISCONNECTED'];

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $value
     */
    private function accountEvent(array &$config, string $field, array $value): void
    {
        $event = mb_substr((string) ($value['event'] ?? data_get($value, 'decision', '')), 0, 60);
        $events = array_slice((array) ($config['other_events'] ?? []), 0, 9);
        array_unshift($events, ['field' => mb_substr($field, 0, 60), 'event' => $event, 'at' => now()->toIso8601String()]);
        $config['other_events'] = $events;
        $upper = strtoupper($event);
        if ($field === 'account_update' && (in_array($upper, self::DISCONNECT_EVENTS, true) || str_contains($upper, 'OFFBOARD'))) {
            $config['connection_error'] = 'Meta bildirdi: numaranın telefonla (WhatsApp Business uygulaması) bağlantısı kaldırıldı ('.$event.'). Mesajlar artık gelmez; "Bağlamaya başla" ile yeniden bağlayın.';
            $config['disconnected_at'] = now()->toIso8601String();
        }
    }

    private function digits(mixed $value): string
    {
        return is_scalar($value) ? (string) preg_replace('/[^0-9]/', '', (string) $value) : '';
    }

    /**
     * One contact of the WhatsApp Business app's address book (smb_app_state_sync): kept for numbers without a chat
     * yet, and written on the existing chats with that number. "remove" forgets the saved name.
     *
     * @param  array<string, mixed>  $sync
     */
    private function contactName(int $integrationId, array $sync): bool
    {
        $phone = $this->digits(data_get($sync, 'contact.phone_number', ''));
        if (! preg_match('/^[0-9]{7,20}$/', $phone)) {
            return false;
        }
        if (($sync['action'] ?? '') === 'remove') {
            DB::table('whatsapp_contact_names')->where('integration_id', $integrationId)->where('contact_id', $phone)->delete();

            return true;
        }
        $name = trim((string) (data_get($sync, 'contact.full_name') ?: data_get($sync, 'contact.first_name', '')));
        if ($name === '') {
            return false;
        }
        $name = mb_substr($name, 0, 255);
        DB::table('whatsapp_contact_names')->updateOrInsert(['integration_id' => $integrationId, 'contact_id' => $phone], ['name' => $name, 'updated_at' => now(), 'created_at' => now()]);
        WhatsAppConversation::query()->where('integration_id', $integrationId)->where('contact_id', $phone)->update(['contact_name' => $name]);

        return true;
    }

    /**
     * The ad a Click-to-WhatsApp conversation started from (Meta's `referral` object), trimmed to what the record uses.
     *
     * @param  array<string, mixed>  $referral
     * @return array<string, string>
     */
    private function referral(array $referral, CarbonImmutable $at): array
    {
        $out = ['at' => $at->toIso8601String()];
        foreach (['source_type', 'source_id', 'source_url', 'headline', 'body', 'media_type', 'ctwa_clid'] as $key) {
            $value = $referral[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $out[$key] = mb_substr(trim((string) $value), 0, $key === 'body' ? 500 : 255);
            }
        }

        return $out;
    }

    /** A KVKK opt-out: the contact asked to stop being messaged. Whole-message match only, so it never trips on prose. */
    private function isOptOut(string $body): bool
    {
        $text = mb_strtolower(trim(str_replace(['İ', 'I', 'ı'], ['i', 'i', 'i'], $body)));

        return in_array($text, ['dur', 'stop', 'iptal', 'çık', 'cik', 'çıkar', 'cikar', 'abonelikten çık', 'abonelikten cik', 'unsubscribe', 'listeden çık', 'listeden cik'], true);
    }
}
