<?php

namespace Tests\Feature\Alerts;

use App\Enums\DigitalAssetStatus;
use App\Jobs\CheckAdBudgetJob;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\Alerts\AdBudgetWatch;
use App\Services\Alerts\AssetAlertScanner;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Services\Integrations\Meta\MetaApiClient;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Integrations\Meta\MetaResourceType;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Budget watch: Google Ads / Meta budget, balance, account block, capped campaigns and disapproved ads become alerts. */
final class AdBudgetWatchTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    /** @var array<int, int> */
    private array $resourceIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-23 13:00:00', 'UTC')); // 16:00 in Istanbul
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Örnek Klinik']);
    }

    public function test_meta_spend_cap_reached_blocked_account_and_disapproved_ads_open_alerts(): void
    {
        $asset = $this->metaAsset();
        $this->metaDaily($asset, 300);
        $this->fakeMeta([
            'account' => ['account_status' => 3, 'spend_cap' => '1000000', 'amount_spent' => '1000000', 'currency' => 'TRY', 'timezone_name' => 'Europe/Istanbul', 'is_prepay_account' => false],
            'today' => 0,
            'ads' => [['name' => 'İmplant video', 'effective_status' => 'DISAPPROVED']],
        ]);

        app(AdBudgetWatch::class)->check($asset);

        $alerts = AssetAlert::query()->open()->where('digital_asset_id', $asset->id)->pluck('title', 'kind')->all();
        $this->assertArrayHasKey('budget_account_blocked', $alerts);
        $this->assertArrayHasKey('budget_exhausted', $alerts);
        $this->assertSame('Meta bütçesi bitti', $alerts['budget_exhausted']);
        $this->assertArrayHasKey('ads_disapproved', $alerts);
        $this->assertArrayNotHasKey('budget_no_spend_today', $alerts, 'a blocked account already explains the zero spend');
        $this->assertSame('critical', AssetAlert::query()->where('kind', 'budget_exhausted')->value('severity'));
    }

    public function test_meta_low_prepaid_balance_and_no_spend_today(): void
    {
        $asset = $this->metaAsset();
        $this->metaDaily($asset, 300);
        $this->fakeMeta([
            'account' => ['account_status' => 1, 'spend_cap' => '0', 'amount_spent' => '5000', 'currency' => 'TRY', 'timezone_name' => 'Europe/Istanbul', 'is_prepay_account' => true],
            'funding' => 'Available balance (₺450,00 TRY)',
            'today' => 0,
            'ads' => [],
        ]);

        app(AdBudgetWatch::class)->check($asset);

        $kinds = AssetAlert::query()->open()->where('digital_asset_id', $asset->id)->pluck('kind')->all();
        $this->assertEqualsCanonicalizing(['budget_low', 'budget_no_spend_today'], $kinds);
        $this->assertStringContainsString('450', (string) AssetAlert::query()->where('kind', 'budget_low')->value('message'));
    }

    public function test_google_ads_account_budget_low_and_capped_campaign_then_stale_state_resolves(): void
    {
        $asset = $this->googleAsset();
        $this->fakeGoogle([
            'customer' => [['customer' => ['status' => 'ENABLED', 'currencyCode' => 'TRY', 'timeZone' => 'Europe/Istanbul']]],
            'campaign' => [
                ['campaign' => ['name' => 'Arama – İmplant'], 'campaignBudget' => ['amountMicros' => '200000000'], 'metrics' => ['costMicros' => '198000000']],
                ['campaign' => ['name' => 'Marka'], 'campaignBudget' => ['amountMicros' => '100000000'], 'metrics' => ['costMicros' => '20000000']],
            ],
            'account_budget' => [['accountBudget' => ['approvedSpendingLimitMicros' => '10000000000', 'amountServedMicros' => '9500000000']]],
        ]);
        for ($day = 1; $day <= 10; $day++) {
            DB::table('google_ads_account_daily')->insert([
                'digital_asset_id' => null, 'external_resource_id' => $this->resourceIds[$asset->id], 'customer_id' => '1112223333', 'reporting_date' => now()->subDays($day)->toDateString(),
                'impressions' => 1000, 'clicks' => 50, 'cost_micros' => 300_000_000, 'cost_amount' => 300, 'conversions' => 2,
                'currency' => 'TRY', 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
                'record_fingerprint' => hash('sha256', 'g'.$day), 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        app(AdBudgetWatch::class)->check($asset);

        $kinds = AssetAlert::query()->open()->where('digital_asset_id', $asset->id)->pluck('kind')->all();
        $this->assertEqualsCanonicalizing(['budget_low', 'budget_campaign_capped'], $kinds);
        $this->assertStringContainsString('Arama – İmplant', (string) AssetAlert::query()->where('kind', 'budget_campaign_capped')->value('message'));

        $this->travel(7)->hours();
        app(AssetAlertScanner::class)->scan($asset);
        $this->assertSame(0, AssetAlert::query()->open()->where('digital_asset_id', $asset->id)->whereIn('kind', ['budget_low', 'budget_campaign_capped'])->count(), 'a budget state older than 6 hours is not trusted');
    }

    public function test_command_queues_only_bound_ad_accounts_and_balance_parsing(): void
    {
        Queue::fake();
        $meta = $this->metaAsset();
        DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'meta_ads', 'status' => DigitalAssetStatus::Active, 'name' => 'Bağsız']);

        $this->artisan('moxdop:ads:budget-watch')->assertSuccessful();
        Queue::assertPushed(CheckAdBudgetJob::class, 1);
        Queue::assertPushed(CheckAdBudgetJob::class, fn ($job): bool => $job->assetId === $meta->id);

        $watch = app(AdBudgetWatch::class);
        $this->assertSame(1234.56, $watch->prepaidBalance('Available balance (₺1.234,56 TRY)'));
        $this->assertSame(1234.56, $watch->prepaidBalance('Available balance ($1,234.56 USD)'));
        $this->assertSame(1500.0, $watch->prepaidBalance('Kalan bakiye 1.500 TL'));
        $this->assertNull($watch->prepaidBalance('Kredi kartı'));
    }

    private function metaAsset(): DigitalAsset
    {
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'meta_ads', 'module_id' => 'meta-ads', 'status' => DigitalAssetStatus::Active, 'name' => 'Meta']);
        $integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['auth_method' => 'oauth', 'auth_status' => 'connected', 'connection_status' => 'connected', 'credential_status' => 'valid', 'granted_permissions' => ['ads_read', 'business_management']],
        ]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['access_token' => 'EAAG-synthetic', 'granted_permissions' => ['ads_read', 'business_management']]]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => 'meta', 'resource_type' => MetaResourceType::META_AD_ACCOUNT, 'external_id' => 'act_555',
            'status' => CoreExternalResource::STATUS_AVAILABLE, 'metadata' => ['currency' => 'TRY', 'timezone_name' => 'Europe/Istanbul', 'account_status' => 1],
        ]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => MetaAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $this->resourceIds[$asset->id] = $resource->id;

        return $asset;
    }

    private function googleAsset(): DigitalAsset
    {
        config(['moxdop.google.client_id' => 'test-client-id', 'moxdop.google.client_secret' => 'test-client-secret']);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['granted_scopes' => [GoogleScopes::ADWORDS]]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => GoogleScopes::ADWORDS],
            'expires_at' => now()->addHour(),
        ]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'module_id' => 'google_ads', 'status' => DigitalAssetStatus::Active, 'name' => 'Ads']);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'external_id' => '1112223333', 'status' => CoreExternalResource::STATUS_AVAILABLE, 'metadata' => ['currency' => 'TRY'],
        ]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => GoogleAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $this->resourceIds[$asset->id] = $resource->id;

        return $asset;
    }

    private function metaDaily(DigitalAsset $asset, float $spend): void
    {
        for ($day = 1; $day <= 10; $day++) {
            $date = now()->subDays($day)->toDateString();
            DB::table('meta_account_daily')->insert([
                'digital_asset_id' => $asset->id, 'external_resource_id' => $this->resourceIds[$asset->id], 'account_id' => '555', 'reporting_date' => $date,
                'spend' => $spend, 'impressions' => 1000, 'clicks' => 20, 'reach' => 800, 'frequency' => 1.2, 'currency' => 'TRY',
                'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', 'm'.$date),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @param  array<string, mixed>  $state */
    private function fakeMeta(array $state): void
    {
        $this->mock(MetaApiClient::class, function ($mock) use ($state): void {
            $mock->shouldReceive('get')->andReturnUsing(function ($integration, string $path, array $query) use ($state): array {
                return match (true) {
                    str_ends_with($path, '/insights') => ['data' => [['spend' => (string) $state['today']]]],
                    str_ends_with($path, '/ads') => ['data' => $state['ads']],
                    ($query['fields'] ?? '') === 'funding_source_details' => ['funding_source_details' => ['display_string' => $state['funding'] ?? '']],
                    default => $state['account'],
                };
            });
        });
    }

    /** @param  array<string, list<array<string, mixed>>>  $results */
    private function fakeGoogle(array $results): void
    {
        $this->mock(GoogleApiClient::class, function ($mock) use ($results): void {
            $mock->shouldReceive('searchAds')->andReturnUsing(function ($integration, string $customerId, string $query) use ($results): Response {
                $key = match (true) {
                    str_contains($query, 'FROM customer') => 'customer',
                    str_contains($query, 'FROM campaign') => 'campaign',
                    default => 'account_budget',
                };

                return new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode(['results' => $results[$key]])));
            });
        });
    }
}
