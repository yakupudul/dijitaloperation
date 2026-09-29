<?php

namespace Tests\Support;

use App\Enums\CustomerStatus;
use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Roles;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A dental brand (Panorama Ankara: main services Diş İmplantı + Zirkonyum Kaplama, branch in Ankara) with a bound
 * Meta ad account 777 and 56 days of collected rows (2026-09-04 … 2026-10-29) built so every system check fires:
 *  - c1 "Diş İmplantı Lead Ankara" / as1 (Ankara, lead goal) / ad1 → site page with UTM; 2 leads a day now, 4 before;
 *    CTR 2 % → 0,5 % in the last 7 days at daily frequency 2 (fatigue);
 *  - c2 "Genel Trafik" (lead objective) / as2 (İzmir, LINK_CLICKS) / ad2 (DISAPPROVED) → other domain, no UTM, 0 results;
 *  - pixel last fired 2026-10-15.
 * Current 28 days: spend 4 200, 56 results (CPR 75); previous: 4 200, 112 results (CPR 37,5).
 */
trait SeedsMetaAccount
{
    protected User $admin;

    protected Brand $brand;

    protected DigitalAsset $asset;

    protected CoreExternalResource $resource;

    protected DigitalAsset $site;

    protected function seedMetaAccount(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 09:00:00', 'UTC'));
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $customer = Customer::factory()->create(['status' => CustomerStatus::Active]);
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Panorama Ankara', 'sector_id' => $dental->id, 'languages' => ['tr', 'en']]);
        foreach ([['Diş İmplantı', 'main'], ['Zirkonyum Kaplama', 'main'], ['Ortodonti', 'secondary']] as [$name, $priority]) {
            $service = app(ServiceCatalogService::class)->resolveOrCreate($name, 'dental', actor: $this->admin)['service'];
            BrandOffering::query()->create(['brand_id' => $this->brand->id, 'service_catalog_item_id' => $service->id, 'status' => 'active', 'priority' => $priority, 'locked' => true]);
        }
        BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'name' => 'Çankaya şubesi', 'country_code' => 'TR', 'city_name' => 'Ankara', 'district_name' => 'Çankaya',
            'normalized_key' => 'tr-ankara-cankaya', 'physical_branch' => true, 'status' => 'active']);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'panorama.test', 'domain' => 'panorama.test', 'primary_url' => 'https://panorama.test/']);
        Page::query()->create(['website_asset_id' => $this->site->id, 'url' => 'https://panorama.test/implant/', 'url_hash' => hash('sha256', 'implant'), 'path' => '/implant/',
            'title' => 'Diş İmplantı Ankara', 'category' => 'hizmet', 'language' => 'tr', 'is_indexable' => true, 'content_summary' => 'İmplant tedavisi süreci, muayene ve randevu.']);

        $this->asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'meta_ads', 'module_id' => 'meta-ads', 'status' => DigitalAssetStatus::Active, 'name' => 'Panorama Meta']);
        $integration = CoreIntegration::factory()->meta()->create(['status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['auth_method' => 'oauth', 'auth_status' => 'connected', 'connection_status' => 'connected', 'credential_status' => 'valid', 'granted_permissions' => ['ads_read', 'business_management']]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['access_token' => 'synthetic', 'granted_permissions' => ['ads_read', 'business_management']]]);
        $this->resource = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'meta', 'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_777', 'status' => CoreExternalResource::STATUS_AVAILABLE, 'metadata' => ['currency' => 'TRY', 'timezone_name' => 'Europe/Istanbul', 'account_status' => 1]]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->asset->id, 'external_resource_id' => $this->resource->id, 'capability' => MetaAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        $this->snapshot('meta_campaign_snapshot', ['campaign_id' => 'c1'], ['name' => 'Diş İmplantı Lead Ankara', 'objective' => 'OUTCOME_LEADS', 'effective_status' => 'ACTIVE', 'daily_budget' => '150.000000']);
        $this->snapshot('meta_campaign_snapshot', ['campaign_id' => 'c2'], ['name' => 'Genel Trafik', 'objective' => 'OUTCOME_LEADS', 'effective_status' => 'ACTIVE', 'daily_budget' => '50.000000']);
        $this->snapshot('meta_adset_snapshot', ['adset_id' => 'as1'], ['name' => 'İmplant Ankara 35+', 'campaign_id' => 'c1', 'optimization_goal' => 'LEAD_GENERATION', 'effective_status' => 'ACTIVE',
            'attribution_spec' => [['event_type' => 'CLICK_THROUGH', 'window_days' => 7], ['event_type' => 'VIEW_THROUGH', 'window_days' => 1]]]);
        $this->snapshot('meta_adset_snapshot', ['adset_id' => 'as2'], ['name' => 'İzmir geniş', 'campaign_id' => 'c2', 'optimization_goal' => 'LINK_CLICKS', 'effective_status' => 'ACTIVE']);
        $this->snapshot('meta_creative_snapshot', ['creative_id' => 'cr1'], ['name' => 'İmplant video', 'title' => 'Diş İmplantı Ankara', 'body' => 'Çankaya’da implant muayenesi için randevu alın.',
            'link_url' => 'https://panorama.test/implant/?utm_source=facebook&utm_medium=paid_social', 'thumbnail_url' => 'https://cdn.test/thumb-1.jpg', 'video_id' => 'v1']);
        $this->snapshot('meta_creative_snapshot', ['creative_id' => 'cr2'], ['name' => 'Trafik görseli', 'title' => 'Kampanya', 'body' => 'Sitemizi ziyaret edin.', 'link_url' => 'https://baska.test/kampanya']);
        $this->professional('meta_ad_snapshot', ['ad_id' => 'ad1', 'ad_name' => 'İmplant video reklamı', 'campaign_id' => 'c1', 'adset_id' => 'as1', 'creative_id' => 'cr1', 'effective_status' => 'ACTIVE']);
        $this->professional('meta_ad_snapshot', ['ad_id' => 'ad2', 'ad_name' => 'Trafik görsel reklamı', 'campaign_id' => 'c2', 'adset_id' => 'as2', 'creative_id' => 'cr2', 'effective_status' => 'DISAPPROVED']);
        $this->professional('meta_adset_targeting_snapshot', ['adset_id' => 'as1', 'campaign_id' => 'c1', 'adset_name' => 'İmplant Ankara 35+', 'optimization_goal' => 'LEAD_GENERATION',
            'targeting' => json_encode(['geo_locations' => ['cities' => [['key' => '1', 'name' => 'Ankara']]]])]);
        $this->professional('meta_adset_targeting_snapshot', ['adset_id' => 'as2', 'campaign_id' => 'c2', 'adset_name' => 'İzmir geniş', 'optimization_goal' => 'LINK_CLICKS',
            'targeting' => json_encode(['geo_locations' => ['cities' => [['key' => '2', 'name' => 'İzmir']]]])]);
        $this->professional('meta_conversion_source_snapshot', ['source_type' => 'PIXEL', 'source_id' => 'px1', 'source_name' => 'Panorama Pixel', 'last_fired_time' => '2026-10-15 10:00:00', 'is_unavailable' => false]);

        $start = CarbonImmutable::parse('2026-09-04');
        for ($i = 0; $i < 56; $i++) {
            $date = $start->addDays($i)->toDateString();
            $current = $i >= 28;
            $lastWeek = $i >= 49;
            $this->daily('ad1', 'c1', 'as1', $date, 100, 2000, $lastWeek ? 10 : 40, 1000);
            $this->daily('ad2', 'c2', 'as2', $date, 50, 1000, 5, 900);
            $this->action('ad1', $date, 'lead', $current ? 2 : 4);
            $this->action('ad1', $date, 'onsite_conversion.lead_grouped', $current ? 2 : 4);
        }
    }

    /** @param  array<string, mixed>  $key  @param  array<string, mixed>  $metadata */
    protected function snapshot(string $table, array $key, array $metadata): void
    {
        DB::table($table)->insert($key + ['digital_asset_id' => $this->asset->id, 'external_resource_id' => $this->resource->id, 'account_id' => '777',
            'metadata' => json_encode($metadata)] + $this->provenance($table.json_encode($key)));
    }

    /** @param  array<string, mixed>  $row */
    protected function professional(string $table, array $row): void
    {
        DB::table($table)->insert($row + ['digital_asset_id' => $this->asset->id, 'external_resource_id' => $this->resource->id, 'account_id' => '777'] + $this->provenance($table.json_encode($row)));
    }

    protected function daily(string $ad, string $campaign, string $adset, string $date, float $spend, int $impressions, int $clicks, int $reach): void
    {
        DB::table('meta_ad_daily')->insert(['digital_asset_id' => $this->asset->id, 'external_resource_id' => $this->resource->id, 'account_id' => '777', 'reporting_date' => $date,
            'ad_id' => $ad, 'spend' => $spend, 'impressions' => $impressions, 'clicks' => $clicks, 'reach' => $reach, 'currency' => 'TRY',
            'metadata' => json_encode(['campaign_id' => $campaign, 'adset_id' => $adset])] + $this->provenance($ad.$date));
    }

    protected function action(string $ad, string $date, string $type, float $value): void
    {
        DB::table('meta_typed_action_daily')->insert(['digital_asset_id' => $this->asset->id, 'external_resource_id' => $this->resource->id, 'account_id' => '777', 'reporting_date' => $date,
            'entity_level' => 'ad', 'entity_id' => $ad, 'action_type' => $type, 'action_value' => $value, 'currency' => 'TRY'] + $this->provenance($ad.$date.$type));
    }

    /** @return array<string, mixed> */
    private function provenance(string $seed): array
    {
        return ['contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $seed), 'created_at' => now(), 'updated_at' => now()];
    }
}
