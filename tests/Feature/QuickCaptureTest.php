<?php

namespace Tests\Feature;

use App\Livewire\Operator\Calendar\CalendarPage;
use App\Models\AgencySetting;
use App\Models\Brand;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Assistant\ReminderService;
use App\Services\Calendar\CalendarIcs;
use App\Services\Calendar\QuickCapture;
use App\Support\OperatorMenu;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

/** Yapılacaklar + hızlı not (yakup 2026-11-10): one line from Takvim, the Chrome extension, the phone or Moxi. */
class QuickCaptureTest extends TestCase
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
        // Tuesday 2026-11-10 19:21 in Istanbul.
        CarbonImmutable::setTestNow('2026-11-10 16:21:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: ?string, 4: bool}> */
    public static function lines(): array
    {
        return [
            'tomorrow with hour' => ["yarın 10'da Şaban Bey'i ara", 'task', "Şaban Bey'i ara", '2026-11-11 10:00', false],
            'weekday, all day' => ['cuma teklif gönder', 'task', 'Teklif gönder', '2026-11-13 09:00', true],
            'cumartesi is not cuma' => ['cumartesi günü hesap', 'task', 'Hesap', '2026-11-14 09:00', true],
            'note' => ["not: Eyüp Bey 3 klinik getirecek\nfiyat 18.000", 'note', 'Eyüp Bey 3 klinik getirecek', null, false],
            'meeting' => ['toplantı: perşembe 14:00 Dentocare', 'meeting', 'Dentocare', '2026-11-12 14:00', false],
            'no date stays open' => ['Suada: ödeme hatırlat', 'task', 'Suada: ödeme hatırlat', null, false],
            'afternoon hour' => ["3'te Buşra'yı ara", 'task', "Buşra'yı ara", '2026-11-11 15:00', false],
            'month name' => ['15 kasımda fatura kes', 'task', 'Fatura kes', '2026-11-15 09:00', true],
            'dotted date' => ['15.11 fatura', 'task', 'Fatura', '2026-11-15 09:00', true],
            'dotted time' => ["14.30'da ara", 'task', 'Ara', '2026-11-11 14:30', false],
            'next week' => ['haftaya salı Avrupadent rapor', 'task', 'Avrupadent rapor', '2026-11-17 09:00', true],
            'year is not an hour' => ["2025'te 5 makale yaz", 'task', "2025'te 5 makale yaz", null, false],
            'days later' => ['3 gün sonra kontrol et', 'task', 'Kontrol et', '2026-11-13 09:00', true],
            'evening' => ["akşam 8'de spor", 'task', 'Spor', '2026-11-10 20:00', false],
            'meeting without hour is a task' => ['toplantı: cuma Dentocare', 'task', 'Dentocare', '2026-11-13 09:00', true],
        ];
    }

    #[DataProvider('lines')]
    public function test_a_line_becomes_a_task_reminder_meeting_or_note(string $text, string $kind, string $title, ?string $at, bool $allDay): void
    {
        $parsed = QuickCapture::parse($text);

        $this->assertSame($kind, $parsed['kind']);
        $this->assertSame($title, $parsed['title']);
        $this->assertSame($at, $parsed['at']?->format('Y-m-d H:i'));
        $this->assertSame($allDay, $parsed['all_day']);
    }

    public function test_takvim_box_adds_an_undated_todo_that_stays_until_ticked(): void
    {
        $brand = Brand::factory()->create(['name' => 'Suada']);

        Livewire::actingAs($this->admin)->test(CalendarPage::class)
            ->set('quick', 'Suada: ödeme hatırlat')->call('quickAdd')
            ->assertHasNoErrors()->assertSet('quick', '')
            ->assertSee('Tarihsiz')->assertSee('Suada: ödeme hatırlat');

        $row = Reminder::query()->sole();
        $this->assertNull($row->remind_at);
        $this->assertSame($brand->id, $row->brand_id);

        // A week later it is still there.
        CarbonImmutable::setTestNow('2026-11-17 08:00:00');
        $this->assertSame(1, app(QuickCapture::class)->board($this->admin->id)['count']);

        Livewire::actingAs($this->admin)->test(CalendarPage::class)->call('toggle', $row->id);
        $this->assertNotNull($row->refresh()->done_at);
        $this->assertSame(0, app(QuickCapture::class)->board($this->admin->id)['count']);
    }

    public function test_a_late_dated_task_stays_and_notes_are_listed(): void
    {
        Reminder::query()->create(['user_id' => $this->admin->id, 'kind' => 'task', 'title' => 'Logo gönder', 'remind_at' => '2026-11-07 06:00:00', 'repeat' => 'none']);
        app(QuickCapture::class)->capture($this->admin, "not: Eyüp Bey 3 klinik getirecek\nfiyat 18.000");

        $board = app(QuickCapture::class)->board($this->admin->id);
        $this->assertSame('3 gün gecikti', $board['late'][0]['late']);
        $this->assertSame('Eyüp Bey 3 klinik getirecek', $board['notes'][0]['title']);

        Livewire::actingAs($this->admin)->test(CalendarPage::class)->assertSee('Geciken')->assertSee('Logo gönder')->assertSee('Eyüp Bey 3 klinik getirecek');
        // Notes and undated items never reach the calendar grid or the feed.
        $this->get(route('calendar.feed', CalendarIcs::token($this->admin)))->assertOk()->assertDontSee('Eyüp Bey');
    }

    public function test_the_extension_api_needs_the_key_and_saves_with_the_page(): void
    {
        $this->getJson('/api/hizli-not')->assertUnauthorized();
        $this->postJson('/api/hizli-not', ['text' => 'x'], ['Authorization' => 'Bearer '.str_repeat('a', 48)])->assertUnauthorized();

        $key = QuickCapture::token($this->admin);
        $auth = ['Authorization' => 'Bearer '.$key];

        $this->postJson('/api/hizli-not', ['text' => "yarın 10'da Şaban Bey'i ara", 'url' => 'https://klinikeregli.com/hakkimizda'], $auth)
            ->assertCreated()->assertJsonPath('message', "Yarın 10:00'da hatırlatılır.");
        $row = Reminder::query()->sole();
        $this->assertSame('https://klinikeregli.com/hakkimizda', $row->url);
        $this->assertSame('2026-11-11 07:00', $row->remind_at->utc()->format('Y-m-d H:i'));

        // A double Enter stores it once.
        $this->postJson('/api/hizli-not', ['text' => "yarın 10'da Şaban Bey'i ara"], $auth)->assertCreated();
        $this->assertSame(1, Reminder::query()->count());

        $this->postJson('/api/hizli-not', ['text' => '  '], $auth)->assertStatus(422);
        $this->postJson('/api/hizli-not', ['text' => 'Teklif yaz', 'url' => 'javascript:alert(1)'], $auth)->assertCreated();
        $this->assertNull(Reminder::query()->where('title', 'Teklif yaz')->value('url'));

        $this->getJson('/api/hizli-not', $auth)->assertOk()->assertJsonPath('open.0.title', 'Teklif yaz');

        $id = Reminder::query()->where('title', 'Teklif yaz')->value('id');
        $this->postJson('/api/hizli-not/'.$id.'/tik', [], $auth)->assertOk()->assertJsonPath('done', true);
        $this->postJson('/api/hizli-not/'.$id.'/tik', [], $auth)->assertOk()->assertJsonPath('done', false);

        // Someone else's key cannot tick it; a renewed key cuts the old one off.
        $other = User::factory()->create(['is_active' => true]);
        $this->postJson('/api/hizli-not/'.$id.'/tik', [], ['Authorization' => 'Bearer '.QuickCapture::token($other)])->assertNotFound();
        QuickCapture::token($this->admin, true);
        $this->getJson('/api/hizli-not', $auth)->assertUnauthorized();
    }

    public function test_the_extension_downloads_with_the_panel_address_and_key(): void
    {
        $response = $this->actingAs($this->admin)->get(route('operator.calendar.extension'))->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file) === true);
        $config = (string) $zip->getFromName('moxdop-hizli-not/config.js');
        $manifest = json_decode((string) $zip->getFromName('moxdop-hizli-not/manifest.json'), true);
        $zip->close();

        $this->assertStringContainsString((string) $this->admin->refresh()->capture_token, $config);
        $this->assertSame('_execute_action', array_key_first($manifest['commands']));
        $this->assertStringNotContainsString('__HOST__', json_encode($manifest['host_permissions']));
    }

    public function test_morning_push_lists_open_todos_once_a_day(): void
    {
        Http::fake();
        AgencySetting::query()->first()->forceFill(['push_ntfy_url' => 'https://ntfy.example/moxdop'])->save();
        app(QuickCapture::class)->capture($this->admin, 'Teklif yaz');
        app(QuickCapture::class)->capture($this->admin, 'not: sadece not');
        Reminder::query()->create(['user_id' => $this->admin->id, 'kind' => 'task', 'title' => 'Dünden kalan', 'remind_at' => '2026-11-09 06:00:00', 'repeat' => 'none']);

        $service = app(ReminderService::class);
        $this->assertSame(0, $service->morningSummary(CarbonImmutable::parse('2026-11-11 08:30', 'Europe/Istanbul')));
        $this->assertSame(1, $service->morningSummary(CarbonImmutable::parse('2026-11-11 09:01', 'Europe/Istanbul')));
        $this->assertSame(0, $service->morningSummary(CarbonImmutable::parse('2026-11-11 09:02', 'Europe/Istanbul')));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request->body(), 'Teklif yaz') && str_contains($request->body(), 'Dünden kalan') && ! str_contains($request->body(), 'sadece not'));
    }

    public function test_takvim_menu_counts_open_todos(): void
    {
        app(QuickCapture::class)->capture($this->admin, 'Teklif yaz');
        app(QuickCapture::class)->capture($this->admin, 'not: sadece not');
        app(QuickCapture::class)->capture($this->admin, 'pazartesi logo');

        $this->assertSame(1, OperatorMenu::computeBadges()['operator.calendar']['count']);
    }
}
