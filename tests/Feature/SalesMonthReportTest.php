<?php

namespace Tests\Feature;

use App\Livewire\Operator\Calendar\CalendarPage;
use App\Livewire\Operator\Sales\SalesPage;
use App\Models\Customer;
use App\Models\Reminder;
use App\Models\SalesLead;
use App\Models\SalesLeadEvent;
use App\Models\User;
use App\Services\Calendar\CalendarIcs;
use App\Services\Sales\SalesLeads;
use App\Services\Sales\SalesMonthReport;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Satış 4. hafta (2026-11-05): the month report, what wins and losses share, and the read-only Google Takvim feed. */
class SalesMonthReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        CarbonImmutable::setTestNow('2026-11-20 08:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_the_month_report_counts_how_far_cards_got_what_was_won_and_how_fast_we_wrote(): void
    {
        $leads = app(SalesLeads::class);
        $customer = Customer::factory()->create(['monthly_fee' => 14000]);

        $won = SalesLead::query()->create(['name' => 'Dr Ercan Akpınar', 'source' => 'whatsapp', 'heard_from' => 'google', 'stage' => 'priced', 'created_at' => '2026-11-02 08:00:00']);
        $won->forceFill(['customer_id' => $customer->id])->save();
        $leads->setStage($won, 'won', null, $this->admin);
        SalesLeadEvent::query()->create(['lead_id' => $won->id, 'kind' => 'message_out', 'body' => 'Merhaba', 'happened_at' => '2026-11-02 10:00:00']);

        $lost = SalesLead::query()->create(['name' => 'Dt. Gözde Zeyrek', 'source' => 'form', 'stage' => 'meeting', 'created_at' => '2026-11-03 08:00:00']);
        $leads->setStage($lost, 'lost', 'price', $this->admin);
        $this->assertSame('meeting', $lost->fresh()->peak_stage, 'a lost card keeps how far it got');

        SalesLead::query()->create(['name' => 'Yeni klinik', 'source' => 'form', 'stage' => 'new', 'created_at' => '2026-11-10 08:00:00']);
        SalesLead::query()->create(['name' => 'Geçen ay', 'source' => 'phone', 'stage' => 'new', 'created_at' => '2026-10-10 08:00:00']);
        Reminder::query()->create(['user_id' => $this->admin->id, 'kind' => 'meeting', 'title' => 'Görüşme', 'sales_lead_id' => $lost->id, 'remind_at' => '2026-11-05 07:00:00', 'outcome' => 'Fiyat yüksek dedi']);

        $report = app(SalesMonthReport::class)->month(CarbonImmutable::parse('2026-11-01', 'Europe/Istanbul'));

        $this->assertSame(3, $report['opened']);
        $this->assertSame(['form' => 2, 'whatsapp' => 1], $report['by_source']);
        $this->assertSame(['contacted' => 2, 'talking' => 2, 'meeting' => 2, 'priced' => 1, 'won' => 1], $report['funnel']);
        $this->assertSame(1, $report['won']);
        $this->assertSame(14000.0, $report['revenue']);
        $this->assertSame(['price' => 1], $report['lost_reasons']);
        $this->assertSame(1, $report['meetings']);
        $this->assertSame(1, $report['meetings_done']);
        $this->assertSame(2.0, $report['reply_hours']);

        $patterns = app(SalesMonthReport::class)->patterns();
        $this->assertSame(1, $patterns['wins']);
        $this->assertFalse($patterns['ready'], 'no rules before 30 wins');
        $this->assertSame(['Görüşmeden sonra' => 1], $patterns['lost_at']);
        $this->assertNull($patterns['groups']['Kanal'][0]['rate'], 'no rate for a group under 5 cards');

        $this->actingAs($this->admin);
        Livewire::test(SalesPage::class)->set('tab', 'sonuclar')
            ->assertSee('Ay raporu')->assertSee('Kasım 2026')->assertSee('Dr Ercan Akpınar')->assertSee('+14.000 ₺/ay')
            ->assertSee('kazanılan kart birikmeden buradan kural çıkarılmaz')
            ->set('month', '2026-10')->assertSee('Ekim 2026');
    }

    public function test_google_takvim_reads_meetings_tasks_and_prospects_from_a_secret_address_that_can_be_renewed(): void
    {
        Reminder::query()->create(['user_id' => $this->admin->id, 'kind' => 'meeting', 'title' => 'Dentocare görüşmesi', 'place' => 'Bornova, İzmir', 'remind_at' => '2026-11-21 07:30:00', 'ends_at' => '2026-11-21 08:15:00']);
        Reminder::query()->create(['user_id' => $this->admin->id, 'kind' => 'task', 'title' => 'Fatura kes', 'all_day' => true, 'remind_at' => '2026-11-22 00:00:00']);
        Reminder::query()->create(['user_id' => User::factory()->create()->id, 'kind' => 'task', 'title' => 'Başkasının işi', 'remind_at' => '2026-11-21 07:00:00']);
        SalesLead::query()->create(['name' => 'Dr Yaşar Özdemir', 'stage' => 'contacted', 'next_step' => 'Takip', 'next_step_at' => '2026-11-24 07:00:00']);

        $this->get('/takvim/abone/'.str_repeat('a', 48).'.ics')->assertNotFound();

        $this->actingAs($this->admin);
        $page = Livewire::test(CalendarPage::class)->call('showFeed')->assertSeeHtml('data-calendar-feed-url');
        $url = (string) $page->get('feedUrl');
        $this->assertStringContainsString('/takvim/abone/', $url);
        auth()->logout();

        $ics = $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('SUMMARY:Dentocare görüşmesi', $ics);
        $this->assertStringContainsString("DTSTART:20261121T073000Z\r\nDTEND:20261121T081500Z", $ics);
        $this->assertStringContainsString('LOCATION:Bornova\, İzmir', $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261122', $ics);
        $this->assertStringContainsString('SUMMARY:Satış: Takip · Dr Yaşar Özdemir', $ics);
        $this->assertStringNotContainsString('Başkasının işi', $ics);
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }

        $this->actingAs($this->admin);
        $fresh = (string) Livewire::test(CalendarPage::class)->call('renewFeed')->get('feedUrl');
        $this->assertNotSame($url, $fresh);
        auth()->logout();
        $this->get(parse_url($url, PHP_URL_PATH))->assertNotFound();
        $this->get(parse_url($fresh, PHP_URL_PATH))->assertOk();
        $this->assertSame($this->admin->id, CalendarIcs::userFor(basename(parse_url($fresh, PHP_URL_PATH), '.ics'))?->id);
    }
}
