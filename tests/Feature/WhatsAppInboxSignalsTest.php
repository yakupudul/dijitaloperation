<?php

namespace Tests\Feature;

use App\Models\CoreIntegration;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppWebhookReceipt;
use App\Services\WhatsApp\WhatsAppIngestion;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** WhatsApp inbox signals: the 24h reply-window (last inbound time) and KVKK opt-out detection from an inbound message. */
final class WhatsAppInboxSignalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbound_sets_window_time_and_opt_out_is_detected(): void
    {
        $integration = CoreIntegration::factory()->create([
            'provider' => 'whatsapp', 'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['waba_id' => 'WABA1', 'phone_number_id' => 'PN1', 'business_phone' => '905550000000', 'enabled' => true],
        ]);

        $this->ingest($integration, '905551112233', 'Merhaba, fiyat öğrenebilir miyim?', now()->subHours(2));
        $conversation = WhatsAppConversation::query()->where('contact_id', '905551112233')->firstOrFail();
        $this->assertNotNull($conversation->last_incoming_at);
        $this->assertNull($conversation->opted_out_at);

        $this->ingest($integration, '905551112233', 'DUR', now());
        $conversation->refresh();
        $this->assertNotNull($conversation->opted_out_at, 'a STOP/DUR message flags a KVKK opt-out');
    }

    public function test_history_keeps_own_messages_contacts_give_names_and_echoes_without_to_land_in_the_chat(): void
    {
        $integration = CoreIntegration::factory()->create([
            'provider' => 'whatsapp', 'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['waba_id' => 'WABA1', 'phone_number_id' => 'PN1', 'business_phone' => '905550000000', 'enabled' => true],
        ]);
        $at = now()->subDays(3)->timestamp;
        // Meta's history: no "to"; a message from the business number belongs to the thread's customer.
        $this->receipt($integration, 'history', ['history' => [[
            'metadata' => ['phase' => 1, 'chunk_order' => 1, 'progress' => 100],
            'threads' => [[
                'id' => '905551112233',
                'messages' => [
                    ['from' => '905551112233', 'id' => 'h1', 'timestamp' => (string) $at, 'type' => 'text', 'text' => ['body' => 'Fiyat nedir?'], 'history_context' => ['status' => 'READ']],
                    ['from' => '+90 555 000 00 00', 'id' => 'h2', 'timestamp' => (string) ($at + 60), 'type' => 'text', 'text' => ['body' => 'Aylık 14.000 TL'], 'history_context' => ['status' => 'READ']],
                    ['from' => '905551112233', 'id' => 'h3', 'timestamp' => (string) ($at + 120), 'type' => 'media_placeholder', 'history_context' => ['status' => 'READ']],
                ],
            ]],
        ]]]);
        $conversation = WhatsAppConversation::query()->where('contact_id', '905551112233')->firstOrFail();
        $this->assertSame(['incoming', 'outgoing', 'incoming'], $conversation->messages()->orderBy('sent_at')->pluck('direction')->all());
        $this->assertSame('Aylık 14.000 TL', $conversation->messages()->where('message_id', 'h2')->value('body'));
        $this->assertSame('[Fotoğraf, video ya da dosya]', $conversation->messages()->where('message_id', 'h3')->value('body'));

        // Address book: the saved name goes on the existing chat and waits for a number with no chat yet.
        $this->receipt($integration, 'smb_app_state_sync', ['state_sync' => [
            ['type' => 'contact', 'contact' => ['full_name' => 'Dr. Ayşe Yılmaz', 'first_name' => 'Ayşe', 'phone_number' => '+90 555 111 22 33'], 'action' => 'add', 'metadata' => ['timestamp' => (string) $at]],
            ['type' => 'contact', 'contact' => ['full_name' => 'Mehmet Bey', 'phone_number' => '905554445566'], 'action' => 'add', 'metadata' => ['timestamp' => (string) $at]],
        ]]);
        $this->assertSame('Dr. Ayşe Yılmaz', $conversation->fresh()->contact_name);
        $this->ingest($integration, '905554445566', 'Merhaba', now());
        $this->assertSame('Mehmet Bey', WhatsAppConversation::query()->where('contact_id', '905554445566')->value('contact_name'), 'the saved name wins over the profile name');

        // A message sent from the phone (echo) with the customer only in contacts.
        $this->receipt($integration, 'smb_message_echoes', [
            'contacts' => [['wa_id' => '905551112233']],
            'message_echoes' => [['from' => '905550000000', 'id' => 'e1', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Telefondan yazdım']]],
        ]);
        $this->assertSame('outgoing', $conversation->messages()->where('message_id', 'e1')->value('direction'));
    }

    /** @param  array<string, mixed>  $value */
    private function receipt(CoreIntegration $integration, string $field, array $value): void
    {
        $receipt = WhatsAppWebhookReceipt::query()->create([
            'integration_id' => $integration->id, 'payload_hash' => hash('sha256', $field.json_encode($value)), 'status' => 'pending',
            'payload' => ['entry' => [['id' => 'WABA1', 'changes' => [['field' => $field, 'value' => [
                'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => 'PN1'], ...$value,
            ]]]]]],
        ]);
        app(WhatsAppIngestion::class)->process($receipt->id);
    }

    private function ingest(CoreIntegration $integration, string $from, string $body, CarbonInterface $at): void
    {
        $receipt = WhatsAppWebhookReceipt::query()->create([
            'integration_id' => $integration->id,
            'payload_hash' => hash('sha256', $from.$body.$at->timestamp),
            'status' => 'pending',
            'payload' => ['entry' => [[
                'id' => 'WABA1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => 'PN1'],
                        'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Test']]],
                        'messages' => [[
                            'id' => 'wamid.'.$from.$at->timestamp,
                            'from' => $from,
                            'timestamp' => (string) $at->timestamp,
                            'type' => 'text',
                            'text' => ['body' => $body],
                        ]],
                    ],
                ]],
            ]]],
        ]);
        app(WhatsAppIngestion::class)->process($receipt->id);
    }
}
