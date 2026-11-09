<?php

namespace Tests\Feature;

use App\Ai\Agents\SalesAnalysisAgent;
use App\Ai\Agents\SalesReplyAgent;
use App\Livewire\Operator\Calendar\CalendarPage;
use App\Livewire\Operator\Sales\LeadCardPage;
use App\Livewire\Operator\Sales\SalesPage;
use App\Models\AgencySetting;
use App\Models\CoreIntegration;
use App\Models\Reminder;
use App\Models\SalesLead;
use App\Models\SalesLeadEvent;
use App\Models\User;
use App\Services\Sales\LeadIntake;
use App\Services\Sales\SalesAnalysisWriter;
use App\Services\Sales\SalesLeads;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Satış 2. hafta (2026-11-05): statuses that move by themselves, one card per person, site forms told at once. */
class SalesIntakeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu'])->forceFill(['push_ntfy_url' => 'https://ntfy.test/moxdop'])->save();
        Http::fake(['https://ntfy.test/*' => Http::response('{}'), '*' => Http::response('', 404)]);
        CarbonImmutable::setTestNow('2026-11-05 08:00:00'); // 11:00 in Istanbul
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return list<string> titles pushed to the phone */
    private function pushes(): array
    {
        return collect(Http::recorded())->map(fn (array $pair): ClientRequest => $pair[0])
            ->filter(fn (ClientRequest $r): bool => str_starts_with($r->url(), 'https://ntfy.test/'))
            ->map(fn (ClientRequest $r): string => (string) ($r->header('Title')[0] ?? $r->header('X-Title')[0] ?? $r->body()))->values()->all();
    }

    public function test_statuses_move_forward_only_and_a_card_can_be_put_off_for_later(): void
    {
        $leads = app(SalesLeads::class);
        $lead = SalesLead::query()->create(['name' => 'Dr. Ercan', 'stage' => 'new', 'source' => 'whatsapp']);
        $leads->markSent($lead, 'Merhaba, sitenizde üç sorun gördüm.');
        $this->assertSame('contacted', $lead->fresh()->stage);
        $lead = $lead->fresh();
        $leads->markSent($lead, 'Aylık 12.000 TL + KDV.');
        $this->assertSame('priced', $lead->fresh()->stage);
        $leads->meetingPlanned($lead = $lead->fresh());
        $this->assertSame('priced', $lead->fresh()->stage, 'never back');

        $this->assertTrue(SalesLeads::isThinking('Tamam hocam ortağıma danışıp size döneceğim'));
        $this->assertTrue(SalesLeads::isThinking('Ay başı konuşalım'));
        $this->assertFalse(SalesLeads::isThinking('Fiyat nedir?'));

        Livewire::actingAs($this->admin)->test(LeadCardPage::class, ['lead' => (string) $lead->id])
            ->set('laterDate', '2026-11-04')->call('later')->assertHasErrors('laterDate')
            ->set('laterDate', '2026-12-01')->call('later')->assertHasNoErrors();
        $lead->refresh();
        $this->assertSame('later', $lead->stage);
        $this->assertSame('2026-12-01 07:00:00', $lead->next_step_at->format('Y-m-d H:i:s'), '10:00 Istanbul');

        // Writing again brings a put-off card back into the line.
        $this->assertTrue($lead->advanceTo('talking'));
    }

    public function test_one_card_per_person_by_phone_or_email_and_a_shared_site_only_asks(): void
    {
        $leads = app(SalesLeads::class);
        $first = $leads->quickAdd(['name' => 'Dt. Ayşe Kaya', 'phone' => '0532 111 22 33', 'email' => 'Ayse@Klinik.com', 'website' => 'klinik.com']);
        $this->assertSame('ayse@klinik.com', $first->email);
        $this->assertSame($first->id, $leads->quickAdd(['email' => 'ayse@klinik.com', 'city' => 'İzmir'])->id, 'same e-mail');
        $this->assertSame($first->id, $leads->quickAdd(['phone' => '+90 532 111 2233'])->id, 'same phone');
        $this->assertSame($first->id, $leads->quickAdd(['name' => 'Ayşe Kaya', 'website' => 'https://www.klinik.com/'])->id, 'same site, same name');

        $second = $leads->quickAdd(['name' => 'Dr. Mehmet Demir', 'website' => 'klinik.com', 'phone' => '0533 444 55 66']);
        $this->assertNotSame($first->id, $second->id, 'another doctor of the same clinic');
        $this->assertSame($first->id, (int) $second->duplicate_of_id);
        Reminder::query()->create(['user_id' => $this->admin->id, 'kind' => 'meeting', 'title' => 'Görüşme', 'remind_at' => '2026-11-06 11:00:00', 'sales_lead_id' => $first->id]);
        $leads->event($first, 'note', 'İlk kartın notu');

        Livewire::actingAs($this->admin)->test(LeadCardPage::class, ['lead' => (string) $second->id])
            ->assertSee('Aynı siteden başka bir kart var')->call('mergeDuplicate')->assertHasNoErrors();
        $this->assertNull(SalesLead::query()->find($first->id));
        $second->refresh();
        $this->assertNull($second->duplicate_of_id);
        $this->assertSame('ayse@klinik.com', $second->email, 'blank fields filled from the other card');
        $this->assertSame('Dr. Mehmet Demir', $second->name, 'the kept card keeps its own');
        $this->assertContains('İlk kartın notu', SalesLeadEvent::query()->where('lead_id', $second->id)->get()->pluck('body')->all());
        $this->assertSame($second->id, (int) Reminder::query()->value('sales_lead_id'));

        $third = $leads->quickAdd(['name' => 'Dr. Can Ak', 'website' => 'klinik.com']);
        Livewire::actingAs($this->admin)->test(LeadCardPage::class, ['lead' => (string) $third->id])->call('notDuplicate')->assertDontSee('Aynı siteden başka bir kart var');
    }

    public function test_a_site_form_opens_a_card_with_its_origin_and_is_pushed_at_once_in_the_day(): void
    {
        $this->postJson('/api/satis/form', ['clinic' => 'Gülüş Diş', 'phone' => '0532 000 11 22'])->assertStatus(401);
        $token = LeadIntake::issueToken();
        $this->postJson('/api/satis/form', ['clinic' => 'Gülüş Diş'], ['X-Moxdop-Token' => 'mxf_wrong'])->assertStatus(401);
        $this->postJson('/api/satis/form', ['clinic' => 'Gülüş Diş'], ['X-Moxdop-Token' => $token])->assertStatus(422);
        $this->postJson('/api/satis/form', ['clinic' => 'Bot', 'phone' => '0532 999 99 99', 'website_url_confirm' => 'x'], ['X-Moxdop-Token' => $token])->assertStatus(202);
        $this->assertSame(0, SalesLead::query()->count());

        $id = $this->postJson('/api/satis/form', [
            'form' => 'analiz', 'clinic' => 'Gülüş Diş', 'city' => 'Manisa', 'phone' => '+90 532 000 11 22', 'email' => 'info@gulus.com',
            'page' => 'https://moximu.com/dis-klinigi-seo/?utm_source=google&utm_medium=cpc&utm_campaign=klinik-seo&gclid=abc', 'gclid' => 'abc',
            'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'klinik-seo',
        ], ['X-Moxdop-Token' => $token])->assertStatus(201)->json('lead');
        $lead = SalesLead::query()->findOrFail($id);
        $this->assertSame('form', $lead->source);
        $this->assertSame('google_ads', $lead->heard_from);
        $this->assertSame('klinik-seo', $lead->origin['utm_campaign']);
        $this->assertSame('Formu yanıtla', $lead->next_step);
        $this->assertTrue($lead->isDue());
        $this->assertCount(1, $this->pushes());

        // The same person again adds to the same card.
        $this->postJson('/api/satis/form', ['clinic' => 'Gülüş Diş', 'email' => 'INFO@gulus.com', 'message' => 'Fiyat?'], ['X-Moxdop-Token' => $token])->assertStatus(201);
        $this->assertSame(1, SalesLead::query()->count());
        $this->assertSame(2, SalesLeadEvent::query()->where('lead_id', $id)->where('kind', 'message_in')->count());

        Livewire::actingAs($this->admin)->test(LeadCardPage::class, ['lead' => (string) $id])
            ->assertSee('info@gulus.com')->assertSee('Google reklamı')->assertSee('moximu.com/dis-klinigi-seo/');
    }

    public function test_a_form_number_typed_with_a_doubled_prefix_is_fixed_and_a_wrong_one_never_opens_whatsapp(): void
    {
        $token = LeadIntake::issueToken();
        $id = $this->postJson('/api/satis/form', ['clinic' => 'Çift Önek', 'phone' => '+90 0532 000 11 22'], ['X-Moxdop-Token' => $token])->assertStatus(201)->json('lead');
        $lead = SalesLead::query()->findOrFail($id);
        $this->assertSame('+90 532 000 11 22', $lead->phone);
        $this->assertSame('905320001122', $lead->waNumber());

        $id = $this->postJson('/api/satis/form', ['clinic' => 'Eksik Hane', 'phone' => '+90 533 000 11 2', 'email' => 'x@eksik.com'], ['X-Moxdop-Token' => $token])->assertStatus(201)->json('lead');
        $wrong = SalesLead::query()->findOrFail($id);
        $this->assertNull($wrong->waNumber());
        Livewire::actingAs($this->admin)->test(LeadCardPage::class, ['lead' => (string) $id])
            ->assertSee('Numara hatalı, WhatsApp açılmaz')->assertSee('x@eksik.com')->assertDontSee('wa.me/9053300011');
    }

    public function test_night_forms_wait_for_the_eight_thirty_summary(): void
    {
        $token = LeadIntake::issueToken();
        CarbonImmutable::setTestNow('2026-11-04 21:30:00'); // 00:30 Istanbul
        $this->assertTrue(LeadIntake::quiet());
        $this->postJson('/api/satis/form', ['clinic' => 'Gece Klinik', 'phone' => '0532 777 88 99'], ['X-Moxdop-Token' => $token])->assertStatus(201);
        $this->assertSame([], $this->pushes(), 'nobody woken at night');

        CarbonImmutable::setTestNow('2026-11-05 05:30:00'); // 08:30 Istanbul
        $this->assertFalse(LeadIntake::quiet());
        $this->assertSame(1, app(LeadIntake::class)->morningSummary());
        $this->assertCount(1, $this->pushes());
    }

    public function test_connecting_the_site_form_keeps_the_old_token_when_the_site_refuses(): void
    {
        $old = LeadIntake::issueToken();
        Livewire::actingAs($this->admin)->withQueryParams(['sekme' => 'sonuclar'])->test(SalesPage::class)
            ->assertSee('Site formu')->call('connectForm')->assertHasErrors('formSite')
            ->set('formSite', '999')->call('connectForm')->assertHasErrors('formSite');
        $this->assertSame($old, LeadIntake::token());
    }

    public function test_a_prospect_who_talks_gets_the_full_analysis_once_and_the_card_shows_it(): void
    {
        config(['moxdop.anthropic.api_key' => 'sk-ant-test']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $analysis = "## Kısa durum\nManisa'da diş kliniği; fiyat sordu.\n\n## Sitede gördüklerimiz\n- Ana sayfada tek dokunuşla arama yok.\n- 12 hizmetin hiçbirinin kendi sayfası yok.\n\n## Teklif\nKlinik SEO + Google İşletme Profili 14.000 TL + KDV/ay.\n\n## Riskler\nKarar ortakla verilecek; bütçe ay başı.";
        SalesAnalysisAgent::fake([['analysis' => $analysis, 'message' => "Ahmet Hocam merhaba, sitenizde 12 hizmetin hiçbirinin kendi sayfası yok.\nBu ay hizmet sayfalarıyla başlayalım mı?", 'headline' => '12 hizmetin kendi sayfası yok']]);
        SalesReplyAgent::fake([['action' => 'reply', 'reply' => 'Ahmet Hocam, görüşme için teşekkürler.', 'send_on' => '2026-11-06', 'note' => 'Görüşme sonrası.', 'summary' => 'Görüştük.']]);

        $lead = SalesLead::query()->create(['name' => 'Dt. Ahmet Can', 'stage' => 'contacted', 'source' => 'whatsapp']);
        app(SalesLeads::class)->event($lead, 'message_in', 'Fiyat nedir?');
        app(SalesLeads::class)->event($lead, 'message_in', 'Ortağıma soracağım.');
        $lead->advanceTo('talking');
        $lead->save();
        $lead->refresh();
        $this->assertSame('ready', $lead->analysis_status, 'a prospect who started talking is analysed by itself');
        $this->assertStringContainsString('12 hizmetin', (string) $lead->analysis);
        $this->assertSame('12 hizmetin kendi sayfası yok', $lead->analysis_headline);

        // Once only: talking further does not write it again.
        $lead->advanceTo('priced');
        $lead->save();
        SalesAnalysisAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'Dt. Ahmet Can'));
        $this->assertSame(1, SalesLeadEvent::query()->where('lead_id', $lead->id)->where('kind', 'report')->count());

        Livewire::actingAs($this->admin)->test(LeadCardPage::class, ['lead' => (string) $lead->id])
            ->assertSee('Tam analiz')->assertSee('12 hizmetin kendi sayfası yok')->assertSee('Gönderilecek mesaja koy')
            ->call('useAnalysisMessage')->assertSet('draft', "Ahmet Hocam merhaba, sitenizde 12 hizmetin hiçbirinin kendi sayfası yok.\nBu ay hizmet sayfalarıyla başlayalım mı?");

        // A meeting's note: on the card, and the next message is written from it.
        $meeting = Reminder::query()->create(['user_id' => $this->admin->id, 'kind' => 'meeting', 'title' => 'Görüşme: Dt. Ahmet Can', 'remind_at' => '2026-11-05 07:00:00', 'ends_at' => '2026-11-05 07:30:00', 'sales_lead_id' => $lead->id]);
        Livewire::actingAs($this->admin)->test(CalendarPage::class)->call('writeNote', $meeting->id)->set('note', 'Paketi beğendi, ortağıyla konuşup cuma dönecek.')->call('saveNote')->assertHasNoErrors();
        $lead->refresh();
        $this->assertSame('ready', $lead->draft_status);
        $this->assertSame('Görüşme sonrası mesaj', $lead->next_step);
        Livewire::actingAs($this->admin)->test(LeadCardPage::class, ['lead' => (string) $lead->id])->assertSee('Görüşmeler')->assertSee('cuma dönecek');
    }

    public function test_the_automatic_analysis_waits_for_the_site_and_keeps_to_the_daily_share(): void
    {
        $lead = SalesLead::query()->create(['name' => 'Dr. Site', 'stage' => 'talking', 'source' => 'form', 'website' => 'https://ornek.com', 'website_host' => 'ornek.com', 'findings_status' => 'running']);
        $this->assertFalse(SalesAnalysisWriter::queueIfWorth($lead), 'the site is still being read');
        SalesLead::query()->whereKey($lead->id)->update(['analysis_status' => null]);
        for ($i = 0; $i < SalesAnalysisWriter::DAILY_AUTO; $i++) {
            SalesLead::query()->create(['name' => 'X'.$i, 'stage' => 'new', 'analysis' => 'x', 'analysis_at' => now(), 'analysis_note' => 'auto']);
        }
        $lead->forceFill(['findings_status' => 'ready'])->save();
        $this->assertNull($lead->fresh()->analysis_status, 'the day\'s automatic share is used up');
    }
}
