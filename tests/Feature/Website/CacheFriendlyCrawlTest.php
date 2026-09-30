<?php

namespace Tests\Feature\Website;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Enums\DigitalAssetStatus;
use App\Livewire\Operator\Integrations\WebsiteIntegrationIndex;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\Providers\Website\WebsiteCrawlPoliteness;
use App\Services\Collection\Providers\Website\WebsiteCrawlState;
use App\Services\Collection\Providers\Website\WebsiteDatasetExecutor;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use MoxDop\Website\Discovery\PublicHttpFetcher;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cache-friendly page reads: visitor-like requests the site's page cache answers, cache-hit tracking that lets a
 * fully cached site be read a little faster, conditional requests (304 = unchanged) and the WordPress Connector's
 * page-cache export (HTML read from the cache plugin's files, persisted like a crawled page).
 */
final class CacheFriendlyCrawlTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'cache-test-shared-secret-0123456789abcdefghij';

    private Brand $brand;

    private DigitalAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Storage::fake('raw_ingestion');
        config([
            'moxdop-collection.queue_connection' => 'database',
            'moxdop-collection.require_queue_connection' => false,
            'moxdop-data-pool.raw_disk' => 'raw_ingestion',
            'filesystems.disks.raw_ingestion' => ['driver' => 'local', 'root' => storage_path('framework/testing/raw_ingestion')],
        ]);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id, 'type' => 'website', 'module_id' => 'website', 'status' => DigitalAssetStatus::Active,
            'domain' => '1.1.1.1', 'primary_url' => 'http://1.1.1.1/',
        ]);
    }

    #[Test]
    public function page_reads_look_like_a_visitor_so_the_page_cache_answers_them(): void
    {
        Http::fake(fn () => Http::response('<html><body>ok</body></html>', 200, ['Content-Type' => 'text/html']));

        (new PublicHttpFetcher)->fetchMany(['http://1.1.1.1/a/', 'http://1.1.1.1/b'], null, 2);
        (new PublicHttpFetcher)->fetch('http://1.1.1.1/c');

        Http::assertSentCount(3);
        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            $agent = $request->header('User-Agent')[0] ?? '';
            $this->assertStringStartsWith('Mozilla/5.0 (compatible; MoxDOP-SiteReader/1.0', $agent);
            // WP Super Cache's default rejected agents and common bot rules must not match.
            $this->assertDoesNotMatchRegularExpression('/bot|crawl|spider|slurp|yandex|ia_archive/i', $agent);
            $this->assertStringContainsString('gzip', $request->header('Accept-Encoding')[0] ?? '');
            $this->assertStringStartsWith('tr-TR', $request->header('Accept-Language')[0] ?? '');
            $this->assertStringStartsWith('text/html', $request->header('Accept')[0] ?? '');
            foreach (['Cache-Control', 'Pragma', 'Cookie', 'If-None-Match', 'If-Modified-Since'] as $header) {
                $this->assertFalse($request->hasHeader($header), $header.' must not be sent');
            }
            $this->assertNull(parse_url($request->url(), PHP_URL_QUERY), 'no cache-busting query string');
        }
        $this->assertSame(['http://1.1.1.1/a/', 'http://1.1.1.1/b', 'http://1.1.1.1/c'], collect(Http::recorded())->map(fn (array $pair): string => $pair[0]->url())->all());
    }

    #[Test]
    public function cache_hits_are_recognised_from_headers_and_cache_plugin_comments(): void
    {
        $cases = [
            [['X-LiteSpeed-Cache' => 'hit'], null, true, 'litespeed'],
            [['x-litespeed-cache' => 'miss'], null, false, 'litespeed'],
            [['cf-cache-status' => 'HIT', 'cf-apo-via' => 'tcache'], null, true, 'cloudflare_apo'],
            [['cf-cache-status' => 'DYNAMIC'], null, false, null],
            [['X-Cache' => 'HIT from varnish'], null, true, null],
            [['x-proxy-cache' => 'MISS'], null, false, null],
            [['Age' => '120'], null, true, null],
            [['Age' => '0'], null, null, null],
            [['x-wp-super-cache' => 'Served supercache file'], null, true, 'wp_super_cache'],
            [[], '<html><body>x</body></html><!-- This website is like a Rocket, isn\'t it? Performance optimized by WP Rocket. -->', true, 'wp_rocket'],
            [[], '<html></html><!-- Cached page generated by WP-Super-Cache on 2026-11-09 01:00:00 -->', true, 'wp_super_cache'],
            [[], '<html></html><!-- Performance optimized by W3 Total Cache. Page Caching using Disk: Enhanced -->', true, 'w3_total_cache'],
            [[], '<html></html><!-- Performance optimized by W3 Total Cache. Page Caching using Disk: Enhanced (Requested URI is rejected) -->', false, 'w3_total_cache'],
            [[], '<html></html><!-- Cache Enabler by KeyCDN @ Mon, 09 Nov 2026 -->', true, 'cache_enabler'],
            [[], '<html></html>', null, null],
        ];
        foreach ($cases as $index => [$headers, $body, $hit, $plugin]) {
            $this->assertSame(['hit' => $hit, 'plugin' => $plugin], PublicHttpFetcher::cacheSignal($headers, $body), 'case '.$index);
        }
    }

    #[Test]
    public function a_site_that_serves_every_page_from_its_cache_is_read_four_at_a_time(): void
    {
        config(['moxdop-website-intelligence.crawl.min_delay_seconds' => 2]);
        Http::fake(fn () => Http::response('<html><head><title>Klinik</title></head><body><h1>Klinik</h1></body></html>', 200, [
            'Content-Type' => 'text/html', 'X-LiteSpeed-Cache' => 'hit',
        ]));
        [$context, $datasetRun] = $this->makeContext();
        $executor = app(WebsiteDatasetExecutor::class);
        $queue = array_map(static fn (int $i): string => 'http://1.1.1.1/page-'.$i, range(1, 20));

        $first = $executor->execute($this->contextFrom($context, $datasetRun, ['observed_at' => '2026-11-09 00:00:00', 'queue' => $queue, 'visited' => [], 'pages' => 0]));
        $this->assertSame(DatasetExecutionOutcome::Continue, $first->outcome, (string) $first->errorMessage);
        $this->assertSame(6, $first->pagesCompleted, 'unknown cache state: the normal pace');
        $this->assertSame(1.0, app(WebsiteCrawlState::class)->hitRatio($this->asset->id));
        $this->assertSame('litespeed', DB::table('website_crawl_state')->where('digital_asset_id', $this->asset->id)->value('cache_plugin'));
        $this->assertSame(4, $first->checkpoint['politeness']['concurrency'], 'cached pages cost the host next to nothing');

        $second = $executor->execute($this->contextFrom($context, $datasetRun, $first->checkpoint));
        $this->assertSame(8, $second->pagesCompleted, 'four at a time, two rounds per step');
        $this->assertSame(1.0, $second->checkpoint['politeness']['cache_hit_ratio']);

        // Backing off still wins over the cache.
        $politeness = app(WebsiteCrawlPoliteness::class);
        $politeness->backOff('1.1.1.1', 'rate_limited', 8);
        $this->assertSame(1, $politeness->concurrency('1.1.1.1', null, 1.0));
    }

    #[Test]
    public function a_site_whose_pages_miss_the_cache_keeps_two_at_a_time(): void
    {
        Http::fake(fn () => Http::response('<html><body>ok</body></html>', 200, ['Content-Type' => 'text/html', 'X-LiteSpeed-Cache' => 'miss']));
        [$context, $datasetRun] = $this->makeContext();
        $executor = app(WebsiteDatasetExecutor::class);
        $queue = array_map(static fn (int $i): string => 'http://1.1.1.1/page-'.$i, range(1, 20));

        $first = $executor->execute($this->contextFrom($context, $datasetRun, ['observed_at' => '2026-11-09 00:00:00', 'queue' => $queue, 'visited' => [], 'pages' => 0]));
        $second = $executor->execute($this->contextFrom($context, $datasetRun, $first->checkpoint));

        $this->assertSame(0.0, app(WebsiteCrawlState::class)->hitRatio($this->asset->id));
        $this->assertSame(6, $second->pagesCompleted);
        $this->assertSame(2, $second->checkpoint['politeness']['concurrency']);
    }

    #[Test]
    public function an_unchanged_page_answers_304_to_a_conditional_request_and_keeps_its_stored_copy(): void
    {
        $body = '<html><head><title>Klinik</title></head><body><h1>Klinik</h1><a href="/implant">İmplant</a></body></html>';
        Http::fake(function (Request $request) use ($body) {
            if (($request->header('If-None-Match')[0] ?? null) === '"v1"') {
                return Http::response('', 304, ['ETag' => '"v1"']);
            }

            return Http::response($body, 200, ['Content-Type' => 'text/html', 'ETag' => '"v1"', 'Last-Modified' => 'Tue, 03 Nov 2026 10:00:00 GMT']);
        });
        $home = 'http://1.1.1.1/';
        $tables = ['website_http_snapshot', 'website_html_snapshot', 'website_metadata_snapshot', 'website_heading_snapshot', 'website_link_edge'];

        [$context] = $this->makeContext();
        app(WebsiteDatasetExecutor::class)->execute($this->contextFrom($context, $context->datasetRun, [
            'observed_at' => '2026-11-07 00:00:00', 'queue' => [$home], 'visited' => [], 'pages' => 0,
        ]));
        $metadata = json_decode((string) DB::table('website_http_snapshot')->value('metadata'), true);
        $this->assertSame('"v1"', $metadata['etag']);
        $this->assertSame('Tue, 03 Nov 2026 10:00:00 GMT', $metadata['last_modified']);
        $this->assertSame('public_crawl', $metadata['fetch_source']);
        $counts = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();

        [$second] = $this->makeContext();
        $again = app(WebsiteDatasetExecutor::class)->execute($this->contextFrom($second, $second->datasetRun, [
            'observed_at' => '2026-11-08 00:00:00', 'queue' => [$home], 'visited' => [], 'pages' => 0,
        ]));

        $this->assertSame(DatasetExecutionOutcome::Completed, $again->outcome, (string) $again->errorMessage);
        $this->assertSame(0, $again->rowsWritten);
        $this->assertSame(['page_cache' => 0, 'fetched' => 0, 'not_modified' => 1, 'same' => 0], $again->checkpoint['source_mix']);
        $conditional = collect(Http::recorded())->map(fn (array $pair) => $pair[0])->last();
        $this->assertSame('"v1"', $conditional->header('If-None-Match')[0] ?? null);
        $this->assertSame('Tue, 03 Nov 2026 10:00:00 GMT', $conditional->header('If-Modified-Since')[0] ?? null);
        foreach ($tables as $table) {
            $this->assertSame($counts[$table], DB::table($table)->count(), $table.' does not grow on a 304');
            $this->assertSame(0, DB::table($table)->where('observed_at', '<', '2026-11-08 00:00:00')->count(), $table.' rows move to the new observation');
        }
        $this->assertSame('unchanged', DB::table('website_html_snapshot')->value('change_state'));

        // A full re-read sends no validators: the page is read again (same HTML → the unchanged path).
        [$third] = $this->makeContext();
        $full = app(WebsiteDatasetExecutor::class)->execute($this->contextFrom($third, $third->datasetRun, [
            'observed_at' => '2026-11-09 00:00:00', 'queue' => [$home], 'visited' => [], 'pages' => 0, 'full_read' => true,
        ]));
        $this->assertFalse(collect(Http::recorded())->map(fn (array $pair) => $pair[0])->last()->hasHeader('If-None-Match'));
        $this->assertSame(['page_cache' => 0, 'fetched' => 0, 'not_modified' => 0, 'same' => 1], $full->checkpoint['source_mix']);
    }

    #[Test]
    public function html_from_the_connectors_page_cache_export_is_stored_like_a_crawled_page_and_skipped_by_the_crawl(): void
    {
        config(['moxdop-website-intelligence.crawl.page_cache_min_queue' => 3, 'moxdop-wordpress.page_delay_seconds' => 1]);
        $connection = $this->pairConnector('1.6.0');
        foreach (['p1', 'p2', 'p3', 'p4'] as $index => $slug) {
            DB::table('website_cms_object_snapshot')->insert([
                'digital_asset_id' => $this->asset->id, 'cms' => 'wordpress', 'object_type' => 'page', 'object_id' => (string) ($index + 10), 'status' => 'publish',
                'permalink' => 'http://1.1.1.1/'.$slug.'/', 'modified_at' => '2026-11-01 00:00:00', 'observed_at' => now(), 'contract_version' => 1,
                'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $slug), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $cached = fn (string $slug): string => '<html><head><title>'.$slug.'</title></head><body><h1>'.$slug.'</h1><p>Önbellekten okunan sayfa.</p></body></html><!-- This website is like a Rocket -->';
        $record = fn (string $url, string $html): array => [
            'url' => $url, 'status' => 'cached', 'cache_plugin' => 'wp_rocket', 'file_mtime' => '2026-11-08T22:00:00+00:00',
            'sha256' => hash('sha256', $html), 'bytes' => strlen($html), 'html_gz_b64' => base64_encode((string) gzencode($html)),
        ];
        $records = [
            $record('http://1.1.1.1/', $cached('home')),
            $record('http://1.1.1.1/p1/', $cached('p1')),
            $record('http://1.1.1.1/p2/', $cached('p2')),
            ['url' => 'http://1.1.1.1/p3/', 'status' => 'not_cached', 'cache_plugin' => 'wp_rocket'],
            // A copy whose hash does not match is ignored: the page is read over HTTP.
            array_merge($record('http://1.1.1.1/p4/', $cached('p4')), ['sha256' => str_repeat('0', 64)]),
        ];
        $this->fakeSite($records);

        [$context, $datasetRun] = $this->makeContext();
        $executor = app(WebsiteDatasetExecutor::class);
        $first = $executor->execute($this->contextFrom($context, $datasetRun, []));

        $this->assertSame(DatasetExecutionOutcome::Continue, $first->outcome, (string) $first->errorMessage);
        $this->assertSame('page_cache', $first->stage);
        $this->assertSame(3, $first->pagesCompleted);
        $this->assertSame(['http://1.1.1.1/p3/', 'http://1.1.1.1/p4/'], array_values(array_intersect($first->checkpoint['queue'], ['http://1.1.1.1/p3/', 'http://1.1.1.1/p4/'])));
        $this->assertCount(2, $first->checkpoint['queue']);
        $this->assertArrayNotHasKey('page_cache', $first->checkpoint, 'the export has no more pages');
        $this->assertTrue($connection->fresh()->config['page_cache']['readable']);
        foreach (['http://1.1.1.1/', 'http://1.1.1.1/p1/', 'http://1.1.1.1/p2/'] as $url) {
            $metadata = json_decode((string) DB::table('website_http_snapshot')->where('url', $url)->value('metadata'), true);
            $this->assertSame(200, $metadata['status_code'], $url);
            $this->assertSame('wp_page_cache', $metadata['fetch_source'], $url);
            $this->assertSame('wp_rocket', $metadata['cache_plugin'], $url);
            $this->assertSame(1, DB::table('website_heading_snapshot')->where('url', $url)->count(), $url.' headings from the same pipeline');
        }
        $this->assertSame(1, DB::table('website_html_snapshot')->where('url', 'http://1.1.1.1/p1/')->count());

        $second = $executor->execute($this->contextFrom($context, $datasetRun, $first->checkpoint));
        $this->assertSame(DatasetExecutionOutcome::Completed, $second->outcome, (string) $second->errorMessage);
        $read = collect(Http::recorded())->map(fn (array $pair): string => (string) parse_url($pair[0]->url(), PHP_URL_PATH))->all();
        $this->assertContains('/p3/', $read);
        $this->assertContains('/p4/', $read);
        foreach (['/', '/p1/', '/p2/'] as $path) {
            $this->assertNotContains($path, $read, $path.' came from the page cache and is not read again');
        }
        $this->assertSame(['page_cache' => 3, 'fetched' => 2, 'not_modified' => 0, 'same' => 0], $second->checkpoint['source_mix']);
        $state = app(WebsiteCrawlState::class)->view($this->asset->id);
        $this->assertSame(3, $state['last_run']['page_cache']);
        $this->assertTrue($state['last_run']['finished']);
        $this->assertNotNull($state['last_full_read_at'], 'a crawl that skipped nothing is a full read');

        // Next run, the cache files did not change: the unchanged path, nothing new is written for those pages.
        [$next, $nextRun] = $this->makeContext();
        $this->travel(1)->minutes();
        $again = $executor->execute($this->contextFrom($next, $nextRun, []));
        $this->assertSame('page_cache', $again->stage);
        $this->assertSame(0, $again->rowsWritten);
        $this->assertSame(3, $again->checkpoint['source_mix']['same']);
        $this->assertSame(1, DB::table('website_html_snapshot')->where('url', 'http://1.1.1.1/p1/')->count());

        // The overview shows where the pages came from and the site's cache.
        $this->seed(RoleAndPermissionSeeder::class);
        app()->setLocale('tr');
        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);
        Livewire::actingAs($admin)->test(WebsiteIntegrationIndex::class, ['assetId' => $this->asset->id])
            ->assertSee('Önbellekten: 0 · Sayfa okuma: 0 · Değişmedi (304/aynı): 3')
            ->assertSee('WP Rocket')
            ->assertSee('eklenti önbellek dosyalarını okuyor');
    }

    #[Test]
    public function an_older_connector_or_an_unreadable_cache_leaves_every_page_to_the_crawl(): void
    {
        config(['moxdop-website-intelligence.crawl.page_cache_min_queue' => 1]);
        $this->pairConnector('1.5.1');
        $this->fakeSite([]);
        [$context, $datasetRun] = $this->makeContext();

        $result = app(WebsiteDatasetExecutor::class)->execute($this->contextFrom($context, $datasetRun, []));

        $this->assertNotSame('page_cache', $result->stage);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/moxdop/v1/'));
    }

    /** @param list<array<string, mixed>> $records */
    private function fakeSite(array $records): void
    {
        $canonical = new WordPressConnectorCanonicalJson;
        Http::fake(function (Request $request) use ($records, $canonical) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_contains($path, '/moxdop/v1/')) {
                $data = str_ends_with($path, '/status')
                    ? ['schema_version' => 1, 'plugin_version' => '1.6.0', 'capabilities' => ['page_cache'], 'cache' => ['plugin' => 'wp_rocket', 'readable' => true, 'reason' => null]]
                    : ['schema_version' => 1, 'plugin_version' => '1.6.0', 'cache' => ['plugin' => 'wp_rocket', 'readable' => true, 'reason' => null],
                        'records' => $records, 'page' => 1, 'per_page' => 25, 'total' => 4, 'has_more' => false];
                $nonce = $request->header(WordPressConnectorClient::HEADER_NONCE)[0] ?? '';
                $time = now()->timestamp;

                return Http::response(['data' => $data, 'meta' => ['server_time' => $time, 'request_nonce' => $nonce,
                    'signature' => hash_hmac('sha256', implode("\n", [(string) $time, $nonce, hash('sha256', $canonical->encode($data))]), self::SECRET)]]);
            }
            if ($path === '/robots.txt') {
                return Http::response("User-agent: *\nAllow: /\n", 200, ['Content-Type' => 'text/plain']);
            }
            if (str_contains($path, 'sitemap')) {
                return Http::response('', 404);
            }

            return Http::response('<html><head><title>Canlı</title></head><body><h1>Canlı</h1></body></html>', 200, ['Content-Type' => 'text/html']);
        });
    }

    private function pairConnector(string $pluginVersion): CoreConnection
    {
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'type' => WordPressConnectorPairingService::CONNECTION_TYPE,
            'enabled' => true,
            'config' => [
                'pairing_state' => WordPressConnectorPairingService::PAIRED,
                'plugin_version' => $pluginVersion,
                'status_url' => 'http://1.1.1.1/wp-json/moxdop/v1/status',
                'snapshot_url' => 'http://1.1.1.1/wp-json/moxdop/v1/snapshot',
            ],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['client_id' => fake()->uuid(), 'shared_secret' => self::SECRET],
        ]);

        return $connection;
    }

    /** @param array<string, mixed> $checkpoint */
    private function contextFrom(DatasetExecutionContext $context, CollectionDatasetRun $datasetRun, array $checkpoint): DatasetExecutionContext
    {
        return new DatasetExecutionContext(
            collectionRun: $context->collectionRun->fresh(),
            resourceRun: $context->resourceRun->fresh(),
            datasetRun: $datasetRun->fresh(),
            checkpoint: $checkpoint,
            registryDataset: [],
            registryRequestFamily: [],
            attemptNumber: 1,
        );
    }

    /** @return array{0: DatasetExecutionContext, 1: CollectionDatasetRun} */
    private function makeContext(): array
    {
        $family = WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL;
        $definition = WebsiteRequestFamilyCatalog::definition($family);
        $run = CollectionRun::factory()->create([
            'digital_asset_id' => $this->asset->id, 'brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id,
            'status' => CollectionRunStatus::Running,
            'request_context' => ['provider_sources' => ['WEBSITE_DIRECT'], 'context' => ['collection_intent' => 'website_production_collection']],
        ]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id, 'provider_or_source' => 'WEBSITE_DIRECT', 'resource_kind' => 'website_asset_capability',
            'external_resource_id' => null, 'digital_asset_id' => $this->asset->id, 'core_asset_binding_id' => null,
            'status' => CollectionRunStatus::Running,
        ]);
        $datasetRun = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id, 'collection_resource_run_id' => $resourceRun->id, 'provider_or_source' => 'WEBSITE_DIRECT',
            'dataset_contract_id' => $definition['dataset_ids'][0], 'request_family_id' => $family, 'contract_registry_version' => 1,
            'status' => CollectionRunStatus::Running,
        ]);

        return [new DatasetExecutionContext(
            collectionRun: $run, resourceRun: $resourceRun, datasetRun: $datasetRun, checkpoint: [],
            registryDataset: [], registryRequestFamily: [], attemptNumber: 1,
        ), $datasetRun];
    }
}
