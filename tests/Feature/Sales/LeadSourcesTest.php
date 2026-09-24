<?php

namespace Tests\Feature\Sales;

use App\Models\AgencySetting;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Faz 14: Meta Lead Ads and unknown WhatsApp contacts reach the agency lead inbox. */
final class LeadSourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AgencySetting::query()->create(['agency_name' => 'Moximu']);
        config(['moxdop-leads.meta.verify_token' => 'verify-123', 'moxdop-leads.meta.app_secret' => 'app-secret', 'moxdop-leads.meta.page_access_token' => 'page-token']);
    }

    public function test_meta_leadgen_webhook_verifies_checks_signature_and_reads_the_lead(): void
    {
        $this->get('/api/meta/leadgen?hub_mode=subscribe&hub_verify_token=verify-123&hub_challenge=abc')->assertOk()->assertSee('abc');
        $this->get('/api/meta/leadgen?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=abc')->assertForbidden();

        Http::fake(['graph.facebook.com/*' => Http::response(['field_data' => [
            ['name' => 'full_name', 'values' => ['Deniz Kaya']], ['name' => 'phone_number', 'values' => ['+905321234567']],
            ['name' => 'hizmet', 'values' => ['Web sitesi']],
        ], 'ad_name' => 'Kurumsal site reklamı'])]);
        $payload = json_encode(['object' => 'page', 'entry' => [['changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => '123456789']]]]]]);

        $this->call('POST', '/api/meta/leadgen', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], $payload)->assertForbidden();
        $this->call('POST', '/api/meta/leadgen', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'app-secret')], $payload)
            ->assertOk()->assertJson(['received' => 1]);

        $lead = DB::table('agency_leads')->sole();
        $this->assertSame(['Deniz Kaya', '+905321234567', 'meta_lead_ad', 'hizmet: Web sitesi'], [$lead->name, $lead->phone, $lead->source, $lead->message]);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/123456789') && $request['access_token'] === 'page-token');
    }

    public function test_unknown_whatsapp_contact_becomes_a_lead_but_a_customer_does_not(): void
    {
        $integration = CoreIntegration::factory()->create(['provider' => 'whatsapp']);
        Customer::factory()->create(['primary_phone' => '0532 111 22 33']);
        foreach (['905321112233' => 'Müşteri', '905559998877' => 'Yeni Kişi'] as $phone => $name) {
            WhatsAppConversation::query()->create(['integration_id' => $integration->id, 'phone_number_id' => '1', 'contact_id' => $phone, 'contact_name' => $name, 'last_message_at' => now()]);
        }

        $lead = DB::table('agency_leads')->sole();
        $this->assertSame(['Yeni Kişi', 'whatsapp'], [$lead->name, $lead->source]);
    }

    public function test_unconfigured_meta_webhook_is_not_found(): void
    {
        config(['moxdop-leads.meta.verify_token' => null, 'moxdop-leads.meta.app_secret' => null]);

        $this->get('/api/meta/leadgen?hub_mode=subscribe&hub_verify_token=x&hub_challenge=abc')->assertNotFound();
        $this->postJson('/api/meta/leadgen', [])->assertNotFound();
    }
}
