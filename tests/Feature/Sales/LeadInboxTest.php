<?php

namespace Tests\Feature\Sales;

use App\Enums\ProspectSource;
use App\Enums\ProspectStatus;
use App\Livewire\Operator\Sales\LeadInboxPage;
use App\Models\AgencySetting;
use App\Models\Prospect;
use App\Models\User;
use App\Services\Sales\AgencyLeadInbox;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 8g: the agency's lead inbox — token webhook, spam trap, phone de-duplication, push, convert to prospect.
 */
final class LeadInboxTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->create(['agency_name' => 'Moximu', 'push_ntfy_url' => 'https://ntfy.sh/moxdop-test', 'push_min_severity' => 'high']);
        Http::fake(['ntfy.sh/*' => Http::response('{}')]);
    }

    public function test_webhook_receives_dedupes_filters_spam_and_converts(): void
    {
        $token = app(AgencyLeadInbox::class)->rotateToken();
        $this->assertSame(64, strlen((string) AgencySetting::query()->value('lead_inbox_token_hash')), 'stored hashed');
        $this->assertStringNotContainsString($token, (string) json_encode(AgencySetting::query()->first()->getAttributes()));

        $this->post('/api/leads/'.str_repeat('x', 40), ['ad' => 'X', 'telefon' => '05321112233'])->assertNotFound();

        $this->post('/api/leads/'.$token, ['ad' => 'Ayşe Kaya', 'firma' => 'Kaya Diş', 'telefon' => '0532 111 22 33', 'mesaj' => 'Google reklamı için teklif', 'utm_source' => 'google', 'redirect' => 'https://moximu.com/tesekkurler'])
            ->assertRedirect('https://moximu.com/tesekkurler');
        $this->postJson('/api/leads/'.$token, ['name' => 'Ayşe', 'phone' => '+90 532 111 22 33', 'message' => 'SEO da olur'])->assertOk()->assertJson(['ok' => true]);
        $this->postJson('/api/leads/'.$token, ['name' => 'Bot', 'phone' => '05000000000', 'website_hp' => 'http://spam'])->assertOk();

        $leads = DB::table('agency_leads')->orderBy('id')->get();
        $this->assertCount(2, $leads, 'same phone within a day is merged');
        $this->assertSame('Kaya Diş', $leads[0]->company);
        $this->assertStringContainsString('SEO da olur', $leads[0]->message);
        $this->assertSame(['utm_source' => 'google'], json_decode($leads[0]->utm, true));
        $this->assertSame('spam', $leads[1]->status);
        // One push for the first insert, one for the same-day repeat (the UI promises a notification for every new inquiry); the spam row does not notify.
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'ntfy.sh') && str_contains((string) base64_decode(substr((string) ($r->header('Title')[0] ?? ''), 10, -2)), 'Kaya Diş'));

        $this->actingAs($this->admin);
        Livewire::test(LeadInboxPage::class)->assertSee('Kaya Diş')->assertDontSee('Bot')
            ->call('convert', $leads[0]->id)->assertRedirect(route('operator.prospect', ['prospectId' => Prospect::query()->value('id')]));
        $prospect = Prospect::query()->firstOrFail();
        $this->assertSame(['Kaya Diş', 'Ayşe Kaya', ProspectSource::Website], [$prospect->company_name, $prospect->contact_name, $prospect->source]);
        $this->assertSame('converted', DB::table('agency_leads')->where('id', $leads[0]->id)->value('status'));
        $this->assertSame($prospect->id, app(AgencyLeadInbox::class)->convert($leads[0]->id)->id, 'converting twice returns the same prospect');
        $this->get(route('operator.leads'))->assertOk();
    }

    public function test_manual_entry_status_and_token_rotation(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(LeadInboxPage::class)
            ->set('manual.name', 'Mehmet')->call('addManual')->assertHasErrors()
            ->set('manual.phone', '0212 333 44 55')->set('manual.source', 'whatsapp')->call('addManual')->assertSee('Talep eklendi')
            ->call('rotateToken')->assertSee('/api/leads/')->assertSee('website_hp');
        $lead = DB::table('agency_leads')->first();
        $this->assertSame(['whatsapp', 'new'], [$lead->source, $lead->status]);
        Livewire::test(LeadInboxPage::class)->call('setStatus', $lead->id, 'contacted');
        $this->assertSame('contacted', DB::table('agency_leads')->value('status'));

        $old = app(AgencyLeadInbox::class)->rotateToken();
        app(AgencyLeadInbox::class)->rotateToken();
        $this->post('/api/leads/'.$old, ['telefon' => '05321112233'])->assertNotFound();
    }

    public function test_meta_leadgen_id_is_idempotent_and_invalid_email_only_is_spam(): void
    {
        $inbox = app(AgencyLeadInbox::class);
        $first = $inbox->receive(['name' => 'Zeynep', 'email' => 'z@x.com'], 'meta_lead_ad', 'meta_leadgen:9001');
        $again = $inbox->receive(['name' => 'Zeynep', 'email' => 'z@x.com'], 'meta_lead_ad', 'meta_leadgen:9001');
        $this->assertSame($first['id'], $again['id']);
        $this->assertTrue($again['duplicate']);
        $this->assertSame(1, DB::table('agency_leads')->where('external_id', 'meta_leadgen:9001')->count());

        // A malformed e-mail with no phone is contactless, so it is spam, not a "new" lead.
        $spam = $inbox->receive(['name' => 'X', 'email' => 'not-an-email']);
        $this->assertTrue($spam['spam']);
        $this->assertSame('spam', DB::table('agency_leads')->where('id', $spam['id'])->value('status'));

        // A genuine same-day lead from a phone that a spam row already used must not merge into (and hide inside) that spam row.
        $inbox->receive(['phone' => '0532 900 00 00', 'website_hp' => 'x']);
        $real = $inbox->receive(['name' => 'Gerçek', 'phone' => '0532 900 00 00', 'message' => 'teklif']);
        $this->assertFalse($real['duplicate']);
        $this->assertSame('new', DB::table('agency_leads')->where('id', $real['id'])->value('status'));
    }

    public function test_convert_links_to_existing_open_prospect_instead_of_duplicating(): void
    {
        $prospect = Prospect::query()->create(['company_name' => 'Var Olan', 'contact_phone' => '+90 532 111 22 33', 'source' => ProspectSource::Website, 'status' => ProspectStatus::Contacted]);
        $lead = app(AgencyLeadInbox::class)->receive(['name' => 'Aynı Kişi', 'phone' => '0532 111 22 33', 'message' => 'tekrar']);

        $result = app(AgencyLeadInbox::class)->convert($lead['id']);
        $this->assertSame($prospect->id, $result->id, 'the same phone links to the open prospect, no duplicate');
        $this->assertSame(1, Prospect::query()->count());
    }

    public function test_status_change_cannot_move_a_converted_lead_and_assign_and_sla(): void
    {
        $this->actingAs($this->admin);
        $lead = app(AgencyLeadInbox::class)->receive(['name' => 'A', 'phone' => '0555 111 11 11']);
        app(AgencyLeadInbox::class)->convert($lead['id'], $this->admin);

        Livewire::test(LeadInboxPage::class)->call('setStatus', $lead['id'], 'lost')->assertStatus(422);
        $this->assertSame('converted', DB::table('agency_leads')->where('id', $lead['id'])->value('status'));

        $lead2 = app(AgencyLeadInbox::class)->receive(['name' => 'B', 'phone' => '0555 222 22 22']);
        Livewire::test(LeadInboxPage::class)
            ->call('assign', $lead2['id'], $this->admin->id)
            ->call('setStatus', $lead2['id'], 'contacted');
        $row = DB::table('agency_leads')->where('id', $lead2['id'])->first();
        $this->assertSame($this->admin->id, $row->assigned_to);
        $this->assertNotNull($row->first_response_at, 'first response time is stamped on first contact');
    }
}
