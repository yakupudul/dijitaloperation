<?php

namespace Tests\Feature;

use App\Livewire\Demo\Integrations\GoogleIntegrationPage;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\DigitalAsset;
use App\Models\GoogleIntegrationDiscoveryAttempt;
use App\Models\User;
use App\Services\Integrations\Google\DiscoverGoogleResourcesService;
use App\Services\Integrations\Google\GoogleIntegrationReadModel;
use App\Support\Integrations\Google\GoogleOAuthConfig;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class GoogleResourceDiscoveryTest extends TestCase
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
            'moxdop.google.client_id' => 'test-client.apps.googleusercontent.com',
            'moxdop.google.client_secret' => 'test-client-secret',
            'moxdop.google.developer_token' => 'test-dev-token',
            'moxdop.google.include_gbp_scope' => true,
            'moxdop.google.gbp_discovery_enabled' => true,
            'moxdop.google.ads_api_version' => 'v25',
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        $this->integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [
                'granted_scopes' => [
                    GoogleScopes::ANALYTICS_READONLY,
                    GoogleScopes::SEARCH_CONSOLE_READONLY,
                    GoogleScopes::ADWORDS,
                    GoogleScopes::BUSINESS_MANAGE,
                ],
            ],
        ]);

        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'client_id' => 'test-client.apps.googleusercontent.com',
                'client_secret' => 'test-client-secret',
                'developer_token' => 'test-dev-token',
            ],
        ]);

        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'atok',
                'refresh_token' => 'rtok',
                'scope' => implode(' ', [
                    GoogleScopes::ANALYTICS_READONLY,
                    GoogleScopes::SEARCH_CONSOLE_READONLY,
                    GoogleScopes::ADWORDS,
                    GoogleScopes::BUSINESS_MANAGE,
                ]),
            ],
            'expires_at' => now()->addHour(),
        ]);
    }

    public function test_ga4_pagination_and_idempotent_refresh(): void
    {
        Http::fake([
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::sequence()
                ->push([
                    'accountSummaries' => [[
                        'account' => 'accounts/1',
                        'displayName' => 'Acct',
                        'propertySummaries' => [[
                            'property' => 'properties/111',
                            'displayName' => 'Prop A',
                        ]],
                    ]],
                    'nextPageToken' => 'page-2',
                ], 200)
                ->push([
                    'accountSummaries' => [[
                        'account' => 'accounts/1',
                        'displayName' => 'Acct',
                        'propertySummaries' => [[
                            'property' => 'properties/222',
                            'displayName' => 'Prop B',
                        ]],
                    ]],
                ], 200)
                ->push([
                    'accountSummaries' => [[
                        'account' => 'accounts/1',
                        'displayName' => 'Acct',
                        'propertySummaries' => [
                            ['property' => 'properties/111', 'displayName' => 'Prop A'],
                            ['property' => 'properties/222', 'displayName' => 'Prop B'],
                        ],
                    ]],
                ], 200),
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => []], 200),
            GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers') => Http::response(['resourceNames' => []], 200),
            'https://mybusinessaccountmanagement.googleapis.com/v1/accounts' => Http::response(['accounts' => []], 200),
            'https://mybusinessbusinessinformation.googleapis.com/v1/*' => Http::response(['locations' => []], 200),
        ]);

        $service = app(DiscoverGoogleResourcesService::class);
        $first = $service->discover($this->integration->fresh(['authorizationCredential', 'providerCredential']));
        $this->assertTrue($first['ok']);
        $this->assertSame('ok', $first['results']['ga4']['status']);
        $this->assertSame(2, CoreExternalResource::query()->where('resource_type', 'ga4')->count());

        $service->discover($this->integration->fresh(['authorizationCredential', 'providerCredential']));
        $this->assertSame(2, CoreExternalResource::query()->where('resource_type', 'ga4')->count());
        $this->assertSame(0, DigitalAsset::query()->count());
        $this->assertSame(0, CoreAssetBinding::query()->count());
    }

    public function test_ga4_rename_updates_same_external_resource(): void
    {
        Http::fake([
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::sequence()
                ->push([
                    'accountSummaries' => [[
                        'account' => 'accounts/1',
                        'displayName' => 'Acct',
                        'propertySummaries' => [[
                            'property' => 'properties/999',
                            'displayName' => 'Old Name',
                        ]],
                    ]],
                ], 200)
                ->push([
                    'accountSummaries' => [[
                        'account' => 'accounts/1',
                        'displayName' => 'Acct',
                        'propertySummaries' => [[
                            'property' => 'properties/999',
                            'displayName' => 'New Name',
                        ]],
                    ]],
                ], 200),
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => []], 200),
            GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers') => Http::response(['resourceNames' => []], 200),
            'https://mybusinessaccountmanagement.googleapis.com/v1/accounts' => Http::response(['accounts' => []], 200),
            'https://mybusinessbusinessinformation.googleapis.com/v1/*' => Http::response(['locations' => []], 200),
        ]);

        $service = app(DiscoverGoogleResourcesService::class);
        $service->discover($this->freshIntegration());
        $service->discover($this->freshIntegration());

        $this->assertSame(1, CoreExternalResource::query()->where('resource_type', 'ga4')->count());
        $this->assertSame('New Name', CoreExternalResource::query()->where('external_id', 'properties/999')->value('display_name'));
    }

    public function test_gsc_preserves_exact_provider_identity_and_permission(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteFullUser'],
                    ['siteUrl' => 'https://www.example.com/', 'permissionLevel' => 'siteOwner'],
                ],
            ], 200),
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response(['accountSummaries' => []], 200),
            GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers') => Http::response(['resourceNames' => []], 200),
            'https://mybusinessaccountmanagement.googleapis.com/v1/accounts' => Http::response(['accounts' => []], 200),
            'https://mybusinessbusinessinformation.googleapis.com/v1/*' => Http::response(['locations' => []], 200),
        ]);

        app(DiscoverGoogleResourcesService::class)->discover($this->freshIntegration());

        $domain = CoreExternalResource::query()->where('external_id', 'sc-domain:example.com')->firstOrFail();
        $this->assertSame('domain', $domain->metadata['property_form'] ?? null);
        $this->assertSame('siteFullUser', $domain->metadata['permission_level'] ?? null);

        $urlPrefix = CoreExternalResource::query()->where('external_id', 'https://www.example.com/')->firstOrFail();
        $this->assertSame('url_prefix', $urlPrefix->metadata['property_form'] ?? null);
    }

    public function test_google_ads_hierarchy_dedup_and_nested_managers(): void
    {
        $listUrl = GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers');
        $mccUrl = GoogleOAuthConfig::adsApiUrl('customers/1111111111/googleAds:search');
        $nestedUrl = GoogleOAuthConfig::adsApiUrl('customers/3333333333/googleAds:search');

        Http::fake(function ($request) use ($listUrl, $mccUrl, $nestedUrl) {
            if ($request->url() === $listUrl) {
                return Http::response([
                    'resourceNames' => ['customers/1111111111', 'customers/3333333333'],
                ], 200);
            }

            if ($request->url() === $mccUrl) {
                return Http::response([
                    'results' => [
                        ['customerClient' => [
                            'id' => 1111111111,
                            'descriptiveName' => 'Root MCC',
                            'manager' => true,
                            'level' => 0,
                            'clientCustomer' => 'customers/1111111111',
                        ]],
                        ['customerClient' => [
                            'id' => 2222222222,
                            'descriptiveName' => 'Client A',
                            'manager' => false,
                            'level' => 1,
                            'clientCustomer' => 'customers/2222222222',
                        ]],
                        ['customerClient' => [
                            'id' => 3333333333,
                            'descriptiveName' => 'Nested MCC',
                            'manager' => true,
                            'level' => 1,
                            'clientCustomer' => 'customers/3333333333',
                        ]],
                    ],
                ], 200);
            }

            if ($request->url() === $nestedUrl) {
                return Http::response([
                    'results' => [
                        ['customerClient' => [
                            'id' => 3333333333,
                            'descriptiveName' => 'Nested MCC',
                            'manager' => true,
                            'level' => 0,
                            'clientCustomer' => 'customers/3333333333',
                        ]],
                        ['customerClient' => [
                            'id' => 2222222222,
                            'descriptiveName' => 'Client A via nested',
                            'manager' => false,
                            'level' => 1,
                            'clientCustomer' => 'customers/2222222222',
                        ]],
                        ['customerClient' => [
                            'id' => 4444444444,
                            'descriptiveName' => 'Client B',
                            'manager' => false,
                            'level' => 1,
                            'clientCustomer' => 'customers/4444444444',
                        ]],
                    ],
                ], 200);
            }

            if (str_contains($request->url(), 'webmasters') || str_contains($request->url(), 'accountSummaries')) {
                return Http::response([], 200);
            }

            if (str_contains($request->url(), 'mybusiness')) {
                return Http::response(['accounts' => [], 'locations' => []], 200);
            }

            return Http::response([], 404);
        });

        $result = app(DiscoverGoogleResourcesService::class)->discover($this->freshIntegration());
        $this->assertSame('ok', $result['results']['google_ads']['status']);

        $ads = CoreExternalResource::query()->where('resource_type', 'google_ads')->get();
        $this->assertSame(4, $ads->count());
        $this->assertSame(1, $ads->where('external_id', '2222222222')->count());
        $client = $ads->firstWhere('external_id', '2222222222');
        $this->assertFalse((bool) ($client->metadata['is_manager'] ?? true));
        $this->assertTrue((bool) ($client->metadata['selectable'] ?? false));
    }

    public function test_gbp_locations_pagination_and_external_access_isolation(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [['siteUrl' => 'sc-domain:ok.com', 'permissionLevel' => 'siteOwner']],
            ], 200),
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response([
                'accountSummaries' => [[
                    'account' => 'accounts/1',
                    'displayName' => 'A',
                    'propertySummaries' => [['property' => 'properties/1', 'displayName' => 'P']],
                ]],
            ], 200),
            GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers') => Http::response(['resourceNames' => []], 200),
            'https://mybusinessaccountmanagement.googleapis.com/v1/accounts' => Http::response([
                'accounts' => [['name' => 'accounts/gbp1', 'accountName' => 'GBP Acct']],
            ], 200),
            'https://mybusinessbusinessinformation.googleapis.com/v1/accounts/-/locations*' => Http::sequence()
                ->push([
                    'locations' => [[
                        'name' => 'locations/loc-1',
                        'title' => 'Store 1',
                        'storeCode' => 'S1',
                    ]],
                    'nextPageToken' => 'next',
                ], 200)
                ->push([
                    'locations' => [[
                        'name' => 'locations/loc-2',
                        'title' => 'Store 2',
                    ]],
                ], 200),
        ]);

        $result = app(DiscoverGoogleResourcesService::class)->discover($this->freshIntegration());
        $this->assertSame('ok', $result['results']['ga4']['status']);
        $this->assertSame('ok', $result['results']['search_console']['status']);
        $this->assertSame('ok', $result['results']['google_business_profile']['status']);
        $this->assertSame(2, CoreExternalResource::query()->where('resource_type', 'google_business_profile')->count());
        $this->assertDatabaseHas('google_integration_discovery_attempts', [
            'connector' => 'google_business_profile',
            'status' => 'ok',
        ]);
    }

    public function test_gbp_external_access_required_does_not_fail_other_connectors(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [['siteUrl' => 'sc-domain:ok.com', 'permissionLevel' => 'siteOwner']],
            ], 200),
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response([
                'accountSummaries' => [[
                    'account' => 'accounts/1',
                    'displayName' => 'A',
                    'propertySummaries' => [['property' => 'properties/1', 'displayName' => 'P']],
                ]],
            ], 200),
            GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers') => Http::response(['resourceNames' => []], 200),
            'https://mybusinessaccountmanagement.googleapis.com/v1/accounts' => Http::response([], 403),
        ]);

        $result = app(DiscoverGoogleResourcesService::class)->discover($this->freshIntegration());
        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['results']['ga4']['status']);
        $this->assertSame('ok', $result['results']['search_console']['status']);
        $this->assertSame('external_access_required', $result['results']['google_business_profile']['status']);
        $this->assertSame(0, CoreExternalResource::query()->where('resource_type', 'google_business_profile')->count());
        $this->assertGreaterThan(0, CoreExternalResource::query()->where('resource_type', 'ga4')->count());
    }

    public function test_gbp_scope_required_distinct_from_external_access(): void
    {
        config(['moxdop.google.include_gbp_scope' => false]);

        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => []], 200),
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response(['accountSummaries' => []], 200),
            GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers') => Http::response(['resourceNames' => []], 200),
        ]);

        $result = app(DiscoverGoogleResourcesService::class)->discover($this->freshIntegration());
        $this->assertSame('scope_required', $result['results']['google_business_profile']['status']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'mybusiness'));
    }

    public function test_gbp_zero_locations_is_completed_success(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => []], 200),
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response(['accountSummaries' => []], 200),
            GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers') => Http::response(['resourceNames' => []], 200),
            'https://mybusinessaccountmanagement.googleapis.com/v1/accounts' => Http::response(['accounts' => []], 200),
            'https://mybusinessbusinessinformation.googleapis.com/v1/*' => Http::response(['locations' => []], 200),
        ]);

        $result = app(DiscoverGoogleResourcesService::class)->discover($this->freshIntegration());
        $this->assertSame('ok', $result['results']['google_business_profile']['status']);
        $this->assertSame(0, $result['results']['google_business_profile']['count']);
        $this->assertTrue($result['results']['google_business_profile']['complete_inventory']);
    }

    public function test_connector_failure_isolation_and_partial_does_not_mark_unavailable(): void
    {
        CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'ga4',
            'external_id' => 'properties/keep-me',
            'display_name' => 'Keep',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        Http::fake([
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::sequence()
                ->push([
                    'accountSummaries' => [[
                        'account' => 'accounts/1',
                        'displayName' => 'A',
                        'propertySummaries' => [
                            ['property' => 'properties/keep-me', 'displayName' => 'Keep'],
                            ['property' => 'properties/new', 'displayName' => 'New'],
                        ],
                    ]],
                    'nextPageToken' => 'page-2',
                ], 200)
                ->push(['error' => 'boom'], 500),
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([], 500),
            GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers') => Http::response(['resourceNames' => []], 200),
            'https://mybusinessaccountmanagement.googleapis.com/v1/accounts' => Http::response(['accounts' => []], 200),
            'https://mybusinessbusinessinformation.googleapis.com/v1/*' => Http::response(['locations' => []], 200),
        ]);

        $result = app(DiscoverGoogleResourcesService::class)->discover($this->freshIntegration());
        $this->assertSame('partial', $result['results']['ga4']['status']);
        $this->assertSame('error', $result['results']['search_console']['status']);
        $this->assertSame('ok', $result['results']['google_ads']['status']);

        $keep = CoreExternalResource::query()->where('external_id', 'properties/keep-me')->firstOrFail();
        $this->assertSame(CoreExternalResource::STATUS_AVAILABLE, $keep->status);
        $this->assertDatabaseHas('core_external_resources', [
            'external_id' => 'properties/new',
            'status' => 'available',
        ]);
    }

    public function test_complete_absence_marks_unavailable_without_hard_delete(): void
    {
        CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'ga4',
            'external_id' => 'properties/gone',
            'display_name' => 'Gone',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        Http::fake([
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response([
                'accountSummaries' => [[
                    'account' => 'accounts/1',
                    'displayName' => 'A',
                    'propertySummaries' => [['property' => 'properties/stay', 'displayName' => 'Stay']],
                ]],
            ], 200),
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => []], 200),
            GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers') => Http::response(['resourceNames' => []], 200),
            'https://mybusinessaccountmanagement.googleapis.com/v1/accounts' => Http::response(['accounts' => []], 200),
            'https://mybusinessbusinessinformation.googleapis.com/v1/*' => Http::response(['locations' => []], 200),
        ]);

        app(DiscoverGoogleResourcesService::class)->discover($this->freshIntegration());

        $gone = CoreExternalResource::query()->where('external_id', 'properties/gone')->firstOrFail();
        $this->assertSame(CoreExternalResource::STATUS_UNAVAILABLE, $gone->status);
        $this->assertNotNull($gone->id);
    }

    public function test_failed_refresh_preserves_previous_inventory(): void
    {
        CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'search_console',
            'external_id' => 'sc-domain:keep.com',
            'display_name' => 'keep.com',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([], 500),
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response([], 500),
            GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers') => Http::response([], 500),
            'https://mybusinessaccountmanagement.googleapis.com/v1/accounts' => Http::response([], 500),
        ]);

        $before = CoreExternalResource::query()->count();
        app(DiscoverGoogleResourcesService::class)->discover($this->freshIntegration());
        $this->assertSame($before, CoreExternalResource::query()->count());
        $this->assertSame(
            CoreExternalResource::STATUS_AVAILABLE,
            CoreExternalResource::query()->where('external_id', 'sc-domain:keep.com')->value('status'),
        );
    }

    public function test_page_render_does_not_call_google_and_discover_action_works(): void
    {
        Http::fake();

        $detail = app(GoogleIntegrationReadModel::class)->detail();
        $this->assertTrue($detail['actions']['discover']);
        Http::assertNothingSent();

        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => []], 200),
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response(['accountSummaries' => []], 200),
            GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers') => Http::response(['resourceNames' => []], 200),
            'https://mybusinessaccountmanagement.googleapis.com/v1/accounts' => Http::response(['accounts' => []], 200),
            'https://mybusinessbusinessinformation.googleapis.com/v1/*' => Http::response(['locations' => []], 200),
        ]);

        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(GoogleIntegrationPage::class)
            ->call('discoverResources')
            ->assertOk();

        $this->assertGreaterThan(0, GoogleIntegrationDiscoveryAttempt::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_unauthorized_user_cannot_discover(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Roles::TEAM_MEMBER);

        $result = app(DiscoverGoogleResourcesService::class)->discover($this->freshIntegration(), $user);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('authorized operators', $result['message']);
    }

    public function test_missing_known_scope_skips_provider_call(): void
    {
        $this->integration->forceFill([
            'config' => [
                'granted_scopes' => [GoogleScopes::ANALYTICS_READONLY],
            ],
        ])->save();

        Http::fake([
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response(['accountSummaries' => []], 200),
            'https://mybusinessaccountmanagement.googleapis.com/v1/accounts' => Http::response(['accounts' => []], 200),
            'https://mybusinessbusinessinformation.googleapis.com/v1/*' => Http::response(['locations' => []], 200),
            GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers') => Http::response(['resourceNames' => []], 200),
        ]);

        $result = app(DiscoverGoogleResourcesService::class)->discover($this->freshIntegration());
        $this->assertSame('scope_required', $result['results']['search_console']['status']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'webmasters'));
    }

    public function test_discovery_does_not_serialize_tokens_in_attempts(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => []], 200),
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response(['accountSummaries' => []], 200),
            GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers') => Http::response(['resourceNames' => []], 200),
            'https://mybusinessaccountmanagement.googleapis.com/v1/accounts' => Http::response(['accounts' => []], 200),
            'https://mybusinessbusinessinformation.googleapis.com/v1/*' => Http::response(['locations' => []], 200),
        ]);

        app(DiscoverGoogleResourcesService::class)->discover($this->freshIntegration(), $this->admin);

        $json = GoogleIntegrationDiscoveryAttempt::query()->get()->toJson();
        $this->assertStringNotContainsString('atok', $json);
        $this->assertStringNotContainsString('rtok', $json);
        $this->assertStringNotContainsString('test-dev-token', $json);
        $this->assertStringNotContainsString('test-client-secret', $json);
    }

    private function freshIntegration(): CoreIntegration
    {
        return $this->integration->fresh(['authorizationCredential', 'providerCredential']) ?? $this->integration;
    }
}
