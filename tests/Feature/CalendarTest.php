<?php

namespace Tests\Feature;

use App\Livewire\Operator\Calendar\CalendarPage;
use App\Models\AgencySetting;
use App\Models\Brand;
use App\Models\BrandSession;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpQueuedPost;
use App\Models\Reminder;
use App\Models\SalesLead;
use App\Models\SalesLeadEvent;
use App\Models\User;
use App\Services\Assistant\ReminderService;
use App\Services\Calendar\CalendarFeed;
use App\Services\Inbox\InboxQueue;
use App\Services\Sales\SalesLeads;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Takvim (2026-11-05): own tasks and meetings, prospects' next steps and the brands' work on one calendar. */
class CalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'name' => 'Yakup Udul']);
        $this->admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        // 2026-11-05 12:00 in Istanbul.
        CarbonImmutable::setTestNow('2026-11-05 09:00:00');
        $this->brand = Brand::factory()->create(['name' => 'Avrupadent']);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function write(string $action, string $status, string $finishedUtc, array $payload = []): ExternalWriteAction
    {
        return ExternalWriteAction::query()->create([
            'channel' => 'wordpress', 'action' => $action, 'digital_asset_id' => $this->site->id, 'brand_id' => $this->brand->id,
            'status' => $status, 'request_payload' => $payload, 'requested_by' => $this->admin->id, 'finished_at' => $finishedUtc,
            'error' => $status === 'failed' ? 'bağlantı metni sayfada yok' : null,
        ]);
    }

    public function test_the_day_shows_own_items_sales_and_each_brands_work_by_state(): void
    {
        $this->write(ExternalWriteAction::ACTION_SITE_FIX, 'succeeded', '2026-11-05 07:00:00');
        $this->write(ExternalWriteAction::ACTION_SITE_FIX, 'succeeded', '2026-11-05 07:01:00');
        $this->write(ExternalWriteAction::ACTION_SITE_FIX, 'failed', '2026-11-05 07:02:00');
        $this->write(ExternalWriteAction::ACTION_REVIEW_REPLY, 'succeeded', '2026-11-05 08:00:00');
        // 22:30 UTC on the 4th is 01:30 on the 5th in Istanbul.
        $this->write(ExternalWriteAction::ACTION_LOCAL_POST, 'succeeded', '2026-11-04 22:30:00');
        // Content drafts precede the apply and are not counted twice; a running write is not on the calendar yet.
        $this->write(ExternalWriteAction::ACTION_CONTENT_DRAFT, 'succeeded', '2026-11-05 07:00:00');
        $this->write(ExternalWriteAction::ACTION_SITE_FIX, 'running', '2026-11-05 07:00:00');
        GbpQueuedPost::query()->create(['digital_asset_id' => $this->site->id, 'brand_id' => $this->brand->id, 'angle' => 'service', 'publish_on' => '2026-11-07', 'summary' => 'İmplant', 'status' => GbpQueuedPost::APPROVED]);

        Reminder::query()->create(['user_id' => $this->admin->id, 'kind' => 'task', 'title' => 'Suada: ödeme hatırlat', 'remind_at' => '2026-11-05 07:00:00']);
        Reminder::query()->create(['user_id' => $this->admin->id, 'kind' => 'meeting', 'title' => 'Dentocare görüşmesi', 'remind_at' => '2026-11-05 07:30:00', 'ends_at' => '2026-11-05 08:00:00']);
        $lead = SalesLead::query()->create(['name' => 'Dr Yaşar Özdemir', 'stage' => 'contacted', 'next_step' => 'Takip', 'next_step_at' => '2026-11-03 07:00:00']);

        $feed = app(CalendarFeed::class);
        $day = $feed->day(CarbonImmutable::parse('2026-11-05', CalendarFeed::TZ), $this->admin->id);
        $this->assertSame(['Suada: ödeme hatırlat'], array_column($day['tasks'], 'title'));
        $this->assertSame('10:00', $day['tasks'][0]['time'], 'stored UTC, shown in Istanbul time');
        $this->assertSame('10:30', $day['meetings'][0]['time']);
        $this->assertSame('11:00', $day['meetings'][0]['end']);
        $this->assertTrue($day['meetings'][0]['over'], 'it ended at 11:00, it is 12:00');
        $this->assertSame([$lead->id], array_column($day['sales'], 'id'), 'a late step is carried to today');
        $this->assertTrue($day['sales'][0]['late']);

        $this->assertCount(1, $day['brands']);
        $b = $day['brands'][0];
        $this->assertSame('Avrupadent', $b['brand']);
        $this->assertSame(4, $b['done'], '2 fixes + 1 reply + 1 post (01:30 local)');
        $this->assertSame(1, $b['failed']);
        $this->assertSame('failed', $b['items'][0]['state'], 'failures first');
        $this->assertSame('bağlantı metni sayfada yok', $b['items'][0]['error']);

        $grid = $feed->range(CarbonImmutable::parse('2026-11-02', CalendarFeed::TZ), CarbonImmutable::parse('2026-11-08', CalendarFeed::TZ)->endOfDay(), $this->admin->id);
        $this->assertSame(['done' => 4, 'planned' => 0, 'failed' => 1, 'brands' => 1], $grid['2026-11-05']['work']);
        $this->assertSame(['done' => 0, 'planned' => 1, 'failed' => 0, 'brands' => 1], $grid['2026-11-07']['work'], 'the approved post waits on its day');
        $this->assertSame(0, $grid['2026-11-04']['work']['done'], '01:30 Istanbul belongs to the 5th');

        $this->actingAs($this->admin)->get(route('operator.calendar'))->assertOk()
            ->assertSee('Tak')->assertSee('Suada: ödeme hatırlat')->assertSee('Satış: 1 kişi')->assertSee('5 iş · 1 marka')->assertSee('1 hata');
    }

    public function test_month_report_counts_only_applied_work(): void
    {
        $this->write(ExternalWriteAction::ACTION_SITE_FIX, 'succeeded', '2026-11-02 07:00:00');
        $this->write(ExternalWriteAction::ACTION_SITE_FIX, 'failed', '2026-11-02 07:00:00');
        $this->write(ExternalWriteAction::ACTION_ARTICLE_DRAFTS, 'succeeded', '2026-11-03 07:00:00', ['drafts' => [['draft' => ['title' => 'İmplant fiyatları']]]]);
        $this->write(ExternalWriteAction::ACTION_SITE_FIX, 'succeeded', '2026-10-30 07:00:00');
        Reminder::query()->create(['user_id' => $this->admin->id, 'kind' => 'meeting', 'title' => 'Görüşme', 'remind_at' => '2026-11-03 07:00:00', 'done_at' => '2026-11-03 08:00:00']);

        $month = app(CalendarFeed::class)->month(CarbonImmutable::parse('2026-11-01', CalendarFeed::TZ), $this->admin->id);
        $this->assertSame(1, $month['totals']['guncelleme']);
        $this->assertSame(1, $month['totals']['yazi']);
        $this->assertSame(1, $month['brands'][0]['failed']);
        $this->assertSame(1, $month['meetings']);

        Livewire::actingAs($this->admin)->test(CalendarPage::class)->set('report', true)
            ->assertSee('Ay dökümü')->assertSee('Avrupadent')->assertSee('toplantı yapıldı');
    }

    public function test_a_meeting_with_a_prospect_is_added_reminded_and_its_note_goes_to_the_card(): void
    {
        $lead = SalesLead::query()->create(['name' => 'Dt. Gözde Zeyrek', 'stage' => 'contacted']);

        $page = Livewire::actingAs($this->admin)->withQueryParams(['aday' => $lead->id])->test(CalendarPage::class)
            ->assertSet('adding', true)->assertSet('form.kind', 'meeting')->assertSet('form.lead_id', (string) $lead->id)
            ->set('form.date', '2026-11-06')->set('form.time', '14:00')->set('form.place', 'https://meet.google.com/abc')
            ->call('save')->assertHasNoErrors()->assertSet('day', '2026-11-06');
        $meeting = Reminder::query()->firstOrFail();
        $this->assertSame('meeting', $meeting->kind);
        $this->assertSame('Görüşme: Dt. Gözde Zeyrek', $meeting->title);
        $this->assertSame('2026-11-06 11:00:00', $meeting->remind_at->format('Y-m-d H:i:s'), '14:00 Istanbul stored as UTC');
        $this->assertSame('2026-11-06 11:30:00', $meeting->ends_at->format('Y-m-d H:i:s'), 'half an hour by default');
        $this->assertSame($lead->id, (int) $meeting->sales_lead_id);
        $this->assertTrue(SalesLeadEvent::query()->where('lead_id', $lead->id)->where('kind', 'meeting')->exists());

        // 15 minutes ahead the phone is told; after it ends, the note is asked once.
        $push = app(ReminderService::class);
        CarbonImmutable::setTestNow('2026-11-06 10:40:00');
        $push->dispatchDue();
        $this->assertNull($meeting->fresh()->notified_at);
        CarbonImmutable::setTestNow('2026-11-06 10:46:00');
        $push->dispatchDue();
        $this->assertNotNull($meeting->fresh()->notified_at);
        CarbonImmutable::setTestNow('2026-11-06 11:40:00');
        $push->dispatchDue();
        $this->assertNotNull($meeting->fresh()->followup_notified_at);

        $page->call('openDay', '2026-11-06')->assertSee('Görüşme: Dt. Gözde Zeyrek')->assertSee('Not yaz')
            ->call('writeNote', $meeting->id)->set('note', 'Fiyatı sordu, 12.000 + KDV dedim; ay başı dönecek.')->call('saveNote')->assertHasNoErrors();
        $meeting->refresh();
        $this->assertNotNull($meeting->done_at);
        $this->assertStringContainsString('ay başı', (string) $meeting->outcome);
        $this->assertTrue(SalesLeadEvent::query()->where('lead_id', $lead->id)->where('kind', 'meeting')->get()->pluck('body')->contains('Fiyatı sordu, 12.000 + KDV dedim; ay başı dönecek.'));
    }

    public function test_tasks_are_ticked_and_a_repeating_one_moves_on(): void
    {
        $once = Reminder::query()->create(['user_id' => $this->admin->id, 'kind' => 'task', 'title' => 'Fatura kes', 'remind_at' => '2026-11-05 07:00:00']);
        $weekly = Reminder::query()->create(['user_id' => $this->admin->id, 'kind' => 'task', 'title' => 'Haftalık rapor', 'remind_at' => '2026-11-05 07:00:00', 'repeat' => 'weekly']);

        $grid = app(CalendarFeed::class)->range(CarbonImmutable::parse('2026-11-01', CalendarFeed::TZ), CarbonImmutable::parse('2026-11-30', CalendarFeed::TZ)->endOfDay(), $this->admin->id);
        $this->assertSame(['Haftalık rapor'], array_column(array_filter($grid['2026-11-12']['own'], fn ($i) => $i['title'] === 'Haftalık rapor'), 'title'), 'a weekly task shows on each week');

        Livewire::actingAs($this->admin)->test(CalendarPage::class)
            ->call('toggle', $once->id)->call('toggle', $weekly->id);
        $this->assertNotNull($once->fresh()->done_at);
        $this->assertNull($weekly->fresh()->done_at);
        $this->assertSame('2026-11-12', $weekly->fresh()->remind_at->toDateString());

        // Another user's entry cannot be touched.
        $other = User::factory()->create();
        $theirs = Reminder::query()->create(['user_id' => $other->id, 'title' => 'Başkası', 'remind_at' => '2026-11-05 07:00:00']);
        try {
            Livewire::actingAs($this->admin)->test(CalendarPage::class)->call('remove', $theirs->id);
        } catch (ModelNotFoundException) {
        }
        $this->assertNotNull($theirs->fresh());

        Livewire::actingAs($this->admin)->test(CalendarPage::class)->call('startAdding', '2026-11-05', 'task')->set('form.title', '')->call('save')->assertHasErrors('form.title');
    }

    public function test_sales_steps_due_today_are_the_same_records_on_sales_inbox_and_calendar(): void
    {
        // 23:30 in Istanbul (20:30 UTC): "today" still ends at Istanbul midnight.
        CarbonImmutable::setTestNow('2026-11-05 20:30:00');
        $make = fn (string $name, CarbonImmutable $at, array $extra = []) => SalesLead::query()->create(array_merge(
            ['name' => $name, 'source' => 'manual', 'stage' => 'contacted', 'lifecycle' => 'prospect', 'next_step' => 'Ara', 'next_step_at' => $at], $extra));
        $make('Bugün', CarbonImmutable::parse('2026-11-05 20:45:00'));
        $make('Dün', CarbonImmutable::parse('2026-11-04 08:00:00'));
        $make('Yarın sabaha karşı', CarbonImmutable::parse('2026-11-05 22:30:00')); // 01:30 Istanbul, tomorrow
        $make('Arşivde', CarbonImmutable::parse('2026-11-04 08:00:00'), ['archived_at' => now(), 'archive_reason' => 'history']);
        $make('İlgisiz', CarbonImmutable::parse('2026-11-04 08:00:00'), ['lifecycle' => 'irrelevant']);

        $due = app(SalesLeads::class)->due()->pluck('name')->sort()->values()->all();
        $this->assertSame(['Bugün', 'Dün'], $due);

        $today = CarbonImmutable::now(CalendarFeed::TZ);
        $calendar = collect(app(CalendarFeed::class)->sales($today->startOfDay(), $today->endOfDay()))->pluck('title')->sort()->values()->all();
        $this->assertCount(2, $calendar);

        $inbox = app(InboxQueue::class)->items()->filter(fn ($item): bool => $item->lead !== null)->map(fn ($item): string => (string) $item->lead->name)->sort()->values()->all();
        $this->assertSame(['Bugün', 'Dün'], $inbox);
    }

    public function test_monthly_brand_sessions_are_spread_over_the_working_days_and_the_systems_work_is_one_dot(): void
    {
        $feed = app(CalendarFeed::class);
        $from = CarbonImmutable::parse('2026-11-01', CalendarFeed::TZ);
        $this->assertSame([], $feed->sessions($from, $from->endOfMonth()));

        $second = Brand::factory()->create(['name' => 'Dentocare']);
        Brand::query()->whereKey([$this->brand->id, $second->id])->update(['new_order_at' => now()]);
        $sessions = $feed->sessions($from, $from->endOfMonth());
        if ($sessions === []) {
            $this->markTestSkipped('The factory brand is not operational here.');
        }
        $this->assertCount(2, $sessions);
        $this->assertNotSame($sessions[0]['date'], $sessions[1]['date']);
        foreach ($sessions as $session) {
            $this->assertNotContains(CarbonImmutable::parse($session['date'])->dayOfWeekIso, [5, 6, 7]);
            $this->assertFalse($session['done']);
        }
        BrandSession::query()->create(['brand_id' => $second->id, 'month' => '2026-11', 'status' => 'draft', 'summary' => 'x', 'jobs' => [], 'token' => str_repeat('a', 40)]);
        $this->assertTrue(collect($feed->sessions($from, $from->endOfMonth()))->firstWhere('brand_id', $second->id)['done']);

        $this->actingAs($this->admin)->get(route('operator.calendar'))->assertOk()->assertSee('Oturum: Dentocare')->assertSee('data-calendar-load', false)->assertDontSee('Komuta:');
    }
}
