<?php

namespace Tests\Feature\Verification;

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
use App\Services\Verification\DataConsistencyChecker;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Roles;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/**
 * moxdop:verify:data — missing days, spend without paid GA4 sessions, Ads vs GA4 conversions, currency mismatch;
 * "Veri şüpheli" in Komuta merkezi, cleared when the condition is gone. Facts are written the production way
 * (compact store on PostgreSQL).
 */
final class DataConsistencyCheckerTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private Brand $brand;

    private Customer $customer;

    private DigitalAsset $ads;

    private CoreExternalResource $adsResource;

    private DigitalAsset $site;

    private DigitalAsset $meta;

    private CoreExternalResource $metaResource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00', 'Europe/Istanbul'));
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $this->customer = Customer::factory()->create(['status' => CustomerStatus::Active]);
        $this->brand = Brand::factory()->create(['customer_id' => $this->customer->id, 'name' => 'Örnek Klinik']);

        config(['moxdop.google.client_id' => 'test-client-id', 'moxdop.google.client_secret' => 'test-client-secret']);
        $google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['granted_scopes' => [GoogleScopes::ADWORDS]]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $google->id]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $google->id, 'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => GoogleScopes::ADWORDS], 'expires_at' => now()->addHour(),
        ]);

        $this->ads = $this->asset('google_ads', 'Örnek Ads');
        $this->adsResource = $this->bind($this->ads, $google, 'google', GoogleResourceType::GOOGLE_ADS_CUSTOMER, 'google_ads', '1112223333', ['currency' => 'TRY']);
        $this->site = $this->asset('website', 'Örnek Site');
        $this->bind($this->site, $google, 'google', 'ga4', 'ga4', 'properties/123');
        $this->bind($this->site, $google, 'google', 'search_console', 'search_console', 'sc-domain:ornek.test');

        $metaIntegration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['auth_method' => 'oauth', 'auth_status' => 'connected', 'connection_status' => 'connected', 'credential_status' => 'valid', 'granted_permissions' => ['ads_read', 'business_management']],
        ]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $metaIntegration->id, 'encrypted_payload' => ['access_token' => 'EAAG-synthetic', 'granted_permissions' => ['ads_read']]]);
        $this->meta = $this->asset('meta_ads', 'Örnek Meta');
        $this->metaResource = $this->bind($this->meta, $metaIntegration, 'meta', MetaResourceType::META_AD_ACCOUNT, 'meta_ads', 'act_555', ['currency' => 'TRY', 'timezone_name' => 'Europe/Istanbul']);

        // Production PostgreSQL keeps GA4 source / medium facts compact (the logical table is a view).
        if (DB::getDriverName() === 'pgsql') {
            $this->artisan('moxdop:db:compact', ['--execute' => true, '--table' => 'ga4_source_medium_daily', '--reserve-gb' => 0])->assertSuccessful();
        }

    }

    public function test_suspicious_data_is_flagged_listed_and_cleared_when_fixed(): void
    {
        foreach ($this->window() as $day) {
            if ($day !== '2026-09-15') {
                $this->adsDay($day, 100.0, 5.0);
            }
            $this->insertFact('ga4_property_daily', $this->pool($this->site, ['property_id' => '123', 'reporting_date' => $day, 'sessions' => 50, 'keyEvents' => 6]));
            $this->sourceMedium($day, 'google', 'organic', 30, 1);
            // Paid sessions stop after 12 September: auto-tagging broke.
            if ($day <= '2026-09-12') {
                $this->sourceMedium($day, 'google', 'cpc', 20, 3);
            }
            // A quiet Search Console property with a hole is not flagged (too little activity to tell).
            if ($day !== '2026-09-16') {
                $this->insertFact('gsc_property_daily', $this->pool($this->site, ['site_url' => 'sc-domain:ornek.test', 'reporting_date' => $day, 'clicks' => 0, 'impressions' => 3, 'search_type' => 'web', 'metadata' => '{}']));
            }
            $this->metaDay($day, 40.0);
        }

        $result = app(DataConsistencyChecker::class)->run();

        $open = DB::table('data_consistency_issues')->whereNull('resolved_at')->get()->keyBy('kind');
        $this->assertEqualsCanonicalizing(['missing_days', 'ads_untagged', 'conversion_divergence'], $open->keys()->all());
        $this->assertSame(3, $result['open']);
        $gap = json_decode((string) $open['missing_days']->data, true);
        $this->assertSame('google_ads', $gap['capability']);
        $this->assertSame(['2026-09-15'], $gap['dates']);
        $this->assertCount(9, json_decode((string) $open['ads_untagged']->data, true)['dates'], '13–22 Sept with spend, no google / cpc session (15th had no spend)');
        $divergence = json_decode((string) $open['conversion_divergence']->data, true);
        $this->assertEqualsWithDelta(65.0, $divergence['ads_conversions'], 0.01);
        $this->assertEqualsWithDelta(12.0, $divergence['ga4_key_events'], 0.01);

        $again = app(DataConsistencyChecker::class)->run();
        $this->assertSame(0, $again['new'], 'the same findings are updated, not duplicated');
        $this->assertSame(3, DB::table('data_consistency_issues')->count());

        // Fixed: the day is re-collected, tagging works again, conversions line up, invoices move to TRY.
        $this->adsDay('2026-09-15', 100.0, 0.0);
        foreach ($this->window() as $day) {
            if ($day > '2026-09-12') {
                $this->sourceMedium($day, 'google', 'cpc', 20, 5);
            }
        }
        DB::table('google_ads_account_daily')->update(['conversions' => 3]);

        $fixed = app(DataConsistencyChecker::class)->run();
        $this->assertSame(0, $fixed['open']);
        $this->assertSame(3, $fixed['resolved']);
    }

    public function test_quiet_or_unbound_accounts_and_missing_source_medium_data_raise_nothing(): void
    {
        // Ads spends but GA4 source / medium is not collected at all: nothing can be concluded.
        foreach ($this->window() as $day) {
            $this->adsDay($day, 100.0, 20.0);
            $this->insertFact('ga4_property_daily', $this->pool($this->site, ['property_id' => '123', 'reporting_date' => $day, 'sessions' => 50, 'keyEvents' => 1]));
        }
        CoreAssetBinding::query()->where('capability', 'meta_ads')->update(['status' => CoreAssetBinding::STATUS_DISABLED]);

        $this->artisan('moxdop:verify:data', ['--sync' => true])->expectsOutputToContain('0 open')->assertSuccessful();
        $this->assertSame(0, DB::table('data_consistency_issues')->count());
    }

    public function test_a_brand_whose_ad_accounts_spend_in_different_currencies_is_flagged(): void
    {
        foreach ($this->window() as $day) {
            $this->adsDay($day, 100.0, 1.0);
            $this->metaDay($day, 40.0);
        }
        $this->metaResource->update(['metadata' => array_merge((array) $this->metaResource->metadata, ['currency' => 'USD'])]);

        app(DataConsistencyChecker::class)->run();

        $issue = DB::table('data_consistency_issues')->whereNull('resolved_at')->where('kind', 'mixed_currency')->sole();
        $this->assertSame(['TRY', 'USD'], json_decode((string) $issue->data, true)['currencies']);
        $this->assertStringContainsString('farklı para birimleri', (string) $issue->title);
    }

    /** @return list<string> the 14 complete days before "today" (23 Sept, Istanbul) */
    private function window(): array
    {
        $days = [];
        for ($day = CarbonImmutable::parse('2026-09-09'); $day->lte(CarbonImmutable::parse('2026-09-22')); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    private function asset(string $type, string $name): DigitalAsset
    {
        return DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => $type, 'status' => DigitalAssetStatus::Active, 'name' => $name]);
    }

    /** @param array<string, mixed> $metadata */
    private function bind(DigitalAsset $asset, CoreIntegration $integration, string $provider, string $type, string $capability, string $externalId, array $metadata = []): CoreExternalResource
    {
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => $provider, 'resource_type' => $type, 'external_id' => $externalId,
            'status' => CoreExternalResource::STATUS_AVAILABLE, 'metadata' => $metadata,
        ]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => $capability, 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        return $resource;
    }

    private function adsDay(string $day, float $cost, float $conversions): void
    {
        $this->insertFact('google_ads_account_daily', $this->pool($this->ads, [
            'external_resource_id' => $this->adsResource->id, 'customer_id' => '1112223333', 'reporting_date' => $day,
            'impressions' => 1000, 'clicks' => 50, 'cost_micros' => (int) ($cost * 1_000_000), 'cost_amount' => $cost, 'conversions' => $conversions, 'currency' => 'TRY',
        ]));
    }

    private function metaDay(string $day, float $spend): void
    {
        $this->insertFact('meta_account_daily', $this->pool($this->meta, [
            'external_resource_id' => $this->metaResource->id, 'account_id' => '555', 'reporting_date' => $day,
            'spend' => $spend, 'impressions' => 2000, 'clicks' => 30, 'reach' => 1500, 'currency' => 'TRY',
        ]));
    }

    private function sourceMedium(string $day, string $source, string $medium, int $sessions, int $keyEvents): void
    {
        $this->insertFact('ga4_source_medium_daily', $this->pool($this->site, [
            'property_id' => '123', 'reporting_date' => $day, 'sessionSource' => $source, 'sessionMedium' => $medium,
            'sessions' => $sessions, 'engagedSessions' => $sessions, 'keyEvents' => $keyEvents,
        ]));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function pool(DigitalAsset $asset, array $values): array
    {
        return $values + [
            'digital_asset_id' => $asset->id, 'external_resource_id' => null, 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'source_timezone' => 'Europe/Istanbul',
            'record_fingerprint' => hash('sha256', $asset->id.json_encode($values)), 'created_at' => now(), 'updated_at' => now(),
        ];
    }
}
