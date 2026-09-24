<?php

namespace Tests\Feature\Assistant;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Assistant\TodayPanel;
use App\Models\AgencySetting;
use App\Models\AssetAlert;
use App\Models\AssetRenewal;
use App\Models\Brand;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Prospect;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Assistant\ReminderService;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

final class TodayRemindersCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-01 07:00:00', 'UTC'));
        $this->seed(RoleAndPermissionSeeder::class);
        $this->user = User::factory()->create(['is_active' => true, 'timezone' => 'Europe/Istanbul']);
        $this->user->assignRole(Roles::ADMIN);
        $this->actingAs($this->user);
        $this->customer = Customer::factory()->create(['status' => CustomerStatus::Active, 'name' => 'Atlas Sağlık']);
        $this->brand = Brand::factory()->create(['customer_id' => $this->customer->id, 'name' => 'Atlas Dental']);
        AgencySetting::query()->create(['agency_name' => 'Moximu', 'push_ntfy_url' => 'https://ntfy.sh/t', 'push_min_severity' => 'critical']);
        Http::fake(['ntfy.sh/*' => Http::response('{}')]);
    }

    public function test_due_reminder_is_pushed_once_and_repeating_one_moves_forward(): void
    {
        $once = Reminder::query()->create(['user_id' => $this->user->id, 'title' => 'Faturayı kes', 'remind_at' => now()->subMinute(), 'customer_id' => $this->customer->id]);
        $monthly = Reminder::query()->create(['user_id' => $this->user->id, 'title' => 'Aylık rapor', 'remind_at' => now()->subMinute(), 'repeat' => 'monthly']);
        Reminder::query()->create(['user_id' => $this->user->id, 'title' => 'Sonra', 'remind_at' => now()->addHour()]);

        $this->assertSame(2, app(ReminderService::class)->dispatchDue(), 'reminders ignore the minimum severity');
        $this->assertSame(0, app(ReminderService::class)->dispatchDue());
        Http::assertSent(fn ($r): bool => str_contains($r->body(), 'Atlas Sağlık'));

        app(ReminderService::class)->complete($monthly->fresh());
        app(ReminderService::class)->complete($once->fresh());
        $this->assertSame('2026-11-01', $monthly->fresh()->remind_at->toDateString());
        $this->assertNull($monthly->fresh()->notified_at);
        $this->assertNotNull($once->fresh()->done_at);
    }

    public function test_today_panel_shows_what_needs_attention_and_adds_reminders_in_local_time(): void
    {
        $site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'domain' => 'atlasdis.com']);
        DB::table('uptime_states')->insert(['digital_asset_id' => $site->id, 'state' => 'down', 'consecutive_failures' => 3, 'down_since' => now()->subMinutes(20), 'last_error' => 'HTTP 503']);
        AssetRenewal::query()->create(['brand_id' => $this->brand->id, 'kind' => 'hosting', 'label' => 'atlasdis hosting', 'expires_on' => now()->addDays(5), 'charge_amount' => 3000]);
        AssetAlert::query()->create(['digital_asset_id' => $site->id, 'brand_id' => $this->brand->id, 'alert_key' => 'x', 'kind' => 'conversions_stopped', 'severity' => 'critical',
            'title' => 'Dönüşüm gelmiyor', 'message' => 'm', 'first_detected_at' => now(), 'last_detected_at' => now()]);
        $prospect = Prospect::factory()->create(['company_name' => 'Yeni Klinik', 'next_follow_up_on' => now()->toDateString(), 'next_step' => 'Teklif gönder']);
        $conversation = DB::table('whatsapp_conversations')->insertGetId(['integration_id' => CoreIntegration::factory()->create(['provider' => 'whatsapp'])->id, 'phone_number_id' => '1', 'contact_id' => '905551112233',
            'contact_name' => 'Ayşe Hanım', 'last_message_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('whatsapp_messages')->insert(['conversation_id' => $conversation, 'message_id' => 'm1', 'direction' => 'incoming', 'message_type' => 'text', 'body' => 'Merhaba', 'sent_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()]);

        Livewire::test(TodayPanel::class)
            ->assertSee('Erişilemeyen siteler')->assertSee('atlasdis.com')
            ->assertSee('atlasdis hosting')->assertSee('5 gün')
            ->assertSee('Ayşe Hanım')->assertSee('WhatsApp cevap bekliyor')
            ->assertSee('Yeni Klinik')->assertSee('Teklif gönder')
            ->assertSee('Atlas Sağlık')->assertSee('Dönüşüm gelmiyor')
            ->set('title', 'Müşteriyi ara')->set('when', '2026-10-01T15:00')->call('addReminder')
            ->assertSee('Hatırlatıcı eklendi')
            ->call('followedUp', $prospect->id);

        $this->assertSame('2026-10-01 12:00:00', Reminder::query()->where('title', 'Müşteriyi ara')->value('remind_at')->format('Y-m-d H:i:s'), 'Istanbul 15:00 = 12:00 UTC');
        $this->assertNull($prospect->fresh()->next_follow_up_on);
    }

    public function test_calendar_feed_is_token_protected_and_lists_reminders_renewals_and_tasks(): void
    {
        Livewire::test(TodayPanel::class)->call('calendarLink')->assertSee('Takvim bağlantısı oluşturuldu');
        $token = $this->user->fresh()->calendar_feed_token;
        Reminder::query()->create(['user_id' => $this->user->id, 'title' => 'Rapor, müşteri; toplantı', 'remind_at' => now()->addDay()]);
        AssetRenewal::query()->create(['brand_id' => $this->brand->id, 'kind' => 'domain', 'label' => 'atlasdis.com', 'expires_on' => '2026-11-15']);
        DB::table('tasks')->insert(['customer_id' => $this->customer->id, 'title' => 'Sayfa güncelle', 'action' => 'x', 'status' => 'open', 'priority' => 'medium', 'assignee_id' => $this->user->id, 'due_date' => '2026-10-05', 'created_at' => now(), 'updated_at' => now()]);

        auth()->logout();
        $ics = $this->get('/calendar/'.$token.'.ics')->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('SUMMARY:Rapor\\, müşteri\; toplantı', $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261115', $ics);
        $this->assertStringContainsString('SUMMARY:Görev: Sayfa güncelle', $ics);
        $this->get('/calendar/'.str_repeat('a', 48).'.ics')->assertNotFound();
    }
}
