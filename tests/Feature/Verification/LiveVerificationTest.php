<?php

namespace Tests\Feature\Verification;

use App\Enums\CustomerStatus;
use App\Jobs\Verification\RunLiveVerificationJob;
use App\Livewire\Operator\Settings\SystemHealthPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Integrations\DataForSeo\DataForSeoProviderCredentialService;
use App\Services\Integrations\Meta\MetaProviderCredentialService;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\Verification\LiveVerifier;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use MoxDop\Website\Discovery\PublicUrlSafety;
use Tests\TestCase;

/**
 * moxdop:verify:live: one read-only call per integration and bound account, stored in live_checks, failures in
 * Komuta merkezi, section + "Şimdi doğrula" on Sistem Sağlığı.
 */
final class LiveVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private bool $adsFails = true;

    private int $metaAccountStatus = 2;

    /** @var list<array{0: string, 1: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config([
            'moxdop.google.client_id' => null, 'moxdop.google.client_secret' => null, 'moxdop.google.developer_token' => null,
            'moxdop.google.ads_api_version' => 'v25', 'moxdop.meta.access_token' => null, 'moxdop.meta.api_version' => 'v26.0',
            'moxdop.dataforseo.login' => null, 'moxdop.dataforseo.password' => null, 'moxdop.dataforseo.base_url' => 'https://api.dataforseo.com',
        ]);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $asset = fn (string $type, array $extra = []): DigitalAsset => DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => $type, 'status' => 'active'] + $extra);

        $google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $google->id]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $google->id, 'expires_at' => now()->addHour()]);
        $site = $asset('website', ['domain' => 'example.com', 'name' => 'Atlas Site']);
        $this->bind($site, $google, 'ga4', 'properties/123', 'Atlas GA4');
        $this->bind($site, $google, 'search_console', 'sc-domain:example.com', 'example.com');
        $this->bind($asset('google_ads'), $google, 'google_ads', '123-456-7890', 'Atlas Ads');
        $this->bind($asset('google_business_profile'), $google, 'google_business_profile', 'accounts/1/locations/2', 'Atlas Klinik');

        $meta = CoreIntegration::factory()->meta()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        app(MetaProviderCredentialService::class)->save($meta, ['access_token' => 'EAAG-live-check'], $this->admin);
        $this->bind($asset('meta_ads'), $meta, 'meta_ads', 'act_111', 'Atlas Meta', 'meta');

        $dfs = CoreIntegration::factory()->dataforseo()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        app(DataForSeoProviderCredentialService::class)->save($dfs, ['login' => 'agency@example.com', 'password' => 'dfs-secret'], $this->admin);

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'status_url' => 'https://example.com/wp-json/moxdop/v1/status', 'snapshot_url' => 'https://example.com/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.4.0'],
        ]);
        $secret = str_repeat('s', 43);
        CoreConnectionCredential::factory()->create(['connection_id' => $connection->id, 'encrypted_payload' => ['client_id' => 'client-1', 'shared_secret' => $secret]]);
        $this->app->instance(WordPressConnectorClient::class, new WordPressConnectorClient(new WordPressConnectorCanonicalJson, new PublicUrlSafety(fn (string $host): array => ['93.184.216.34'])));

        Http::fake(function (Request $request) use ($secret) {
            $this->sent[] = [$request->method(), $request->url()];
            $url = $request->url();

            return match (true) {
                str_starts_with($url, 'https://oauth2.googleapis.com/token') => Http::response(['access_token' => 'fresh-token', 'expires_in' => 3600, 'token_type' => 'Bearer']),
                str_contains($url, 'analyticsdata.googleapis.com') => Http::response(['rows' => [['metricValues' => [['value' => '321']]]]]),
                str_contains($url, 'webmasters/v3/sites/') => Http::response(['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteOwner']),
                str_contains($url, 'googleads.googleapis.com') => $this->adsFails
                    ? Http::response(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'The caller does not have permission']], 403)
                    : Http::response(['results' => [['customer' => ['id' => '1234567890']]]]),
                str_contains($url, 'mybusinessbusinessinformation.googleapis.com') => Http::response(['name' => 'locations/2', 'title' => 'Atlas Klinik']),
                str_contains($url, 'graph.facebook.com') && str_contains($url, '/me') => Http::response(['id' => '42', 'name' => 'Ajans']),
                str_contains($url, 'graph.facebook.com') => Http::response(['id' => 'act_111', 'account_status' => $this->metaAccountStatus]),
                str_contains($url, 'api.dataforseo.com') => Http::response(['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => [['login' => 'agency@example.com', 'timezone' => 'UTC', 'money' => ['balance' => 12.5]]]]]]),
                str_contains($url, 'example.com/wp-json/moxdop/v1/status') => $this->signed(['plugin_version' => '1.4.0', 'site_url' => 'https://example.com'], $secret, $request),
                default => Http::response(['unexpected' => $url], 500),
            };
        });
    }

    public function test_every_connection_is_checked_read_only_and_failures_reach_the_command_center(): void
    {
        $this->artisan('moxdop:verify:live', ['--sync' => true])->assertSuccessful();

        $latest = collect(LiveVerifier::latest())->keyBy(fn (array $c): string => $c['provider'].':'.$c['capability']);
        $this->assertSame('ok', $latest['google:token']['status']);
        $this->assertSame('ok', $latest['google:ga4']['status']);
        $this->assertStringContainsString('321', (string) $latest['google:ga4']['message']);
        $this->assertSame('ok', $latest['google:search_console']['status']);
        $this->assertSame('fail', $latest['google:google_ads']['status']);
        $this->assertStringContainsString('PERMISSION_DENIED', (string) $latest['google:google_ads']['message']);
        $this->assertSame('ok', $latest['google:google_business_profile']['status']);
        $this->assertSame('ok', $latest['meta:token']['status']);
        $this->assertSame('fail', $latest['meta:meta_ads']['status'], 'a disabled ad account cannot deliver');
        $this->assertStringContainsString('DISABLED', (string) $latest['meta:meta_ads']['message']);
        $this->assertSame('ok', $latest['dataforseo:seo_data']['status']);
        $this->assertSame('ok', $latest['wordpress:connector']['status']);
        $this->assertNotNull($latest['google:ga4']['latency_ms']);

        // Read-only: every POST is a token refresh, a GA4 report or a GAQL search; nothing else is written.
        foreach ($this->sent as [$method, $url]) {
            if ($method !== 'GET') {
                $this->assertMatchesRegularExpression('#(oauth2\.googleapis\.com/token|:runReport|googleAds:search)#', $url);
            }
        }
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'googleAds:search') && str_contains($request->body(), 'SELECT customer.id FROM customer LIMIT 1'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'act_111') && $request['fields'] === 'id,account_status');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'appendix/user_data'));
        // No secrets are stored in messages.
        $this->assertSame(0, DB::table('live_checks')->where('message', 'like', '%EAAG%')->orWhere('message', 'like', '%dfs-secret%')->orWhere('message', 'like', '%fresh-token%')->count());

        $this->assertSame(2, DB::table('live_checks')->where('status', 'fail')->count());

        // Fixed on the provider side: the next run clears the items.
        $this->adsFails = false;
        $this->metaAccountStatus = 1;
        $this->artisan('moxdop:verify:live', ['--sync' => true])->assertSuccessful();
        $this->assertSame(18, DB::table('live_checks')->count(), 'two runs × 9 checks are kept');
    }

    public function test_an_unpaid_meta_account_is_readable_with_a_warning_not_a_failure(): void
    {
        $this->metaAccountStatus = 3;

        $this->artisan('moxdop:verify:live', ['--sync' => true])->assertSuccessful();

        $meta = collect(LiveVerifier::latest())->firstWhere('capability', 'meta_ads');
        $this->assertSame('ok', $meta['status']);
        $this->assertStringContainsString('UNSETTLED', (string) $meta['message']);
    }

    public function test_broken_google_authorization_fails_the_token_and_skips_its_accounts(): void
    {
        $google = CoreIntegration::query()->where('provider', 'google')->firstOrFail();
        $google->forceFill(['config' => ['auth_status' => 'revoked']])->save();

        app(LiveVerifier::class)->run();

        $latest = collect(LiveVerifier::latest())->where('provider', 'google');
        $this->assertSame('fail', $latest->firstWhere('capability', 'token')['status']);
        $this->assertSame(['skipped'], $latest->where('capability', '!=', 'token')->pluck('status')->unique()->values()->all());
        $this->assertFalse(collect($this->sent)->contains(fn (array $s): bool => str_contains($s[1], 'googleapis.com')));
    }

    public function test_old_rows_are_pruned_and_system_health_shows_the_section_and_queues_a_run(): void
    {
        DB::table('live_checks')->insert(['check_key' => 'old', 'provider' => 'google', 'capability' => 'token', 'subject_type' => 'integration', 'subject_id' => 1,
            'label' => 'Eski', 'status' => 'fail', 'latency_ms' => 1, 'message' => null, 'checked_at' => now()->subDays(40)]);
        app(LiveVerifier::class)->run();
        $this->assertSame(0, DB::table('live_checks')->where('check_key', 'old')->count());

        Queue::fake();
        Livewire::test(SystemHealthPage::class)
            ->assertSee('Canlı doğrulama')->assertSee('Atlas GA4')->assertSee('Şimdi doğrula')
            ->call('verifyNow')->assertSet('message', 'Canlı doğrulama sıraya alındı; sonuçlar birkaç dakika içinde burada görünür.');
        Queue::assertPushed(RunLiveVerificationJob::class);

        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);
        Livewire::test(SystemHealthPage::class)->call('verifyNow')->assertForbidden();
    }

    private function bind(DigitalAsset $asset, CoreIntegration $integration, string $capability, string $externalId, string $name, string $provider = 'google'): void
    {
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => $provider, 'resource_type' => $capability,
            'external_id' => $externalId, 'display_name' => $name, 'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => $capability, 'status' => CoreAssetBinding::STATUS_ACTIVE]);
    }

    /** @param array<string, mixed> $data */
    private function signed(array $data, string $secret, Request $request): mixed
    {
        $nonce = $request->header(WordPressConnectorClient::HEADER_NONCE)[0] ?? '';
        $time = now()->timestamp;
        $signature = hash_hmac('sha256', implode("\n", [(string) $time, $nonce, hash('sha256', (new WordPressConnectorCanonicalJson)->encode($data))]), $secret);

        return Http::response(['data' => $data, 'meta' => ['server_time' => $time, 'request_nonce' => $nonce, 'signature' => $signature]]);
    }
}
