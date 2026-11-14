<?php

namespace Tests\Feature\Measurement;

use App\Enums\CustomerStatus;
use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Services\Integrations\Google\GoogleMeasurement;
use App\Services\Measurement\MeasurementSetup;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * Ölçüm kurulumu (yakup 2026-11-13): one standard per brand site; create-only writes, no double counting.
 */
final class MeasurementSetupTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private array $writes = [];

    /** @var list<string> */
    private array $keyEvents = [];

    private string $homeHtml = '<html><head></head><body>Merhaba</body></html>';

    /** How many Tag Manager calls still answer 429 (quota exceeded). */
    private int $quotaHits = 0;

    /** @var list<string> Tag Manager read urls */
    private array $gtmReads = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Sleep::fake();
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret', 'moxdop.google.developer_token' => 'devtoken']);
        $scopes = [GoogleScopes::ADWORDS, GoogleScopes::ANALYTICS_READONLY, GoogleScopes::SEARCH_CONSOLE_READONLY, ...GoogleMeasurement::SCOPES];
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['granted_scopes' => $scopes]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'developer_token' => 'devtoken']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => implode(' ', $scopes)], 'expires_at' => now()->addHour()]);

        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'domain' => 'ornekklinik.com', 'primary_url' => 'https://ornekklinik.com', 'status' => DigitalAssetStatus::Active]);
        $ads = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'module_id' => 'google_ads', 'status' => DigitalAssetStatus::Active]);
        $resource = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'external_id' => '1112223333', 'status' => CoreExternalResource::STATUS_AVAILABLE, 'metadata' => ['manager_customer_id' => '9998887777']]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $ads->id, 'external_resource_id' => $resource->id, 'capability' => 'google_ads', 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        Http::fake(function (Request $request) {
            $url = $request->url();
            $method = strtolower($request->method());
            if (str_starts_with($url, 'https://ornekklinik.com')) {
                return Http::response($this->homeHtml);
            }
            if (str_contains($url, 'googleAds:search')) {
                $query = (string) ($request->data()['query'] ?? '');
                $existing = [['conversionAction' => ['name' => 'Eski WhatsApp (GA4)', 'category' => 'CONTACT', 'status' => 'ENABLED', 'primaryForGoal' => true, 'type' => 'GOOGLE_ANALYTICS_4_CUSTOM']]];
                if (str_contains($query, 'tag_snippets')) {
                    return Http::response(['results' => array_map(fn (string $label, string $code): array => ['conversionAction' => ['name' => 'MoxDOP · '.$label, 'primaryForGoal' => true,
                        'tagSnippets' => [['eventSnippet' => "gtag('event', 'conversion', {'send_to': 'AW-555/".$code."'});"]]]], ['Telefon tıklaması', 'WhatsApp tıklaması', 'Form gönderimi'], ['tel1', 'wa1', 'form1'])]);
                }

                return Http::response(['results' => $existing]);
            }
            if (str_starts_with($url, 'https://tagmanager.googleapis.com/') && $this->quotaHits > 0) {
                $this->quotaHits--;

                return Http::response(['error' => ['code' => 429, 'message' => "Quota exceeded for quota metric 'Queries' and limit 'Queries per minute per user'", 'status' => 'RESOURCE_EXHAUSTED']], 429);
            }
            if ($method === 'get' && str_starts_with($url, 'https://tagmanager.googleapis.com/')) {
                $this->gtmReads[] = strtok($url, '?');
            }
            if ($method !== 'get') {
                $this->writes[] = [$method, $url, $request->data()];
            }

            return Http::response($this->google($method, $url, $request->data()));
        });
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function google(string $method, string $url, array $body): array
    {
        $gtm = 'https://tagmanager.googleapis.com/tagmanager/v2/accounts/42/containers';

        return match (true) {
            $method === 'post' && str_ends_with($url, 'v1beta/properties') => ['name' => 'properties/777'],
            str_ends_with($url, '/dataStreams') => $method === 'post' ? ['webStreamData' => ['measurementId' => 'G-NEW12345', 'defaultUri' => 'https://ornekklinik.com']] : ['dataStreams' => []],
            str_ends_with($url, '/keyEvents') => $method === 'post' ? ['eventName' => $body['eventName']] : ['keyEvents' => array_map(fn (string $e): array => ['eventName' => $e], $this->keyEvents)],
            str_ends_with($url, '/googleAdsLinks') => $method === 'post' ? ['customerId' => '1112223333'] : ['googleAdsLinks' => []],
            str_contains($url, 'tagmanager/v2/accounts') && str_ends_with($url, '/v2/accounts') => ['account' => [['accountId' => '42', 'name' => 'Moximu']]],
            $url === $gtm => $method === 'post' ? ['accountId' => '42', 'containerId' => '9', 'publicId' => 'GTM-NEW1'] : ['container' => []],
            str_ends_with($url, '/workspaces') => ['workspace' => [['workspaceId' => '3', 'name' => 'Default Workspace']]],
            str_contains($url, '/built_in_variables') => $method === 'post' ? [] : ['builtInVariable' => [['type' => 'pageUrl']]],
            str_ends_with($url, '/triggers') => $method === 'post' ? ['triggerId' => (string) (100 + count($this->writes))] : ['trigger' => []],
            str_ends_with($url, '/tags') => $method === 'post' ? ['tagId' => '1'] : ['tag' => []],
            str_ends_with($url, ':create_version') => ['containerVersion' => ['containerVersionId' => '5']],
            str_ends_with($url, ':publish') => ['containerVersion' => ['containerVersionId' => '5']],
            str_contains($url, 'conversionActions:mutate') => ['results' => [['resourceName' => 'x']]],
            default => [],
        };
    }

    public function test_a_new_site_gets_the_whole_standard_with_create_only_writes(): void
    {
        $result = app(MeasurementSetup::class)->apply($this->brand, ['ga4_account' => 'accounts/123', 'gtm_account' => '42']);

        $this->assertFalse($result['stopped']);
        $this->assertSame('G-NEW12345', $result['measurement_id']);
        $this->assertSame('GTM-NEW1', $result['container']);
        $this->assertSame('5', $result['published_version']);
        foreach ($this->writes as [$method, $url]) {
            $this->assertNotSame('delete', $method);
            $this->assertTrue(GoogleApiClient::measurementWriteAllowed($method, $url) || str_contains($url, 'conversionActions:mutate'), $method.' '.$url);
        }
        $keyEvents = collect($this->writes)->filter(fn ($w): bool => str_ends_with($w[1], '/keyEvents'))->pluck('2.eventName')->all();
        $this->assertSame(['phone_click', 'whatsapp_click', 'generate_lead'], $keyEvents);

        // Ads: three WEBPAGE creates; WhatsApp (CONTACT) already has a working primary action, so ours is secondary.
        $ads = collect($this->writes)->first(fn ($w): bool => str_contains($w[1], 'conversionActions:mutate'));
        $creates = collect($ads[2]['operations'])->pluck('create')->keyBy('name');
        $this->assertCount(3, $creates);
        $this->assertFalse($creates['MoxDOP · WhatsApp tıklaması']['primaryForGoal']);
        $this->assertTrue($creates['MoxDOP · Telefon tıklaması']['primaryForGoal']);
        $this->assertSame(['create'], array_unique(array_merge(...array_map('array_keys', $ads[2]['operations']))));

        $tags = collect($this->writes)->filter(fn ($w): bool => str_ends_with($w[1], '/tags'))->pluck('2.name')->all();
        $this->assertContains('MoxDOP · Google etiketi (GA4)', $tags);
        $this->assertContains('MoxDOP · Ads · WhatsApp tıklaması', $tags);
        $this->assertContains('MoxDOP · GA4 · Form gönderimi', $tags);
        $awct = collect($this->writes)->first(fn ($w): bool => ($w[2]['name'] ?? '') === 'MoxDOP · Ads · Telefon tıklaması');
        $this->assertSame('tel1', collect($awct[2]['parameter'])->firstWhere('key', 'conversionLabel')['value']);
        $this->assertTrue(collect($this->writes)->contains(fn ($w): bool => $w[0] === 'put' && str_contains($w[1], 'webmasters/v3/sites/https%3A%2F%2Fornekklinik.com%2F')));
        $this->assertFalse($result['site_has_container']);
    }

    public function test_a_tag_manager_quota_error_waits_for_the_next_minute_and_carries_on(): void
    {
        $this->quotaHits = 2;

        $result = app(MeasurementSetup::class)->apply($this->brand, ['ga4_account' => 'accounts/123', 'gtm_account' => '42', 'skip_ads' => true]);

        $this->assertFalse($result['stopped']);
        $this->assertSame('5', $result['published_version']);
        Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalSeconds === 61, 2);
    }

    public function test_tag_manager_calls_are_paced_under_the_per_minute_quota(): void
    {
        $waits = [];
        Sleep::whenFakingSleep(function ($duration) use (&$waits): void {
            $waits[] = (int) $duration->totalMilliseconds;
        });

        app(MeasurementSetup::class)->apply($this->brand, ['ga4_account' => 'accounts/123', 'gtm_account' => '42', 'skip_ads' => true]);

        // Back-to-back GTM calls wait up to ~4 s each (15 a minute), so the run never bursts past the quota.
        $this->assertGreaterThan(10, count($waits));
        $this->assertLessThanOrEqual(4100, max($waits));
        $this->assertGreaterThan(3000, max($waits));
    }

    public function test_accounts_keeps_the_tag_manager_lists_and_can_skip_containers(): void
    {
        $setup = app(MeasurementSetup::class);

        $short = $setup->accounts(['containers' => false]);
        $this->assertSame([['account_id' => '42', 'name' => 'Moximu']], $short['gtm']);
        $this->assertSame(['https://tagmanager.googleapis.com/tagmanager/v2/accounts'], $this->gtmReads);

        $full = $setup->accounts();
        $setup->accounts(['gtm_account' => '42']);
        $this->assertSame([], $full['gtm'][0]['containers']);
        $this->assertSame(1, collect($this->gtmReads)->filter(fn (string $u): bool => str_ends_with($u, '/v2/accounts'))->count());
        $this->assertSame(1, collect($this->gtmReads)->filter(fn (string $u): bool => str_ends_with($u, '/accounts/42/containers'))->count());

        $this->quotaHits = 99;
        Cache::flush();
        $failed = $setup->accounts();
        $this->assertSame([], $failed['gtm']);
        $this->assertStringContainsString('Quota exceeded', $failed['gtm_error']);
        $this->assertArrayHasKey('ga4', $failed);
    }

    public function test_a_property_that_already_counts_its_own_conversions_stops_before_tags_and_ads(): void
    {
        $this->keyEvents = ['whatsapp_tiklama'];

        $result = app(MeasurementSetup::class)->apply($this->brand, ['property_id' => '555', 'gtm_account' => '42']);

        $this->assertTrue($result['stopped']);
        $this->assertStringContainsString('whatsapp_tiklama', $result['reason']);
        $this->assertFalse(collect($this->writes)->contains(fn ($w): bool => str_contains($w[1], 'conversionActions') || str_contains($w[1], 'tagmanager')));
    }

    public function test_a_google_tag_already_hard_coded_on_the_site_is_not_loaded_twice(): void
    {
        $this->homeHtml = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-NEW12345"></script>';

        app(MeasurementSetup::class)->apply($this->brand, ['ga4_account' => 'accounts/123', 'gtm_account' => '42', 'skip_ads' => true]);

        $tags = collect($this->writes)->filter(fn ($w): bool => str_ends_with($w[1], '/tags'))->pluck('2.name')->all();
        $this->assertNotContains('MoxDOP · Google etiketi (GA4)', $tags);
        $this->assertContains('MoxDOP · GA4 · Telefon tıklaması', $tags);
    }

    public function test_without_the_measurement_permissions_nothing_runs_and_the_connect_link_is_given(): void
    {
        $integration = CoreIntegration::query()->first();
        $integration->forceFill(['config' => ['granted_scopes' => [GoogleScopes::ADWORDS]]])->save();

        try {
            app(MeasurementSetup::class)->apply($this->brand, ['ga4_account' => 'accounts/123']);
            $this->fail('should stop');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('measurement=1', $e->getMessage());
        }
        $this->assertSame([], $this->writes);
    }

    public function test_the_write_allow_list_refuses_deletes_and_other_endpoints(): void
    {
        $this->assertFalse(GoogleApiClient::measurementWriteAllowed('delete', 'https://analyticsadmin.googleapis.com/v1beta/properties/1'));
        $this->assertFalse(GoogleApiClient::measurementWriteAllowed('post', 'https://analyticsadmin.googleapis.com/v1beta/properties/1:delete'));
        $this->assertFalse(GoogleApiClient::measurementWriteAllowed('put', 'https://tagmanager.googleapis.com/tagmanager/v2/accounts/1/containers/2/workspaces/3/tags/4'));
        $this->assertTrue(GoogleApiClient::measurementWriteAllowed('post', 'https://tagmanager.googleapis.com/tagmanager/v2/accounts/1/containers/2/versions/5:publish'));
        $this->expectException(RuntimeException::class);
        app(GoogleApiClient::class)->createAdsConversionActions(CoreIntegration::query()->first(), '1112223333', [['name' => 'x', 'type' => 'UPLOAD_CLICKS']]);
    }

    public function test_the_connect_link_asks_for_the_measurement_permissions(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        CoreIntegration::query()->first()->forceFill(['config' => ['granted_scopes' => []]])->save();

        $location = (string) $this->actingAs($admin)->get(app(GoogleMeasurement::class)->connectUrl())->headers->get('Location');

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertStringContainsString('tagmanager.publish', $query['scope']);
        $this->assertStringContainsString('analytics.edit', $query['scope']);
        $this->assertSame('consent', $query['prompt']);
    }
}
