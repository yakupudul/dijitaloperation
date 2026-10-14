<?php

namespace Tests\Feature\Integrations;

use App\Livewire\Operator\Integrations\SiteConnectorShow;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\Integrations\WordPress\WordPressConnectorCommands;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use MoxDop\Website\Discovery\PublicUrlSafety;
use RuntimeException;
use Tests\TestCase;

/**
 * Connector 1.13.0: a host that refuses every request from MoxDOP (Avrupadent, 2026-10-09). The site asks MoxDOP for
 * its work; the request MoxDOP would have sent runs there and its signed answer comes back to the waiting caller.
 */
final class WordPressConnectorPullTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'pull-test-secret-0123456789abcdefghijklmnop';

    private const string INSTALLATION = '0f8fad5b-d9cb-469f-a165-70867728950e';

    private CoreConnection $connection;

    /** @var list<array<string, mixed>> commands the simulated site ran */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();
        $asset = DigitalAsset::factory()->create(['type' => 'website', 'cms' => 'wordpress', 'domain' => 'example.com', 'primary_url' => 'https://example.com/']);
        $this->connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id, 'type' => WordPressConnectorPairingService::CONNECTION_TYPE, 'enabled' => true,
            'config' => ['pairing_state' => WordPressConnectorPairingService::PAIRED, 'plugin_version' => '1.13.0', 'installation_id' => self::INSTALLATION,
                'status_url' => 'https://example.com/wp-json/moxdop/v1/status', 'snapshot_url' => 'https://example.com/wp-json/moxdop/v1/snapshot'],
        ]);
        CoreConnectionCredential::factory()->create(['connection_id' => $this->connection->id, 'encrypted_payload' => ['client_id' => 'client-1', 'shared_secret' => self::SECRET]]);
        $this->app->instance(WordPressConnectorClient::class, new WordPressConnectorClient(new WordPressConnectorCanonicalJson, new PublicUrlSafety(fn (string $host): array => ['93.184.216.34'])));
        // The host refuses everything MoxDOP sends.
        Http::fake(fn () => Http::response('<html><body>403 Forbidden Access to this resource on the server is denied!</body></html>', 403, ['Server' => 'LiteSpeed']));
        // While the caller waits, the site asks for its work and answers it.
        $this->app->instance(WordPressConnectorCommands::class, new WordPressConnectorCommands(fn () => $this->siteAsks()));
    }

    public function test_a_refused_site_that_asks_for_work_gets_the_request_and_its_answer_returns(): void
    {
        $this->siteAsks(); // the 1.13.0 plugin has been asking every 15 minutes

        $data = app(WordPressConnectorClient::class)->applyFixes($this->connection->fresh(), [['type' => 'seo_title', 'object_id' => 5, 'reference' => 'r', 'value' => 'Yeni']]);

        $this->assertSame([['ok' => true]], $data['results']);
        $this->assertSame('pull', $this->connection->fresh()->config['rest_transport']);
        $this->assertSame(['POST', '/moxdop/v1/fixes'], [$this->ran[0]['method'], $this->ran[0]['route']]);
        $this->assertStringContainsString('"seo_title"', $this->ran[0]['body']);
        $this->assertNull($this->connection->fresh()->last_error);

        // From now on nothing is sent to the site: the next call goes the same way at once.
        Http::fake(fn () => throw new RuntimeException('no request to the site'));
        $this->ran = [];
        app(WordPressConnectorClient::class)->health($this->connection->fresh());
        $this->assertSame('/moxdop/v1/health', $this->ran[0]['route']);
    }

    public function test_the_site_learns_from_its_ask_that_work_comes_this_way(): void
    {
        $this->assertFalse($this->ask([], true)['pull']);
        $this->artisan('moxdop:wordpress:pull', ['site' => 'example.com'])->assertSuccessful();
        $this->assertTrue($this->ask([], true)['pull']);
    }

    public function test_a_job_saving_its_old_copy_of_the_connection_does_not_lose_the_sites_last_ask(): void
    {
        $old = $this->connection->fresh();
        $this->artisan('moxdop:wordpress:pull', ['site' => 'example.com'])->assertSuccessful();
        $this->siteAsks();
        $old->forceFill(['config' => array_merge((array) $old->config, ['plugin_version' => '1.13.0'])])->save();

        $this->assertTrue(WordPressConnectorCommands::available($this->connection->fresh()));
        $this->assertNotNull(WordPressConnectorCommands::seenAt($this->connection->fresh()));
    }

    public function test_an_ask_with_a_wrong_signature_or_a_reused_nonce_is_refused(): void
    {
        $this->postJson('/api/connectors/wordpress/commands', $this->payload([], true), $this->headers('{}'))->assertStatus(401);
        $body = json_encode($this->payload([], true));
        $headers = $this->headers((string) $body);
        $this->call('POST', '/api/connectors/wordpress/commands', [], [], [], $this->server($headers), $body)->assertOk();
        $this->call('POST', '/api/connectors/wordpress/commands', [], [], [], $this->server($headers), $body)->assertStatus(409);
    }

    public function test_a_caller_that_stops_waiting_withdraws_the_request(): void
    {
        $this->siteAsks();
        WordPressConnectorCommands::setTransport($this->connection, 'pull');
        $this->app->instance(WordPressConnectorCommands::class, new WordPressConnectorCommands(fn () => usleep(1000)));
        config(['moxdop-wordpress.pull_wait_seconds' => 1]);

        try {
            app(WordPressConnectorClient::class)->health($this->connection->fresh());
            $this->fail('expected a timeout');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('gelip almadı', $e->getMessage());
        }

        $this->assertSame([], $this->ask([], true)['commands'], 'the withdrawn request never runs on the site');
    }

    public function test_the_last_ask_is_kept_in_the_database_with_what_the_site_reports(): void
    {
        $this->ask([], true, ['plugin_version' => '1.16.0', 'site' => ['trigger' => 'visit', 'cron_disabled' => false, 'last_failure' => 'MoxDOP HTTP 504 döndü', 'last_failure_at' => '2026-10-14T06:50:00+00:00']]);
        Cache::flush();

        $connection = $this->connection->fresh();
        $this->assertNotNull(WordPressConnectorCommands::seenAt($connection), 'the last ask survives a cache flush');
        $state = WordPressConnectorCommands::state($connection);
        $this->assertSame('visit', $state['report']['trigger']);
        $this->assertSame('MoxDOP HTTP 504 döndü', $state['report']['last_failure']);
        $this->assertSame('1.16.0', $connection->config['plugin_version'], 'the version the site reports on its ask is kept');
    }

    public function test_an_ask_from_a_site_with_a_wrong_clock_is_refused_with_the_reason_kept(): void
    {
        $body = (string) json_encode($this->payload([], true));
        $headers = $this->headers($body, time() - 900);
        $this->call('POST', '/api/connectors/wordpress/commands', [], [], [], $this->server($headers), $body)->assertStatus(401);

        $state = WordPressConnectorCommands::state($this->connection->fresh());
        $this->assertNull($state['seen_at']);
        $this->assertStringContainsString('900 saniye geri', (string) $state['refusal']);
    }

    public function test_an_answer_with_an_empty_body_is_still_accepted(): void
    {
        WordPressConnectorCommands::setTransport($this->connection, 'pull');
        $id = DB::table('website_connector_commands')->insertGetId(['connection_id' => $this->connection->id, 'method' => 'GET', 'route' => '/moxdop/v1/health', 'status' => 'pending', 'created_at' => now()]);
        $this->assertSame($id, $this->ask([], true)['commands'][0]['id']);

        $this->ask([['id' => $id, 'status' => 500, 'body' => '']], false);

        $this->assertSame('done', DB::table('website_connector_commands')->where('id', $id)->value('status'));
    }

    public function test_the_connector_page_says_when_the_site_last_asked_instead_of_asking_for_1_13_0(): void
    {
        WordPressConnectorCommands::setTransport($this->connection, 'pull');
        $connection = $this->connection->fresh();
        $never = SiteConnectorShow::pullSummary($connection);
        $this->assertStringContainsString('henüz hiç sormadı', $never);
        $this->assertStringContainsString('1.16.0', $never, 'an older plugin is told to update to the version that also asks on visits');
        $this->assertStringNotContainsString('1.13.0 kurulu mu', $never);

        $this->ask([], true, ['plugin_version' => '1.16.0', 'site' => ['trigger' => 'visit']]);
        $fresh = SiteConnectorShow::pullSummary($this->connection->fresh());
        $this->assertStringContainsString('(bir ziyaretle)', $fresh);
        $this->assertStringNotContainsString('güncelle', $fresh);
    }

    public function test_a_connector_update_the_site_did_not_take_in_time_stays_for_its_next_ask(): void
    {
        $this->siteAsks();
        WordPressConnectorCommands::setTransport($this->connection, 'pull');
        $this->app->instance(WordPressConnectorCommands::class, new WordPressConnectorCommands(fn () => usleep(1000)));
        config(['moxdop-wordpress.pull_wait_seconds' => 1]);
        DB::table('website_connector_commands')->insert(['connection_id' => $this->connection->id, 'method' => 'GET', 'route' => '/moxdop/v1/health', 'status' => 'pending', 'created_at' => now()->subMinute()]);

        try {
            app(WordPressConnectorClient::class)->selfUpdate($this->connection->fresh(), '1.16.0', 'https://app.example/pkg.zip', str_repeat('a', 64));
            $this->fail('expected the caller to stop waiting');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ilk soruşunda alacak', $e->getMessage());
        }

        $this->assertSame('/moxdop/v1/self-update', $this->ask([], true)['commands'][0]['route'], 'the site still gets the update on its next ask, before other waiting work');
    }

    /** One ask of the 1.13.0 plugin: runs every command it gets (verifying MoxDOP's signature) and brings the answers back. */
    private function siteAsks(): void
    {
        $reply = $this->ask([], true);
        $results = [];
        foreach ($reply['commands'] as $command) {
            $headers = $command['headers'];
            $canonical = implode("\n", [$command['method'], $command['route'], $command['query'], $headers['X-MoxDOP-Timestamp'], $headers['X-MoxDOP-Nonce'], hash('sha256', $command['body'])]);
            $this->assertSame(hash_hmac('sha256', $canonical, self::SECRET), $headers['X-MoxDOP-Signature'], 'the command is signed like a direct request');
            $this->ran[] = $command;
            $data = $command['route'] === '/moxdop/v1/fixes' ? ['results' => [['ok' => true]]] : ['wordpress_version' => '6.8'];
            $time = time();
            $results[] = ['id' => $command['id'], 'status' => 200, 'body' => (string) json_encode(['data' => $data, 'meta' => ['server_time' => $time,
                'request_nonce' => $headers['X-MoxDOP-Nonce'],
                'signature' => hash_hmac('sha256', implode("\n", [(string) $time, $headers['X-MoxDOP-Nonce'], hash('sha256', (new WordPressConnectorCanonicalJson)->encode($data))]), self::SECRET)]])];
        }
        if ($results !== []) {
            $this->ask($results, false);
        }
    }

    /**
     * @param  array<string, mixed>  $extra  fields a newer plugin adds (plugin_version, site)
     * @return array{commands: list<array<string, mixed>>, pull: bool}
     */
    private function ask(array $results, bool $take, array $extra = []): array
    {
        $body = (string) json_encode(array_merge($this->payload($results, $take), $extra));
        $response = $this->call('POST', '/api/connectors/wordpress/commands', [], [], [], $this->server($this->headers($body)), $body)->assertOk();
        $data = $response->json('data');
        $expected = hash_hmac('sha256', implode("\n", [(string) $response->json('meta.server_time'), $response->json('meta.request_nonce'),
            hash('sha256', (new WordPressConnectorCanonicalJson)->encode($data))]), self::SECRET);
        $this->assertSame($expected, $response->json('meta.signature'));

        return $data;
    }

    /** @return array<string, mixed> */
    private function payload(array $results, bool $take): array
    {
        return ['schema_version' => 1, 'installation_id' => self::INSTALLATION, 'plugin_version' => '1.13.0', 'take' => $take, 'results' => $results];
    }

    /** @return array<string, string> */
    private function headers(string $body, ?int $time = null): array
    {
        $timestamp = (string) ($time ?? time());
        $nonce = (string) Str::uuid();

        return ['X-MoxDOP-Installation' => self::INSTALLATION, 'X-MoxDOP-Client' => 'client-1', 'X-MoxDOP-Timestamp' => $timestamp, 'X-MoxDOP-Nonce' => $nonce,
            'X-MoxDOP-Signature' => hash_hmac('sha256', implode("\n", ['POST', '/api/connectors/wordpress/commands', '', $timestamp, $nonce, hash('sha256', $body)]), self::SECRET)];
    }

    /** @param  array<string, string>  $headers */
    private function server(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }
}
