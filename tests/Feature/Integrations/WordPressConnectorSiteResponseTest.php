<?php

namespace Tests\Feature\Integrations;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Livewire\Operator\Integrations\SiteConnectorShow;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\Providers\Website\WebsiteProviderErrorMapper;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use App\Services\Integrations\WordPress\WordPressConnectorSiteException;
use App\Services\Integrations\WordPress\WordPressManagementService;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use App\Support\Roles;
use Closure;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use MoxDop\Website\Discovery\PublicUrlSafety;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * A WordPress site whose plugins, shortcodes or PHP notices print into the connector's REST answer: the output around
 * the signed JSON is cut away (the nonce and signature still verify the data); an answer without the JSON is a site
 * problem that names the site and how the answer starts, and the connection test shows it instead of reporting it.
 */
final class WordPressConnectorSiteResponseTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'site-response-test-secret-0123456789abcdefgh';

    private const array STATUS = ['schema_version' => 1, 'plugin_version' => '1.8.0', 'wordpress_version' => '6.8', 'read_only' => true];

    private const string MESSAGE = 'WordPress sitesi example.com JSON olmayan bir yanıt döndürdü; bir eklenti, kısa kod veya PHP uyarısı yanıta yazıyor olabilir. ';

    private User $admin;

    private DigitalAsset $asset;

    private CoreConnection $connection;

    /** @var Closure(Request): string the site's raw answer to a connector request */
    private Closure $answer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->asset = DigitalAsset::factory()->create(['type' => 'website', 'cms' => 'wordpress', 'domain' => 'example.com', 'primary_url' => 'https://example.com/']);
        $this->connection = CoreConnection::factory()->create([
            'digital_asset_id' => $this->asset->id, 'type' => WordPressConnectorPairingService::CONNECTION_TYPE, 'enabled' => true,
            'config' => [
                'pairing_state' => WordPressConnectorPairingService::PAIRED, 'plugin_version' => '1.8.0',
                'status_url' => 'https://example.com/wp-json/moxdop/v1/status', 'snapshot_url' => 'https://example.com/wp-json/moxdop/v1/snapshot',
            ],
        ]);
        CoreConnectionCredential::factory()->create(['connection_id' => $this->connection->id, 'encrypted_payload' => ['client_id' => 'client-1', 'shared_secret' => self::SECRET]]);
        $this->app->instance(WordPressConnectorClient::class, new WordPressConnectorClient(new WordPressConnectorCanonicalJson, new PublicUrlSafety(fn (string $host): array => ['93.184.216.34'])));

        $this->answer = fn (Request $request): string => $this->signedJson($request, self::STATUS);
        Http::fake(fn (Request $request) => Http::response(($this->answer)($request), 200, ['Content-Type' => 'application/json']));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function outputAroundTheJson(): array
    {
        return [
            'byte order marks' => ["\xEF\xBB\xBF\xEF\xBB\xBF", ''],
            'a PHP notice with braces in front' => ["<br />\n<b>Deprecated</b>:  Function {closure}() is deprecated in <b>/var/www/wp-content/plugins/x/x.php</b> on line <b>12</b><br />\n<style>.x{color:red}</style>", ''],
            'a shortcode in front and a comment behind' => ["\xEF\xBB\xBF<div id=\"fb-root\"></div>", "\n<!-- Page generated in 0.21 seconds -->"],
        ];
    }

    #[Test]
    #[DataProvider('outputAroundTheJson')]
    public function output_around_the_connectors_json_is_cut_away_and_the_signed_data_is_used(string $before, string $after): void
    {
        $this->answer = fn (Request $request): string => $before.$this->signedJson($request, self::STATUS).$after;

        $status = app(WordPressConnectorClient::class)->status($this->connection->fresh('credential'));

        $this->assertSame('6.8', $status['wordpress_version']);
        $this->assertNull($this->connection->fresh()->last_error);
        $this->assertNotNull($this->connection->fresh()->last_success_at);
    }

    #[Test]
    public function the_nonce_and_signature_still_guard_the_data_found_inside_other_output(): void
    {
        $client = app(WordPressConnectorClient::class);
        $answers = [
            'signature verification failed' => fn (Request $request): string => '<p>x{y}</p>'.$this->signedJson($request, self::STATUS, signature: str_repeat('0', 64)),
            'freshness verification failed' => fn (Request $request): string => '<p>x{y}</p>'.$this->signedJson($request, self::STATUS, nonce: 'an-earlier-nonce'),
        ];
        foreach ($answers as $failure => $answer) {
            $this->answer = $answer;
            try {
                $client->status($this->connection->fresh('credential'));
                $this->fail('A forged answer is refused: '.$failure);
            } catch (RuntimeException $error) {
                $this->assertNotInstanceOf(WordPressConnectorSiteException::class, $error);
                $this->assertStringContainsString($failure, $error->getMessage());
            }
        }
    }

    #[Test]
    public function an_answer_without_the_connectors_json_is_a_site_problem_named_with_the_site_and_how_it_starts(): void
    {
        $page = "<!DOCTYPE html>\n<html lang=\"tr\">\n<head><title>Bakım modu</title></head>\n<body>".str_repeat('Site kısa bir süre bakımda. ', 20).'</body></html>';
        $this->answer = fn (): string => $page;

        $error = $this->siteProblem();

        $start = mb_substr((string) preg_replace('/\s+/', ' ', $page), 0, 120);
        $this->assertSame(120, mb_strlen($start));
        $this->assertSame('example.com', $error->host);
        $this->assertSame($start, $error->bodyStart);
        $this->assertSame(self::MESSAGE.'Yanıtın başı: "'.$start.'"', $error->getMessage());
        $this->assertSame($error->getMessage(), $this->connection->fresh()->last_error, 'the site problem is the connection\'s last error');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function answersWithoutJson(): array
    {
        return [
            'an empty answer' => ['', ''],
            'a notice in front of a cut-off envelope' => ['<b>Notice</b>: x'."\n".'{"data":{"a":1},"meta":{"server_time":1', '<b>Notice</b>: x {"data":{"a":1},"meta":{"server_time":1'],
            'invalid UTF-8 and control characters' => ["\xEF\xBB\xBF\xC3\x28\x00\x01 <b>Warning</b>\r\n\tline", '?( <b>Warning</b> line'],
            'a long run of blank lines in front is not an empty answer' => [str_repeat("\n", 3000).'<b>Notice</b>: x', '<b>Notice</b>: x'],
        ];
    }

    #[Test]
    #[DataProvider('answersWithoutJson')]
    public function the_start_of_an_answer_without_json_is_one_clean_line(string $body, string $start): void
    {
        $this->answer = fn (): string => $body;

        $error = $this->siteProblem();

        $this->assertSame($start, $error->bodyStart);
        $this->assertTrue(mb_check_encoding($error->getMessage(), 'UTF-8'));
        $this->assertStringEndsWith($start === '' ? 'Yanıt boş.' : 'Yanıtın başı: "'.$start.'"', $error->getMessage());
        $this->assertSame($error->getMessage(), $this->connection->fresh()->last_error);
    }

    #[Test]
    public function the_connection_test_shows_the_site_problem_and_reports_only_application_errors(): void
    {
        Exceptions::fake();
        $this->answer = fn (): string => '<b>Fatal error</b>: Allowed memory size exhausted';
        $this->actingAs($this->admin);

        $page = Livewire::test(SiteConnectorShow::class, ['connector' => 'wordpress'])
            ->set('selectedAssetId', $this->asset->id)
            ->call('testConnection')
            ->assertSet('messageTone', 'error')
            ->assertSet('message', 'Connector bağlantısı doğrulanamadı: '.self::MESSAGE.'Yanıtın başı: "<b>Fatal error</b>: Allowed memory size exhausted"')
            ->assertSee('Allowed memory size exhausted');
        Exceptions::assertNotReported(WordPressConnectorSiteException::class);

        // A forged answer stays an application alarm with the short message.
        $this->answer = fn (Request $request): string => $this->signedJson($request, self::STATUS, signature: str_repeat('0', 64));
        $page->call('testConnection')
            ->assertSet('messageTone', 'error')
            ->assertSet('message', 'Connector bağlantısı doğrulanamadı.');
        Exceptions::assertReported(fn (RuntimeException $error): bool => str_contains($error->getMessage(), 'signature verification failed'));
    }

    #[Test]
    public function the_sites_own_words_never_turn_a_site_problem_into_a_retry_or_a_login_refusal(): void
    {
        $mapper = new WebsiteProviderErrorMapper;
        $failed = $mapper->fromThrowable(new WordPressConnectorSiteException('example.com', '<script>setTimeout(function () {}, 10)</script> network'));
        $this->assertSame(DatasetExecutionOutcome::Failed, $failed->outcome);
        $this->assertSame(CollectionErrorCategory::Unknown, $failed->errorCategory);
        $this->assertStringContainsString('example.com', (string) $failed->errorMessage);
        $this->assertSame(DatasetExecutionOutcome::Retry, $mapper->fromThrowable(new RuntimeException('Operation timeout'))->outcome, 'a real timeout is still retried');

        $this->answer = fn (): string => '<b>Warning</b>: Undefined index in functions.php on line 403';
        try {
            app(WordPressManagementService::class)->loginUrl($this->asset, $this->admin);
            $this->fail('The login link needs the connector JSON.');
        } catch (ValidationException $error) {
            $this->assertStringStartsWith('Giriş bağlantısı alınamadı: WordPress sitesi example.com', $error->errors()['login'][0]);
        }
    }

    private function siteProblem(): WordPressConnectorSiteException
    {
        try {
            app(WordPressConnectorClient::class)->status($this->connection->fresh('credential'));
        } catch (WordPressConnectorSiteException $error) {
            return $error;
        }
        $this->fail('An answer without the connector JSON is a site problem.');
    }

    /** @param array<string, mixed> $data */
    private function signedJson(Request $request, array $data, ?string $nonce = null, ?string $signature = null): string
    {
        $nonce ??= $request->header(WordPressConnectorClient::HEADER_NONCE)[0] ?? '';
        $time = now()->timestamp;
        $signature ??= hash_hmac('sha256', implode("\n", [(string) $time, $nonce, hash('sha256', (new WordPressConnectorCanonicalJson)->encode($data))]), self::SECRET);

        return (string) json_encode(['data' => $data, 'meta' => ['server_time' => $time, 'request_nonce' => $nonce, 'signature' => $signature]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
