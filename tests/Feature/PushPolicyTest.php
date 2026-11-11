<?php

namespace Tests\Feature;

use App\Livewire\Operator\Komuta\KomutaPage;
use App\Models\AgencySetting;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Assistant\DayDigest;
use App\Services\Assistant\PushNotifier;
use App\Services\Assistant\PushPolicy;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Bildirim düzeni (Günlük Düzen 3): one door, three classes, a daily limit, two summaries, izin modu. */
class PushPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu'])->forceFill(['push_ntfy_url' => 'https://ntfy.test/moxdop', 'push_min_severity' => 'info'])->save();
        Http::fake();
        CarbonImmutable::setTestNow('2026-11-11 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function held(): int
    {
        return DB::table('push_notifications')->where('status', 'held')->count();
    }

    private function sent(): int
    {
        return DB::table('push_notifications')->where('channel', 'ntfy')->where('status', 'sent')->count();
    }

    public function test_urgent_ones_ring_summary_ones_wait_and_the_morning_plan_tells_them_once(): void
    {
        $push = app(PushNotifier::class);
        $this->assertSame(1, $push->send('lead-form:7:202611111000', 'Yeni form: Işık Diş', 'x', 'high'));
        $this->assertSame(0, $push->send('repair-desk-digest:20261111', 'Onarım masası: 12 iş onayını bekliyor', 'x', 'high'));
        $this->assertSame(0, $push->send('repair-desk-digest:20261111', 'Onarım masası: 12 iş onayını bekliyor', 'x', 'high'), 'kept once');
        $this->assertSame(0, $push->send('provider-watch:auth:1:2026-11-20', 'Meta yetkisi: 6 gün kaldı', 'x', 'high'));
        $this->assertSame([1, 2], [$this->sent(), $this->held()]);

        Reminder::query()->create(['user_id' => $this->admin->id, 'kind' => 'meeting', 'title' => 'Eyüp Bey', 'remind_at' => now()->addHours(3)]);
        $this->assertSame(1, app(DayDigest::class)->morning());
        $row = DB::table('push_notifications')->where('channel', 'ntfy')->orderByDesc('id')->first();
        $this->assertSame('Sabah planı', $row->title);
        $this->assertStringContainsString('Bugün: 1 toplantı', $row->body);
        $this->assertStringContainsString('Onarım masası: 12 iş', $row->body);
        $this->assertStringContainsString('Meta yetkisi', $row->body);
        $this->assertSame(0, $this->held());
    }

    public function test_izin_modu_lets_only_money_alarms_and_own_alarms_ring(): void
    {
        Livewire::actingAs($this->admin)->test(KomutaPage::class)->assertSeeHtml('data-komuta-leave')->call('setAway', true)->assertSeeHtml('data-komuta-away');
        $this->assertTrue(PushPolicy::away());
        $push = app(PushNotifier::class);
        $this->assertSame(0, $push->send('lead-form:8:202611111001', 'Yeni form', 'x', 'high'));
        $this->assertSame(1, $push->send('uptime:down:3', 'Site erişilemiyor', 'x', 'critical'));
        $this->assertSame(1, $push->send('alert:5:spend:20261111', 'Reklam harcıyor, talep yok', 'x', 'critical'));
        $this->assertSame(1, $push->send('reminder:9:202611111300', 'Toplantı', 'x', 'info'));
        $this->assertSame(1, $this->held());

        CarbonImmutable::setTestNow('2026-11-12 08:30:00');
        $this->assertFalse(PushPolicy::away(), 'the mode ends with the day');
        Livewire::actingAs($this->admin)->test(KomutaPage::class)->assertDontSeeHtml('data-komuta-away');
    }

    public function test_above_the_daily_limit_a_notification_waits_for_the_summary_and_the_evening_close_names_tomorrow(): void
    {
        foreach (range(1, PushPolicy::DAILY_LIMIT) as $i) {
            DB::table('push_notifications')->insert(['dedupe_key' => 'inbox:wa-'.$i.':1:1', 'channel' => 'browser', 'severity' => 'high', 'title' => 'x', 'body' => 'x', 'status' => 'sent', 'created_at' => now()]);
        }
        $push = app(PushNotifier::class);
        $this->assertSame(0, $push->send('lead-form:9:202611111002', 'Yeni form: Sınır Üstü', 'x', 'high'));
        $this->assertSame(1, $push->send('uptime:down:4', 'Site erişilemiyor', 'x', 'critical'));

        Reminder::query()->create(['user_id' => $this->admin->id, 'kind' => 'task', 'title' => 'Bitti', 'done_at' => now()]);
        Reminder::query()->create(['user_id' => $this->admin->id, 'kind' => 'meeting', 'title' => 'Dentocare görüşmesi', 'remind_at' => '2026-11-12 08:00:00']);
        CarbonImmutable::setTestNow('2026-11-11 16:00:00'); // 19:00 Istanbul
        $this->assertSame(1, app(DayDigest::class)->evening());
        $body = (string) DB::table('push_notifications')->where('channel', 'ntfy')->where('title', 'Akşam kapanışı')->value('body');
        $this->assertStringContainsString('Bugün 1 işini bitirdin', $body);
        $this->assertStringContainsString('Yarın ilk iş: 11:00 Dentocare görüşmesi', $body);
        $this->assertStringContainsString('Yeni form: Sınır Üstü', $body);
    }
}
