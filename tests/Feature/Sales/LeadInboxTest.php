<?php

namespace Tests\Feature\Sales;

use App\Enums\ProspectSource;
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
        Http::assertSentCount(1);
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
}
