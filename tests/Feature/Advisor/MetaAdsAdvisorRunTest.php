<?php

namespace Tests\Feature\Advisor;

use App\Enums\AdvisorItemStatus;
use App\Enums\DigitalAssetStatus;
use App\Jobs\DraftMetaAdsCreativeJob;
use App\Livewire\Operator\Advisor\AdvisorPanel;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Advisor\AdvisorPlanRunner;
use App\Services\Advisor\MetaAds\MetaAdsAdvisorInputCollector;
use App\Services\Advisor\MetaAds\MetaAdsAdvisorRuleEngine;
use App\Services\Advisor\MetaAds\MetaAdsCreativeDrafter;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Meta Ads advisor end-to-end: stored meta_* rows → run → items → panel, plus silence and draft limits.
 */
final class MetaAdsAdvisorRunTest extends TestCase
{
    use RefreshDatabase;

    private const string ACCOUNT = '11110001';

    private DigitalAsset $asset;

    private CoreExternalResource $resource;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00', 'Europe/Istanbul'));
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Örnek Klinik']);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'meta_ads', 'module_id' => 'meta-ads', 'status' => DigitalAssetStatus::Active, 'name' => 'Örnek Klinik Meta']);
        $integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['auth_method' => 'oauth', 'auth_status' => 'connected', 'connection_status' => 'connected', 'credential_status' => 'valid', 'granted_permissions' => ['ads_read', 'business_management']],
        ]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['access_token' => 'EAAG-synthetic', 'granted_permissions' => ['ads_read']]]);
        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => 'meta', 'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_'.self::ACCOUNT, 'display_name' => 'Örnek Meta', 'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['currency' => 'TRY', 'timezone_name' => 'Europe/Istanbul', 'account_status' => 1],
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id, 'external_resource_id' => $this->resource->id,
            'capability' => MetaAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    public function test_run_finds_fatigue_saturation_learning_waste_pixel_and_placement_issues(): void
    {
        $this->seedAccount();

        $input = app(MetaAdsAdvisorInputCollector::class)->collect($this->asset);
        $this->assertTrue($input['bound']);
        $this->assertSame('lead', $input['campaigns']['c1']['result_type']);
        $this->assertEqualsWithDelta(90.0, $input['campaigns']['c1']['results'], 0.01);
        $this->assertSame(0.0, $input['campaigns']['c2']['results']);

        $runner = app(AdvisorPlanRunner::class);
        $plan = $runner->run($runner->queue($this->asset, $this->admin)->id);
        $this->assertSame(AdvisorPlan::STATUS_COMPLETED, $plan->status);
        $this->assertSame(AdvisorPlan::CHANNEL_META_ADS, $plan->channel);
        $rules = AdvisorItem::query()->pluck('rule_id')->all();
        foreach (['creative-fatigue', 'audience-saturation', 'learning-limited', 'spend-no-results', 'pixel-health', 'delivery-outliers'] as $rule) {
            $this->assertContains($rule, $rules, $rule.' should fire');
        }
        $fatigue = AdvisorItem::query()->where('rule_id', 'creative-fatigue')->firstOrFail();
        $this->assertSame('Implant videosu', $fatigue->evidence['ad']);
        $pixel = AdvisorItem::query()->where('rule_id', 'pixel-health')->firstOrFail();
        $this->assertCount(2, $pixel->evidence['issues'], 'stale pixel + ad set pointing to an unknown pixel');
        $this->assertSame('audience_network · an_classic', AdvisorItem::query()->where('rule_id', 'delivery-outliers')->firstOrFail()->evidence['segments'][0]['segment']);

        // Panel: global Meta filter, account tab, draft request.
        Livewire::test(AdvisorPanel::class)->set('channelFilter', 'meta_ads')->assertSee('Kreatif yoruldu: Implant videosu')->assertSee('Meta Ads');
        Queue::fake();
        Livewire::test(AdvisorPanel::class, ['assetId' => $this->asset->id])->call('requestDraft', $fatigue->id)->assertSee('taslağı hazırlanıyor');
        Queue::assertPushed(DraftMetaAdsCreativeJob::class);
        $this->get(route('operator.meta.overview', ['assetId' => $this->asset->id, 'tab' => 'advisor']))->assertOk()->assertSee('Danışman');

        // Placement breakdown gone next week → that item closes itself.
        DB::table('meta_analysis_breakdown_daily')->delete();
        $runner->run($runner->queue($this->asset, $this->admin)->id);
        $this->assertSame(AdvisorItemStatus::Resolved, AdvisorItem::query()->where('rule_id', 'delivery-outliers')->firstOrFail()->status);
    }

    public function test_silent_without_binding_or_spend_and_draft_limits(): void
    {
        $engine = new MetaAdsAdvisorRuleEngine;
        $this->assertSame(['not_bound'], $engine->evaluate(['bound' => false])['silenced']);
        $this->assertSame(['low_spend'], $engine->evaluate(['bound' => true, 'account' => ['has_data' => true, 'spend' => 50.0]])['silenced']);

        CoreAssetBinding::query()->update(['status' => 'inactive']);
        $runner = app(AdvisorPlanRunner::class);
        $this->assertSame('Meta Ads hesabı bağlı değil.', $runner->run($runner->queue($this->asset, $this->admin)->id)->summary_text);

        $draft = app(MetaAdsCreativeDrafter::class)->validate([
            'concepts' => ['Uzman hekim anlatıyor'],
            'primary_texts' => ['Kısa metin. Hemen randevu al.', str_repeat('x', 251)],
            'headlines' => ['İmplant tedavisi', str_repeat('y', 41)],
            'descriptions' => ['Ücretsiz muayene', str_repeat('z', 31)],
            'notes' => 'Kontrol et.',
        ]);
        $this->assertSame(['Kısa metin. Hemen randevu al.'], $draft['primary_texts']);
        $this->assertSame(['İmplant tedavisi'], $draft['headlines']);
        $this->assertSame(['Ücretsiz muayene'], $draft['descriptions']);
    }

    private function seedAccount(): void
    {
        $end = now('Europe/Istanbul')->toImmutable()->subDay()->startOfDay();
        $this->row('meta_campaign_snapshot', ['campaign_id' => 'c1', 'metadata' => json_encode(['name' => 'Lead Kampanya', 'objective' => 'OUTCOME_LEADS', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => '50'])]);
        $this->row('meta_campaign_snapshot', ['campaign_id' => 'c2', 'metadata' => json_encode(['name' => 'Satış Deneme', 'objective' => 'OUTCOME_SALES', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => '40'])]);
        $this->row('meta_adset_snapshot', ['adset_id' => 's1', 'metadata' => json_encode(['name' => 'Lead Geniş', 'campaign_id' => 'c1', 'optimization_goal' => 'LEAD_GENERATION', 'effective_status' => 'ACTIVE', 'daily_budget' => '50'])]);
        $this->row('meta_adset_snapshot', ['adset_id' => 's2', 'metadata' => json_encode(['name' => 'Satış İlgi', 'campaign_id' => 'c2', 'optimization_goal' => 'OFFSITE_CONVERSIONS', 'effective_status' => 'ACTIVE', 'daily_budget' => '40'])]);
        $this->row('meta_adset_targeting_snapshot', ['adset_id' => 's2', 'campaign_id' => 'c2', 'adset_name' => 'Satış İlgi', 'optimization_goal' => 'OFFSITE_CONVERSIONS', 'promoted_object' => json_encode(['pixel_id' => '999', 'custom_event_type' => 'PURCHASE']), 'targeting' => json_encode(['age_min' => 25])]);
        $this->row('meta_ad_snapshot', ['ad_id' => 'a1', 'ad_name' => 'Implant videosu', 'campaign_id' => 'c1', 'adset_id' => 's1', 'creative_id' => 'cr1', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
        $this->row('meta_ad_snapshot', ['ad_id' => 'a2', 'ad_name' => 'Ürün görseli', 'campaign_id' => 'c2', 'adset_id' => 's2', 'creative_id' => 'cr2', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
        $this->row('meta_creative_snapshot', ['creative_id' => 'cr1', 'metadata' => json_encode(['title' => 'İmplantta uzman ekip', 'body' => 'Randevunu al.', 'call_to_action_type' => 'LEARN_MORE', 'link_url' => 'https://ornek.test/implant/'])]);
        $this->row('meta_conversion_source_snapshot', ['source_type' => 'PIXEL', 'source_id' => 'p1', 'source_name' => 'Site pikseli', 'pixel_id' => 'p1', 'last_fired_time' => '2026-09-10 10:00:00', 'is_unavailable' => false]);

        for ($day = 0; $day < 30; $day++) {
            $date = $end->subDays($day)->toDateString();
            $recent = $day < 7;
            $this->row('meta_campaign_daily', ['reporting_date' => $date, 'campaign_id' => 'c1', 'spend' => 50, 'impressions' => 5000, 'clicks' => 120, 'reach' => $recent ? 1800 : 3300, 'frequency' => $recent ? 2.8 : 1.5, 'currency' => 'TRY', 'metadata' => json_encode(['inline_link_clicks' => $recent ? 50 : 100])]);
            $this->row('meta_campaign_daily', ['reporting_date' => $date, 'campaign_id' => 'c2', 'spend' => 40, 'impressions' => 2000, 'clicks' => 30, 'reach' => 1600, 'frequency' => 1.2, 'currency' => 'TRY', 'metadata' => json_encode(['inline_link_clicks' => 20])]);
            $this->row('meta_ad_daily', ['reporting_date' => $date, 'ad_id' => 'a1', 'spend' => 50, 'impressions' => 5000, 'clicks' => 120, 'reach' => $recent ? 2300 : 3300, 'currency' => 'TRY', 'metadata' => json_encode(['inline_link_clicks' => $recent ? 50 : 100, 'frequency' => $recent ? 2.2 : 1.5, 'campaign_id' => 'c1', 'adset_id' => 's1'])]);
            $this->row('meta_ad_daily', ['reporting_date' => $date, 'ad_id' => 'a2', 'spend' => 40, 'impressions' => 2000, 'clicks' => 30, 'reach' => 1600, 'currency' => 'TRY', 'metadata' => json_encode(['inline_link_clicks' => 20, 'frequency' => 1.2, 'campaign_id' => 'c2', 'adset_id' => 's2'])]);
            $this->row('meta_typed_action_daily', ['reporting_date' => $date, 'entity_level' => 'ad', 'entity_id' => 'a1', 'action_type' => 'lead', 'action_value' => 3, 'currency' => 'TRY']);
            $this->row('meta_typed_action_daily', ['reporting_date' => $date, 'entity_level' => 'ad', 'entity_id' => 'a2', 'action_type' => 'link_click', 'action_value' => 20, 'currency' => 'TRY']);
        }
        $this->row('meta_analysis_breakdown_daily', ['reporting_date' => $end->toDateString(), 'breakdown_type' => 'placement', 'breakdown_key' => json_encode(['publisher_platform' => 'audience_network', 'platform_position' => 'an_classic']), 'spend' => 400, 'impressions' => 20000, 'clicks' => 20, 'reach' => 9000, 'currency' => 'TRY']);
        $this->row('meta_analysis_breakdown_daily', ['reporting_date' => $end->toDateString(), 'breakdown_type' => 'placement', 'breakdown_key' => json_encode(['publisher_platform' => 'facebook', 'platform_position' => 'feed']), 'spend' => 2000, 'impressions' => 100000, 'clicks' => 2000, 'reach' => 40000, 'currency' => 'TRY']);
    }

    /** @param array<string, mixed> $values */
    private function row(string $table, array $values): void
    {
        DB::table($table)->insert($values + [
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'account_id' => self::ACCOUNT,
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'Europe/Istanbul',
            'record_fingerprint' => hash('sha256', $table.json_encode($values)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
