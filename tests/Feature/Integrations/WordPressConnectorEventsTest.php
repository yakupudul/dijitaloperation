<?php

namespace Tests\Feature\Integrations;

use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use App\Services\Site\ContentPosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * yakup 2026-10-14: "sitede paylaşım olduğunda o yazının yayına alındığı bilgisi sisteme anında iletilmesi lazım". The
 * site's events move MoxDOP posts between Taslakta and Yayında as they arrive and start the page refresh at once.
 */
final class WordPressConnectorEventsTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'events-test-secret-0123456789abcdefghijklmn';

    private const string INSTALLATION = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

    private CoreConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $asset = DigitalAsset::factory()->create(['type' => 'website', 'cms' => 'wordpress', 'domain' => 'example.com', 'primary_url' => 'https://example.com/']);
        $this->connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id, 'type' => WordPressConnectorPairingService::CONNECTION_TYPE, 'enabled' => true,
            'config' => ['pairing_state' => WordPressConnectorPairingService::PAIRED, 'plugin_version' => '1.16.0', 'installation_id' => self::INSTALLATION],
        ]);
        CoreConnectionCredential::factory()->create(['connection_id' => $this->connection->id, 'encrypted_payload' => ['client_id' => 'client-1', 'shared_secret' => self::SECRET]]);
    }

    public function test_a_moxdop_post_published_on_the_site_shows_as_published_at_once(): void
    {
        $siteId = (int) $this->connection->digital_asset_id;
        Cache::put(ContentPosts::cacheKey($siteId), [501 => ['status' => 'draft', 'date_gmt' => null, 'link' => '', 'featured_media_id' => 0]], now()->addHour());

        $this->send([$this->event('content.published', '501', [
            'url' => 'https://example.com/implant-fiyatlari/',
            'post_state' => ['post_id' => 501, 'status' => 'publish', 'title' => 'İmplant', 'date_gmt' => '2026-10-14T20:40:00+00:00', 'modified_gmt' => '2026-10-14T20:40:00+00:00', 'link' => 'https://example.com/implant-fiyatlari/', 'featured_media_id' => 9],
        ])])->assertOk();

        $state = app(ContentPosts::class)->states($siteId, [501])[501];
        $this->assertSame('publish', $state['status']);
        $this->assertSame('https://example.com/implant-fiyatlari/', $state['link']);
        $this->assertSame('site', $state['from']);
        $this->assertSame(1, DB::table('website_connector_events')->count());
    }

    public function test_an_older_plugin_event_still_moves_a_tracked_post_and_leaves_other_posts_alone(): void
    {
        $siteId = (int) $this->connection->digital_asset_id;
        Cache::put(ContentPosts::cacheKey($siteId), [
            501 => ['status' => 'draft', 'date_gmt' => null, 'link' => ''],
            502 => ['status' => 'publish', 'date_gmt' => '2026-10-01T08:00:00+00:00', 'link' => 'https://example.com/b/'],
        ], now()->addHour());

        $this->send([
            $this->event('content.published', '501', ['url' => 'https://example.com/a/']),
            $this->event('content.trashed', '502'),
            $this->event('content.published', '777', ['url' => 'https://example.com/not-ours/']),
        ])->assertOk();

        $states = (array) Cache::get(ContentPosts::cacheKey($siteId));
        $this->assertSame('publish', $states[501]['status']);
        $this->assertSame('https://example.com/a/', $states[501]['link']);
        $this->assertNotEmpty($states[501]['date_gmt'], 'a draft published by hand gets the time it went out');
        $this->assertSame('trash', $states[502]['status']);
        $this->assertArrayNotHasKey(777, $states, 'a post MoxDOP does not track is not added');
    }

    public function test_a_content_event_starts_the_page_refresh_now_but_a_site_in_its_error_pause_waits(): void
    {
        DB::table('website_connector_delivery')->insert(['connection_id' => $this->connection->id, 'next_reconcile_at' => now()->addMinutes(10)]);
        $this->send([$this->event('content.updated', '501')])->assertOk();
        $this->assertLessThanOrEqual(now()->getTimestamp(), strtotime((string) DB::table('website_connector_delivery')->value('next_reconcile_at')));

        DB::table('website_connector_delivery')->update(['next_reconcile_at' => now()->addHour()]);
        $this->send([$this->event('seo.updated', '501')])->assertOk();
        $this->assertGreaterThan(now()->addMinutes(30)->getTimestamp(), strtotime((string) DB::table('website_connector_delivery')->value('next_reconcile_at')));
    }

    public function test_a_post_state_must_name_its_status(): void
    {
        $this->send([$this->event('content.published', '501', ['post_state' => ['post_id' => 501]])])->assertUnprocessable();
        $this->assertSame(0, DB::table('website_connector_events')->count());
    }

    public function test_the_plugin_sends_right_after_the_save_without_waiting_for_wp_cron(): void
    {
        $events = (string) file_get_contents(base_path('connectors/wordpress/moxdop-connector/includes/class-moxdop-connector-events.php'));

        $this->assertStringContainsString("'blocking' => false", $events, 'the loopback does not hold the editor’s save');
        $this->assertStringContainsString("add_action('wp_ajax_nopriv_'.self::SEND_ACTION, [\$this, 'send_now'])", $events);
        $this->assertStringContainsString("add_action('admin_footer', [\$this, 'beacon'], 99)", $events);
        $this->assertStringContainsString("MoxDOP_Connector_Posts::post_state(\$event['_moxdop_post'])", $events);
        $this->assertStringContainsString('MoxDOP_Connector_Fixes::REDIRECTS_OPTION', $events, 'removing a 301 on the site is reported');
    }

    /** @param  array<string, mixed>  $extra */
    private function event(string $type, string $objectId, array $extra = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(), 'type' => $type, 'occurred_at' => now()->toIso8601String(),
            'object_type' => 'post', 'object_id' => $objectId, 'title' => 'Yazı', 'url' => '',
            'actor_id' => '3', 'actor_name' => 'Editör', 'origin' => 'wordpress_user', 'fields' => ['post_status'],
        ], $extra);
    }

    /** @param  list<array<string, mixed>>  $events */
    private function send(array $events): TestResponse
    {
        $body = json_encode([
            'schema_version' => 1, 'installation_id' => self::INSTALLATION, 'plugin_version' => '1.17.0', 'events' => $events,
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
