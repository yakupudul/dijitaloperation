<?php

namespace Tests\Feature;

use App\Livewire\Operator\Sales\LeadCardPage;
use App\Livewire\Operator\Sales\SalesPage;
use App\Models\AgencySetting;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\Sales\SalesLeads;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Adaylar tablosu (yakup 2026-11-07): whose turn, last talk, next step, offer; ready views; calls logged; closing asks why. */
class SalesLeadsTableTest extends TestCase
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
        CarbonImmutable::setTestNow('2026-11-07 08:00:00');
        Http::fake();
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_price_in_a_sent_text_is_read_as_the_monthly_offer(): void
    {
        $this->assertSame(14000, SalesLead::priceIn('Web sitesi 30.000 TL, SEO + İşletme Profili 14.000 TL + KDV/ay'));
        $this->assertSame(12000, SalesLead::priceIn('Aylık 12.000 TL + KDV.'));
        $this->assertSame(9000, SalesLead::priceIn('9000 ₺'));
        $this->assertNull(SalesLead::priceIn('Fiyatı görüşelim'));
        $this->assertSame('seo_gbp', SalesLead::serviceIn('SEO ve İşletme Profili yönetimi'));

        $leads = app(SalesLeads::class);
        $lead = $leads->quickAdd(['name' => 'Dt. Eslem', 'phone' => '0532 111 22 33'], $this->admin);
        $leads->markSent($lead, 'Hocam SEO + İşletme Profili aylık 14.000 TL + KDV.', $this->admin);
        $lead = $lead->fresh();
        $this->assertSame('priced', $lead->stage);
        $this->assertSame(14000, $lead->offer_amount);
        $this->assertSame('seo_gbp', $lead->offer_service);
        $this->assertFalse($lead->offer_confirmed, 'read from the text: a suggestion until confirmed');

        Livewire::test(SalesPage::class)->call('confirmOffer', $lead->id)->assertHasNoErrors();
        $this->assertTrue($lead->fresh()->offer_confirmed);
        // A confirmed offer is never overwritten by a later text.
        $leads->markSent($lead->fresh(), 'Web sitesi 30.000 TL', $this->admin);
        $this->assertSame(14000, $lead->fresh()->offer_amount);
    }

    public function test_the_list_shows_whose_turn_the_last_talk_and_the_views_count(): void
    {
        $leads = app(SalesLeads::class);
        $waiting = $leads->quickAdd(['name' => 'Dr. Yaşar', 'clinic' => 'Yaşar Klinik', 'phone' => '0533 500 03 25'], $this->admin);
        $waiting->forceFill(['last_contact_at' => now()->subDays(3), 'last_incoming_at' => now()->subDay(), 'notes' => "Ajanslardan yanmış\nikinci satır"])->save();
        $quiet = $leads->quickAdd(['name' => 'Dt. Gözde', 'phone' => '0534 000 11 22'], $this->admin);
        $quiet->forceFill(['last_contact_at' => now()->subDays(20), 'next_step_at' => null, 'next_step' => null, 'created_at' => now()->subDays(30)])->save();

        $this->assertTrue($waiting->fresh()->awaitsUs());
        $this->assertFalse($quiet->fresh()->awaitsUs());

        $page = Livewire::test(SalesPage::class)->set('tab', 'adaylar');
        $page->assertSee('Dr. Yaşar')->assertSee('Sende')->assertSee('Ajanslardan yanmış')->assertSee('Adım yok');
        $counts = $page->viewData('viewCounts');
        $this->assertSame(2, $counts['acik']);
        $this->assertSame(1, $counts['borclu']);
        $this->assertSame(1, $counts['adimsiz']);
        $this->assertSame(1, $counts['soguyan']);
        $this->assertSame([$waiting->id, $quiet->id], $page->viewData('list')->pluck('id')->all(), 'the one waiting for my answer comes first');

        $page->set('view', 'borclu');
        $this->assertSame([$waiting->id], $page->viewData('list')->pluck('id')->all());
        // A search looks in the note too.
        $page->set('q', 'yanmış');
        $this->assertSame([$waiting->id], $page->viewData('list')->pluck('id')->all());
        $this->get('/satis?sekme=adaylar')->assertOk()->assertSee('Cevap borçlu olduklarım');
    }

    public function test_a_call_is_logged_from_the_row_and_must_end_with_a_next_step_or_a_close(): void
    {
        $leads = app(SalesLeads::class);
        $lead = $leads->quickAdd(['name' => 'Dr. Ercan', 'phone' => '0552 818 71 48'], $this->admin);

        $page = Livewire::test(SalesPage::class)->set('tab', 'adaylar')->call('openDialog', 'touch', $lead->id)
            ->set('touch.body', '')->set('touch.date', '')->call('saveTouch')->assertHasErrors(['touch.body', 'touch.date']);

        $page->set('touch.body', 'Fiyatı sordu, ortağıyla konuşacak')->set('touch.next', 'Teklifi hatırlat')->set('touch.date', '2026-11-10')
            ->call('saveTouch')->assertHasNoErrors()->assertSet('dialog', '');
        $lead = $lead->fresh();
        $this->assertSame('talking', $lead->stage);
        $this->assertSame('Teklifi hatırlat', $lead->next_step);
        $this->assertSame('2026-11-10', $lead->next_step_at->timezone('Europe/Istanbul')->toDateString());
        $this->assertNotNull($lead->last_contact_at);
        $this->assertTrue($lead->events()->where('kind', 'call')->exists());
        $this->assertSame(0, $lead->stageDays());

        // Closing from the touch dialog takes a reason instead of a date.
        Livewire::test(SalesPage::class)->call('openDialog', 'touch', $lead->id)->set('touch.channel', 'in_person')
            ->set('touch.body', 'Başka ajansla devam ediyor')->set('touch.close', 'other_agency')->call('saveTouch')->assertHasNoErrors();
        $lead = $lead->fresh();
        $this->assertSame('lost', $lead->stage);
        $this->assertSame('other_agency', $lead->lost_reason);
        $this->assertTrue($lead->events()->where('kind', 'meeting')->exists());
    }

    public function test_closing_always_asks_why(): void
    {
        $leads = app(SalesLeads::class);
        $lead = $leads->quickAdd(['name' => 'Kemal Bey', 'phone' => '0535 000 00 01'], $this->admin);

        // Bugün's "Kapat" opens the reason dialog instead of closing as "Sessiz kaldı".
        $page = Livewire::test(SalesPage::class)->call('setStage', $lead->id, 'lost')->assertSet('dialog', 'close');
        $this->assertSame('new', $lead->fresh()->stage);
        $page->call('saveClose')->assertHasErrors('closeReason');
        $page->set('closeReason', 'price')->set('closeNote', 'Bütçe yok')->call('saveClose')->assertHasNoErrors();
        $this->assertSame('price', $lead->fresh()->lost_reason);

        $other = $leads->quickAdd(['name' => 'Bülent Bey', 'phone' => '0535 000 00 02'], $this->admin);
        Livewire::test(LeadCardPage::class, ['lead' => (string) $other->id])->call('setStage', 'lost')->assertHasErrors('lostReason');
        $this->assertSame('new', $other->fresh()->stage);
    }

    public function test_note_and_offer_are_set_from_the_row_and_the_results_sum_the_offers(): void
    {
        $leads = app(SalesLeads::class);
        $lead = $leads->quickAdd(['name' => 'Dr. Aslı', 'phone' => '0536 000 00 03'], $this->admin);

        Livewire::test(SalesPage::class)->set('tab', 'adaylar')
            ->call('openDialog', 'note', $lead->id)->set('noteText', 'Karar verici eşi')->call('saveNote')->assertHasNoErrors()
            ->call('openDialog', 'offer', $lead->id)->set('offerAmount', '18.000')->set('offerService', 'seo')->call('saveOffer')->assertHasNoErrors();
        $lead = $lead->fresh();
        $this->assertSame('Karar verici eşi', $lead->notes);
        $this->assertSame(18000, $lead->offer_amount);
        $this->assertTrue($lead->offer_confirmed);

        $page = Livewire::test(SalesPage::class)->set('tab', 'adaylar')->set('view', 'teklif');
        $this->assertSame(18000, $page->viewData('offerTotal'));
        Livewire::test(SalesPage::class)->set('tab', 'sonuclar')->assertSee('Teklifte bekleyen')->assertSee('18.000 ₺');
    }
}
