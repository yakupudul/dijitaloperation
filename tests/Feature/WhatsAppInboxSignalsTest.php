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
