<?php

namespace Tests\Feature\Queries;

use App\Enums\CustomerStatus;
use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Queries\KeywordPlanner;
use App\Services\Queries\QuerySourceAggregator;
use App\Support\Integrations\Google\GoogleScopes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Anahtar Kelime Planlayıcı (yakup, 2026-10-09): read-only search ideas for the brand's services enter the query
 * library as raw rows (source keyword_planner) with their monthly searches; ideas outside the brand's services or
 * searched too rarely are left out; a brand is asked once in 30 days.
 */
final class KeywordPlannerTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $requests = [];

    public function test_ideas_for_the_brands_services_enter_the_library_with_their_searches(): void
    {
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret', 'moxdop.google.developer_token' => 'devtoken']);
        $sector = ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'sector_id' => $sector->id, 'name' => 'Burcu Kısa']);
        $service = app(ServiceCatalogService::class)->resolveOrCreate('İmplant Tedavisi', 'saglik', actor: User::factory()->create())['service'];
        DB::table('service_matching_keywords')->insert(['service_catalog_item_id' => $service->id, 'label' => 'implant', 'normalized_key' => 'implant', 'created_at' => now(), 'updated_at' => now()]);
        BrandOffering::query()->create(['brand_id' => $brand->id, 'service_catalog_item_id' => $service->id, 'status' => 'active', 'priority' => 'main']);

        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['granted_scopes' => [GoogleScopes::ADWORDS]]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'developer_token' => 'devtoken']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => GoogleScopes::ADWORDS], 'expires_at' => now()->addHour()]);
        CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => 'google_ads', 'external_id' => '1112223333',
            'status' => CoreExternalResource::STATUS_AVAILABLE, 'metadata' => ['login_customer_id' => '9998887777']]);
        $site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'status' => DigitalAssetStatus::Active]);
        $gsc = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => 'search_console', 'external_id' => 'sc-domain:burcukisa.test',
            'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $site->id, 'external_resource_id' => $gsc->id, 'capability' => 'search_console', 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'oauth2')) {
                return Http::response(['access_token' => 'fresh', 'expires_in' => 3600]);
            }
            if (str_contains($request->url(), ':generateKeywordIdeas')) {
                $this->requests[] = ['url' => $request->url(), 'login' => $request->header('login-customer-id')[0] ?? null, 'body' => $request->data()];

                return Http::response(['results' => [
                    ['text' => 'implant fiyatları', 'keywordIdeaMetrics' => ['avgMonthlySearches' => '1900', 'competitionIndex' => '80', 'lowTopOfPageBidMicros' => '2000000', 'highTopOfPageBidMicros' => '6000000',
                        'monthlySearchVolumes' => [['month' => 'MARCH', 'year' => '2026', 'monthlySearches' => '2400']]]],
                    ['text' => 'diş beyazlatma', 'keywordIdeaMetrics' => ['avgMonthlySearches' => '5000']],
                    ['text' => 'implant ağrısı', 'keywordIdeaMetrics' => ['avgMonthlySearches' => '10']],
                ]]);
            }

            return Http::response([], 404);
        });

        $result = app(KeywordPlanner::class)->forBrand($brand);

        $this->assertSame('ready', $result['status'], (string) $result['message']);
        $this->assertSame(1, $result['kept'], 'another service and a rare search are left out');
        $this->assertCount(1, $this->requests);
        $this->assertStringContainsString('customers/1112223333:generateKeywordIdeas', $this->requests[0]['url']);
        $this->assertSame('9998887777', $this->requests[0]['login']);
        $this->assertSame(['geoTargetConstants/2792'], $this->requests[0]['body']['geoTargetConstants']);
        $this->assertSame('languageConstants/1037', $this->requests[0]['body']['language']);
        $this->assertContains('implant tedavisi', $this->requests[0]['body']['keywordSeed']['keywords']);

        $row = DB::table('query_sources')->where('source', KeywordPlanner::SOURCE)->sole();
        $this->assertSame((int) $gsc->id, (int) $row->external_resource_id, 'on the brand\'s own account, so the library knows the brand');
        $this->assertSame('implant fiyatları', $row->raw_query);
        $this->assertSame(1900, (int) $row->impressions);
        $volume = DB::table('query_volumes')->sole();
        $this->assertSame(1900, (int) $volume->volume);
        $this->assertSame(4.0, (float) $volume->cpc);
        $this->assertSame([['year' => 2026, 'month' => 3, 'volume' => 2400]], json_decode((string) $volume->monthly, true));

        app(QuerySourceAggregator::class)->aggregate((int) $gsc->id, CarbonImmutable::now(), CarbonImmutable::now());
        $this->assertSame(1, DB::table('query_sources')->where('source', KeywordPlanner::SOURCE)->count(), 'a Search Console refresh keeps the planner rows');

        $this->artisan('moxdop:queries:keyword-planner')->assertSuccessful();
        $this->assertCount(1, $this->requests, 'asked once in 30 days');
        $this->assertSame('ready', KeywordPlanner::lastRun((int) $brand->id)['status']);
    }
}
