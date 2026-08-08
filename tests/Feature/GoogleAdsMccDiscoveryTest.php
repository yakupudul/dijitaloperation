<?php

namespace Tests\Feature;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Services\Integrations\Google\Discovery\GoogleAdsDiscoverer;
use App\Services\Integrations\Google\GoogleProviderCredentialService;
use App\Services\Integrations\Google\GoogleResourceRefreshService;
use App\Support\Integrations\Google\GoogleOAuthConfig;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAdsMccDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'app.url' => 'http://127.0.0.1:8000',
            'moxdop.google.client_id' => null,
            'moxdop.google.client_secret' => null,
            'moxdop.google.redirect_uri' => null,
            'moxdop.google.developer_token' => null,
            'moxdop.google.ads_api_version' => 'v25',
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);

        $this->integration = CoreIntegration::factory()->google()->create();

        app(GoogleProviderCredentialService::class)->save($this->integration, [
            'client_id' => 'cid',
            'client_secret' => 'csecret',
            'developer_token' => 'ads-dev-token',
        ], $this->admin);

        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'atok',
                'refresh_token' => 'rtok',
            ],
            'expires_at' => now()->addHour(),
        ]);
    }

    public function test_mcc_customer_client_hierarchy_discovers_descriptive_names_and_manager_metadata(): void
    {
        $listUrl = GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers');
        $mccSearchUrl = GoogleOAuthConfig::adsApiUrl('customers/1111111111/googleAds:search');

        Http::fake(function ($request) use ($listUrl, $mccSearchUrl) {
            if ($request->url() === $listUrl) {
                return Http::response(['resourceNames' => ['customers/1111111111']], 200);
            }

            if ($request->url() === $mccSearchUrl) {
                $this->assertSame('POST', $request->method());
                $this->assertSame('ads-dev-token', $request->header('developer-token')[0] ?? null);
                $this->assertSame('1111111111', $request->header('login-customer-id')[0] ?? null);
                $body = $request->data();
                $this->assertStringContainsString('customer_client', (string) ($body['query'] ?? ''));
                $this->assertStringNotContainsString('mutate', strtolower($request->url()));

                return Http::response([
                    'results' => [
                        [
                            'customerClient' => [
                                'id' => 1111111111,
                                'descriptiveName' => 'Moximu MCC',
                                'manager' => true,
                                'level' => 0,
                                'status' => 'ENABLED',
                                'currencyCode' => 'TRY',
                                'timeZone' => 'Europe/Istanbul',
                                'testAccount' => false,
                                'clientCustomer' => 'customers/1111111111',
                            ],
                        ],
                        [
                            'customerClient' => [
                                'id' => 2222222222,
                                'descriptiveName' => 'Panorama Ankara',
                                'manager' => false,
                                'level' => 1,
                                'status' => 'ENABLED',
                                'currencyCode' => 'TRY',
                                'timeZone' => 'Europe/Istanbul',
                                'testAccount' => false,
                                'clientCustomer' => 'customers/2222222222',
                            ],
                        ],
                        [
                            'customerClient' => [
                                'id' => 3333333333,
                                'descriptiveName' => 'Indirect Client',
                                'manager' => false,
                                'level' => 2,
                                'status' => 'ENABLED',
                                'clientCustomer' => 'customers/3333333333',
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'unexpected '.$request->url()], 500);
        });

        $result = app(GoogleAdsDiscoverer::class)->discover($this->integration->fresh(['credential', 'providerCredential']));
        $this->assertSame('ok', $result->status);
        $this->assertCount(3, $result->resources);

        $byId = [];
        foreach ($result->resources as $resource) {
            $byId[$resource->externalId] = $resource;
        }

        $this->assertSame('Panorama Ankara', $byId['2222222222']->displayName);
        $this->assertSame('1111111111', $byId['2222222222']->metadata['login_customer_id'] ?? null);
        $this->assertSame('1111111111', $byId['2222222222']->metadata['manager_customer_id'] ?? null);
        $this->assertSame('222-222-2222', $byId['2222222222']->metadata['customer_id_formatted'] ?? null);
        $this->assertSame('Moximu MCC', $byId['1111111111']->displayName);
        $this->assertTrue((bool) ($byId['1111111111']->metadata['is_manager'] ?? false));
        $this->assertSame('Indirect Client', $byId['3333333333']->displayName);
    }

    public function test_refresh_persists_descriptive_ads_resources_without_duplicates(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'listAccessibleCustomers')) {
                return Http::response(['resourceNames' => ['customers/1111111111']], 200);
            }
            if (str_contains($request->url(), 'googleAds:search')) {
                return Http::response([
                    'results' => [
                        [
                            'customerClient' => [
                                'id' => 2222222222,
                                'descriptiveName' => 'Panorama Ankara',
                                'manager' => false,
                                'level' => 1,
                                'clientCustomer' => 'customers/2222222222',
                            ],
                        ],
                        [
                            'customerClient' => [
                                'id' => 2222222222,
                                'descriptiveName' => 'Panorama Ankara',
                                'manager' => false,
                                'level' => 1,
                                'clientCustomer' => 'customers/2222222222',
                            ],
                        ],
                    ],
                ], 200);
            }
            if (str_contains($request->url(), 'webmasters')) {
                return Http::response(['siteEntry' => []], 200);
            }
            if (str_contains($request->url(), 'analyticsadmin')) {
                return Http::response(['accountSummaries' => []], 200);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });

        $refresh = app(GoogleResourceRefreshService::class)->refresh($this->integration->fresh(['credential', 'providerCredential']));
        $this->assertSame('ok', $refresh['results']['google_ads']['status']);

        $this->assertSame(1, CoreExternalResource::query()
            ->where('resource_type', 'google_ads')
            ->where('external_id', '2222222222')
            ->count());

        $resource = CoreExternalResource::query()
            ->where('external_id', '2222222222')
            ->firstOrFail();

        $this->assertSame('Panorama Ankara', $resource->display_name);
        $this->assertSame('1111111111', data_get($resource->metadata, 'login_customer_id'));
        $this->assertNull(data_get($resource->metadata, 'access_token'));
    }

    public function test_developer_token_comes_from_provider_credential_store(): void
    {
        Http::fake([
            GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers') => Http::response([
                'resourceNames' => [],
            ], 200),
        ]);

        $result = app(GoogleAdsDiscoverer::class)->discover($this->integration->fresh(['credential', 'providerCredential']));
        $this->assertSame('ok', $result->status);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'listAccessibleCustomers')
                && ($request->header('developer-token')[0] ?? null) === 'ads-dev-token';
        });
    }
}
