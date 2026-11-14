<?php

namespace Tests\Feature\Site;

use App\Models\AgencySetting;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Kasa sesi (yakup 2026-11-14): a paid order on the agency's own shop rings the phone with the cash register sound,
 * a free application with a soft chime (silent at night); neither starts a page refresh of the site.
 */
final class SaleAlertsTest extends SiteTestCase
{
    private const string SECRET = 'sale-alerts-test-secret-0123456789abcdefgh';

    private const string INSTALLATION = '1c7f2a1e-4c11-4e3c-9d55-7f0f4b0b2c11';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake(['*' => Http::response('', 201)]);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://panorama.com.tr/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.37.0', 'installation_id' => self::INSTALLATION],
        ]);
        CoreConnectionCredential::factory()->create(['connection_id' => $connection->id, 'encrypted_payload' => ['client_id' => 'client-1', 'shared_secret' => self::SECRET]]);
        PushSubscription::query()->create(['user_id' => $this->admin->id, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
            'endpoint_hash' => PushSubscription::hashEndpoint('https://fcm.googleapis.com/fcm/send/abc'), 'public_key' => null, 'auth_token' => null, 'failures' => 0]);
        $this->travelTo('2026-11-14 09:00:00'); // 12:00 in Istanbul
    }

    public function test_a_paid_order_rings_with_the_cash_sound_once_and_starts_no_refresh(): void
    {
        $event = $this->event('commerce.order_paid', 41, 'Mevzuat denetimi · 3.000 ₺ · Gülüş Klinik');
        $this->send([$event])->assertOk();
        $this->send([$event])->assertOk(); // the outbox may deliver the same event twice

        $push = DB::table('push_notifications')->where('channel', 'browser')->sole();
        $this->assertSame('Yeni sipariş geldi', $push->title);
        $this->assertSame('Mevzuat denetimi · 3.000 ₺ · Gülüş Klinik · panorama.com.tr', $push->body);
        $this->assertSame('high', $push->severity);
        $this->assertNull(DB::table('website_connector_delivery')->value('next_reconcile_at'), 'an order changes no page');

        $latest = $this->actingAs($this->admin)->getJson('/push/latest?after=0')->assertOk()->json();
        $this->assertSame('kasa', $latest['sound']);
        $this->assertSame('https://panorama.com.tr/wp-admin/post.php?post=41&action=edit', $latest['url']);
    }

    public function test_an_application_chimes_by_day_and_comes_silently_at_night(): void
    {
        $this->send([$this->event('commerce.application', 42, 'Gülüş Klinik · Manisa')])->assertOk();
        $this->travelTo('2026-11-14 21:00:00'); // midnight in Istanbul
        $this->send([$this->event('commerce.application', 43, 'Ağız Klinik')])->assertOk();

        $rows = DB::table('push_notifications')->where('channel', 'browser')->orderBy('id')->get();
        $this->assertSame(['high', 'low'], $rows->pluck('severity')->all());
        $this->assertSame('Yeni ön değerlendirme başvurusu', $rows[0]->title);

        $latest = $this->actingAs($this->admin)->getJson('/push/latest?after='.$rows[0]->id)->assertOk()->json();
        $this->assertSame('', $latest['sound'], 'a silent one plays no sound');
        $this->assertTrue($latest['silent']);
    }

    public function test_the_test_button_sends_an_order_notification_with_the_cash_sound(): void
    {
        $this->actingAs($this->admin)->postJson('/push/test', ['tur' => 'kasa'])->assertOk()->assertJson(['sent' => 1]);

        $this->assertSame('kasa', $this->actingAs($this->admin)->getJson('/push/latest')->json('sound'));
        $this->assertFileExists(public_path('sounds/kasa.mp3'));
        $this->assertFileExists(public_path('sounds/zil.mp3'));
        $this->assertStringContainsString("type: 'moxdop-sound'", (string) file_get_contents(public_path('sw.js')));
    }

    /** @return array<string, mixed> */
    private function event(string $type, int $orderId, string $title): array
    {
        return ['event_id' => (string) Str::uuid(), 'type' => $type, 'occurred_at' => now()->toIso8601String(), 'object_type' => 'shop_order', 'object_id' => (string) $orderId,
            'title' => $title, 'url' => 'https://panorama.com.tr/wp-admin/post.php?post='.$orderId.'&action=edit', 'actor_id' => '0', 'actor_name' => '', 'origin' => 'wordpress_automation', 'fields' => ['total']];
    }

    /** @param  list<array<string, mixed>>  $events */
    private function send(array $events): TestResponse
    {
        $body = json_encode([
            'schema_version' => 1, 'installation_id' => self::INSTALLATION, 'plugin_version' => '1.37.0', 'events' => $events,
            'delivery' => ['pending' => 0, 'gap_at' => null, 'storage_ready' => true, 'wp_cron_disabled' => false],
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = (string) Str::uuid();
        $signature = hash_hmac('sha256', implode("\n", ['POST', '/api/connectors/wordpress/events', '', $timestamp, $nonce, hash('sha256', $body)]), self::SECRET);

        return $this->call('POST', '/api/connectors/wordpress/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_MOXDOP_INSTALLATION' => self::INSTALLATION, 'HTTP_X_MOXDOP_CLIENT' => 'client-1',
            'HTTP_X_MOXDOP_TIMESTAMP' => $timestamp, 'HTTP_X_MOXDOP_NONCE' => $nonce, 'HTTP_X_MOXDOP_SIGNATURE' => $signature,
        ], $body);
    }
}
