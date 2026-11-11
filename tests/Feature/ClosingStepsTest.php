<?php

namespace Tests\Feature;

use App\Livewire\Operator\Sales\LeadCardPage;
use App\Livewire\Operator\Sales\SalesPage;
use App\Models\AgencySetting;
use App\Models\BrandFinding;
use App\Models\SalesLead;
use App\Models\SalesLesson;
use App\Models\User;
use App\Services\Access\AccessLinks;
use App\Services\Brand\Baseline;
use App\Services\Operator\Agenda;
use App\Services\Sales\CallBrief;
use App\Services\Sales\ClosingSteps;
use App\Services\Sales\SalesLeads;
use App\Services\Sales\WeekReview;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Satış Düzeni 3.1: "Kazanıldı" is five steps (evet, sözleşme, ilk ödeme, erişimler, kurulum), followed until done. */
class ClosingStepsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        Http::fake();
    }

    public function test_a_won_record_shows_which_step_it_waits_at_until_all_five_are_done(): void
    {
        $lead = SalesLead::query()->create(['name' => 'Ali Akbulut', 'company' => 'Akbulut Diş', 'stage' => 'talking', 'source' => 'whatsapp', 'research_at' => now(),
            'research' => ['business_name' => 'Akbulut Diş', 'person_name' => '', 'kind' => '', 'city' => '', 'district' => '', 'website' => '', 'instagram' => '', 'google_maps' => '', 'maps' => [], 'instagram_facts' => [],
                'site_notes' => [], 'confidence' => 'high', 'sources' => [], 'note' => '', 'findings' => [['title' => 'İşletme Profilinde hizmet listesi boş', 'detail' => 'Profilde tek kategori var, hizmet eklenmemiş.', 'source' => 'Google Maps'], ['title' => 'Sitede implant sayfası yok', 'detail' => '', 'source' => 'site']]]]);
        $closing = app(ClosingSteps::class);
        $this->assertNull($closing->of($lead));

        $brand = app(SalesLeads::class)->win($lead, $this->admin, ['kind' => 'monthly', 'fee' => '14.000', 'starts_on' => now()->toDateString(), 'due_day' => 5]);
        $lead->refresh();
        // Devir (3.3): the sale's findings are the new brand's first findings.
        $handed = BrandFinding::query()->where('brand_id', $brand->id)->orderBy('id')->get();
        $this->assertSame(['İşletme Profili', 'Web sitesi'], $handed->pluck('area')->all());
        $this->assertStringContainsString('Satış analizinden', (string) $handed[0]->evidence);
        $state = $closing->of($lead);
        $this->assertSame('contract', $state['current']);
        $this->assertSame(['yes'], collect($state['steps'])->where('done', true)->pluck('key')->all());
        $this->assertCount(1, $closing->open());
        $this->assertStringStartsWith('Sözleşme: imza', $closing->open()->first()['line']);

        // The card: one action per open step.
        $this->actingAs($this->admin);
        $card = Livewire::test(LeadCardPage::class, ['lead' => (string) $lead->id])->assertSeeHtml('data-lead-closing="contract"')->call('contractSigned');
        $card->assertSeeHtml('data-lead-closing="payment"')->call('firstPaymentTaken')->assertSeeHtml('data-lead-closing="access"');

        // Erişimler: the access link's channels all marked; Kurulum: the brand's first findings.
        $links = app(AccessLinks::class);
        $links->saveIdentities('ajans@example.com', '123456789');
        $link = $links->ask($brand, ['search_console', 'google_business_profile'], $this->admin);
        $links->mark($link, 'search_console');
        $this->assertSame('access', $closing->of($lead)['current']);
        $this->assertStringContainsString('2 erişimden 1', $closing->open()->first()['line']);
        $links->mark($link->refresh(), 'google_business_profile');
        $this->assertSame('setup', $closing->of($lead)['current']);

        // Satış and Komuta keep it in front until the setup is written.
        Livewire::test(SalesPage::class)->assertSeeHtml('data-sales-closing-row="setup"');
        $rows = app(Agenda::class)->today($this->admin->id)['rows'];
        $this->assertSame('closing', $rows[0]['kind']);
        $this->assertSame('Kurulum: başlangıç ölçümü bekliyor', $rows[0]['line']);

        app(Baseline::class)->take($brand);
        $this->assertTrue($closing->of($lead)['finished']);
        $this->assertCount(0, $closing->open());
        Livewire::test(SalesPage::class)->assertDontSeeHtml('data-sales-closing');
    }

    public function test_the_call_brief_opens_from_the_record_and_the_note_after_the_call_becomes_a_step(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow('2026-11-11 09:00:00');
        $lead = SalesLead::query()->create(['name' => 'Serap Yıldız', 'company' => 'Yıldız Diş', 'city' => 'Manisa', 'stage' => 'talking', 'source' => 'whatsapp', 'heard_from' => 'google',
            'website' => 'https://yildizdis.example', 'findings' => [['title' => 'Sitede implant sayfası yok', 'consequence' => 'İmplant aramalarında çıkmıyor.', 'severity' => 'high'], ['title' => 'Bilgi', 'severity' => 'info']]]);
        app(SalesLeads::class)->event($lead, 'message_in', 'Fiyat ne kadar, şu an bir ajansla çalışıyorum.');

        $brief = app(CallBrief::class)->of($lead);
        $this->assertSame('Yıldız Diş · Manisa', $brief['who'][0]);
        $this->assertSame(['Sitede implant sayfası yok'], array_column($brief['findings'], 'title'));
        $this->assertCount(2, $brief['objections']);
        $this->assertStringStartsWith('Henüz fiyat verilmedi', $brief['price']['line']);
        $this->assertNotEmpty($brief['price']['list']);

        $this->actingAs($this->admin);
        Livewire::test(LeadCardPage::class, ['lead' => (string) $lead->id])->assertSeeHtml('data-lead-brief-page')->assertSee('Bir ajansla çalışıyor ya da çalışmış')
            ->set('afterCallNote', 'Eşine soracak, cuma tekrar arayacağım')->call('afterCall')->assertHasNoErrors()->assertSet('afterCallNote', '');
        $lead->refresh();
        $this->assertSame('2026-11-13', $lead->next_step_at->timezone('Europe/Istanbul')->toDateString());
        $this->assertSame('queued', $lead->draft_status);
        $this->assertSame(1, $lead->events()->where('kind', 'call')->count());
        CarbonImmutable::setTestNow();
    }

    public function test_the_week_review_lists_who_moved_who_is_stuck_and_three_decisions(): void
    {
        CarbonImmutable::setTestNow('2026-11-13 17:00:00'); // Friday
        $moved = SalesLead::query()->create(['name' => 'Dr. İlerleyen', 'stage' => 'priced', 'source' => 'whatsapp', 'stage_at' => '2026-11-11 10:00:00', 'offer_amount' => 14000, 'offer_confirmed' => true, 'last_contact_at' => '2026-11-12 10:00:00']);
        SalesLead::query()->create(['name' => 'Dr. Eski', 'stage' => 'meeting', 'source' => 'whatsapp', 'stage_at' => '2026-11-02 10:00:00']);
        $owed = SalesLead::query()->create(['name' => 'Dr. Bekleyen', 'stage' => 'talking', 'source' => 'whatsapp', 'last_incoming_at' => '2026-11-09 10:00:00', 'last_contact_at' => '2026-11-08 10:00:00']);
        SalesLead::query()->create(['name' => 'Dr. Sessiz', 'stage' => 'thinking', 'source' => 'form', 'stage_at' => '2026-10-20 10:00:00', 'last_contact_at' => '2026-11-01 10:00:00']);
        foreach (['Dr. İlerleyen' => '2026-11-11 10:00:00', 'Dr. Eski' => '2026-11-02 10:00:00', 'Dr. Sessiz' => '2026-10-20 10:00:00', 'Dr. Bekleyen' => '2026-11-01 10:00:00'] as $name => $at) {
            DB::table('sales_leads')->where('name', $name)->update(['stage_at' => $at]);
        }
        foreach (['soru', 'karar', 'yontem', 'soru'] as $i => $kind) {
            SalesLesson::query()->create(['kind' => $kind, 'text' => 'Öneri '.$i, 'status' => 'proposed']);
        }

        $week = app(WeekReview::class)->week();
        $this->assertSame([$moved->id], array_column($week['moved'], 'id'));
        $this->assertSame(['4 gündür cevap bekliyor', 'Teklif 12 gündür sessiz'], array_column($week['stuck'], 'line'));
        $this->assertSame($owed->id, $week['stuck'][0]['id']);
        $this->assertSame(['Soru', 'Soru', 'Karar'], array_column($week['decisions'], 'kind'));
        $this->assertSame('Satış haftası: 1 kişi ilerledi, 2 kişi takıldı, 3 karar bekliyor.', $week['line']);

        $this->actingAs($this->admin);
        Livewire::test(SalesPage::class)->set('tab', 'sonuclar')->assertSeeHtml('data-sales-week')->assertSee('Dr. Bekleyen');
        CarbonImmutable::setTestNow();
    }

    public function test_an_old_win_and_a_win_without_terms_are_handled(): void
    {
        $lead = SalesLead::query()->create(['name' => 'Eski Müşteri', 'stage' => 'talking', 'source' => 'form']);
        app(SalesLeads::class)->win($lead, $this->admin);
        $closing = app(ClosingSteps::class);
        $state = $closing->of($lead->refresh());
        $this->assertSame('Önce anlaşma şartlarını gir', collect($state['steps'])->firstWhere('key', 'contract')['note']);
        $this->assertFalse($closing->contract($lead, now()->toDateString()));

        $lead->forceFill(['won_at' => now()->subDays(ClosingSteps::WINDOW_DAYS + 1)])->save();
        $this->assertCount(0, $closing->open());
    }
}
