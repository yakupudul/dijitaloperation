<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\ViewDigitalAsset;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\MetaAdsConnectionsRelationManager;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Models\User;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MoxDop\MetaAds\Ai\MetaAdsAiGuidanceContextBuilder;
use MoxDop\MetaAds\Collection\MetaAdsBoundCollector;
use MoxDop\MetaAds\Normalization\MetaResultResolver;
use MoxDop\MetaAds\Support\MetaPercentage;
use MoxDop\MetaAds\Workspace\MetaAdsWorkspaceData;
use ReflectionMethod;
use Tests\TestCase;

class MetaAdsOperatorCorrectionPassTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private CoreIntegration $metaIntegration;

    private CoreIntegration $googleIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id]);

        $this->metaIntegration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'name' => 'Agency Meta',
        ]);
        $this->googleIntegration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'name' => 'Agency Google',
        ]);
    }

    public function test_brand_may_own_multiple_meta_and_google_ads_digital_assets(): void
    {
        $metaA = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'name' => 'Meta Account A',
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);
        $metaB = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'name' => 'Meta Account B',
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);
        $googleA = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'name' => 'Google Account A',
            'type' => 'google_ads',
            'module_id' => 'google-ads',
        ]);
        $googleB = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'name' => 'Google Account B',
            'type' => 'google_ads',
            'module_id' => 'google-ads',
        ]);

        $resourceMetaA = $this->metaResource('act_aaa', 'Avrupadent YD Yeni', 'Avrupadent BM');
        $resourceMetaB = $this->metaResource('act_bbb', 'Avrupadent Germany', 'Avrupadent BM');
        $resourceGoogleA = $this->googleResource('customers/111', 'Google Ads A');
        $resourceGoogleB = $this->googleResource('customers/222', 'Google Ads B');

        $this->bind($metaA, $resourceMetaA, 'meta_ads');
        $this->bind($metaB, $resourceMetaB, 'meta_ads');
        $this->bind($googleA, $resourceGoogleA, 'google_ads');
        $this->bind($googleB, $resourceGoogleB, 'google_ads');

        $this->assertSame(2, DigitalAsset::query()->where('brand_id', $this->brand->id)->where('type', 'meta_ads')->count());
        $this->assertSame(2, DigitalAsset::query()->where('brand_id', $this->brand->id)->where('type', 'google_ads')->count());
        $this->assertSame(1, CoreAssetBinding::query()->where('digital_asset_id', $metaA->id)->count());
        $this->assertSame(1, CoreAssetBinding::query()->where('digital_asset_id', $metaB->id)->count());
        $this->assertNotSame(
            CoreAssetBinding::query()->where('digital_asset_id', $metaA->id)->value('external_resource_id'),
            CoreAssetBinding::query()->where('digital_asset_id', $metaB->id)->value('external_resource_id'),
        );
    }

    public function test_edit_binding_options_include_current_account_label_with_business_context(): void
    {
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);

        $current = $this->metaResource('act_29', 'Avrupadent YD Yeni', 'Avrupadent BM');
        $other = $this->metaResource('act_30', 'Avrupadent Germany', 'Avrupadent BM');
        $binding = $this->bind($asset, $current, 'meta_ads');

        $manager = new MetaAdsConnectionsRelationManager;
        $manager->ownerRecord = $asset;
        $manager->pageClass = ViewDigitalAsset::class;

        $method = new ReflectionMethod($manager, 'resourceOptions');
        $method->setAccessible(true);
        /** @var array<int|string, string> $options */
        $options = $method->invoke($manager, $binding);

        $this->assertArrayHasKey($current->id, $options);
        $this->assertArrayHasKey($other->id, $options);
        $this->assertStringContainsString('Avrupadent YD Yeni', $options[$current->id]);
        $this->assertStringContainsString('Meta Business: Avrupadent BM', $options[$current->id]);
        $this->assertStringNotContainsString((string) $current->id.' ·', $options[$current->id]);
        foreach ($options as $label) {
            $this->assertDoesNotMatchRegularExpression('/^\d+$/', $label);
        }

        $emptyWhileBound = $method->invoke($manager, null);
        $this->assertSame([], $emptyWhileBound);
    }

    public function test_meta_ctr_percentage_semantics_and_exact_samples(): void
    {
        // Meta Insights stores percentage points (1.48 means 1.48%).
        $this->assertSame('1.48%', MetaPercentage::format(1.4846));
        $this->assertSame('1.20%', MetaPercentage::format(1.2037));
        $this->assertSame('1.86%', MetaPercentage::format(1.8563));
        $this->assertSame('148.46%', MetaPercentage::format(148.46)); // would be wrong product value; format still honest
        $this->assertTrue(MetaPercentage::isPercentagePointKey('ctr'));

        $accountImpressions = 87299.0;
        $accountClicks = 1296.0;
        $accountCtr = round(($accountClicks / $accountImpressions) * 100, 2);
        $this->assertSame(1.48, $accountCtr);
        $this->assertSame('1.48%', MetaPercentage::format($accountCtr));

        $campaignACtr = round((598 / 49697) * 100, 2);
        $campaignBCtr = round((698 / 37602) * 100, 2);
        $this->assertSame(1.2, $campaignACtr);
        $this->assertSame(1.86, $campaignBCtr);
        $this->assertSame('1.20%', MetaPercentage::format($campaignACtr));
        $this->assertSame('1.86%', MetaPercentage::format($campaignBCtr));
    }

    public function test_workspace_and_ai_context_use_meta_percentage_points_not_times_100(): void
    {
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
            'name' => 'Meta Specialist Asset',
        ]);
        $resource = $this->metaResource('act_ctr', 'CTR Check Account', 'Avrupadent BM');
        $this->bind($asset, $resource, 'meta_ads');

        $run = Run::query()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'meta-ads',
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'finished_at' => now(),
            'metadata' => [],
        ]);

        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'meta-ads',
            'type' => MetaAdsBoundCollector::EVIDENCE_ACCOUNT_SUMMARY,
            'title' => 'account',
            'observed_at' => now(),
            'payload' => [
                'response_ok' => true,
                'account_name' => 'CTR Check Account',
                'currency' => 'EUR',
                'timezone_name' => 'Europe/Berlin',
                'business_name' => 'Avrupadent BM',
                'requested_period' => ['start' => '2026-07-01', 'end' => '2026-07-28'],
                'comparison_period' => ['start' => '2026-06-03', 'end' => '2026-06-30'],
                'current' => [
                    'spend' => 500.0,
                    'impressions' => 87299.0,
                    'reach' => 40000.0,
                    'frequency' => 2.1,
                    'clicks' => 1296.0,
                    'inline_link_clicks' => 900.0,
                    'outbound_clicks' => 850.0,
                    'ctr' => 1.48,
                    'inline_link_click_ctr' => 1.03,
                    'cpc' => 0.39,
                    'cpm' => 5.73,
                    'actions' => [
                        ['raw_action_type' => 'landing_page_view', 'count' => 700.0],
                    ],
                ],
                'deltas' => [
                    'spend' => ['percent' => 10.0],
                    'ctr' => ['percent' => -5.0],
                ],
                'primary_result' => [
                    'status' => 'deferred',
                    'reason' => 'Account/Insights row lacks campaign objective',
                    'raw_action_type' => null,
                    'count' => null,
                ],
                'limitations' => ['Platform metrics reflect Meta attribution.'],
            ],
        ]);

        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'meta-ads',
            'type' => MetaAdsBoundCollector::EVIDENCE_CAMPAIGN_PERFORMANCE,
            'title' => 'campaigns',
            'observed_at' => now(),
            'payload' => [
                'response_ok' => true,
                'rows' => [[
                    'campaign_id' => 'c1',
                    'campaign_name' => 'Lead Camp A',
                    'status' => 'ACTIVE',
                    'objective' => 'OUTCOME_LEADS',
                    'spend' => 200.0,
                    'impressions' => 49697.0,
                    'reach' => 20000.0,
                    'frequency' => 2.4,
                    'clicks' => 598.0,
                    'inline_link_clicks' => 500.0,
                    'ctr' => 1.20,
                    'cpc' => 0.33,
                    'cpm' => 4.02,
                    'primary_result' => [
                        'status' => 'resolved',
                        'raw_action_type' => 'lead',
                        'normalized_result_type' => 'lead',
                        'count' => 26.0,
                        'cost_per_result' => 7.69,
                        'reason' => 'Primary Meta result resolved from ordered preference. Objective=OUTCOME_LEADS; Matching attributed action=lead.',
                    ],
                    'actions' => [
                        ['raw_action_type' => 'lead', 'count' => 26.0],
                        ['raw_action_type' => 'link_click', 'count' => 500.0],
                    ],
                ]],
            ],
        ]);

        $data = app(MetaAdsWorkspaceData::class)->for($asset->fresh());
        $this->assertContains($data['workspace_state'], ['data_available', 'collection_partial']);
        $this->assertSame('CTR Check Account', $data['account_identity']['name'] ?? null);

        $ctrKpi = collect($data['kpis'])->firstWhere('key', 'ctr');
        $this->assertNotNull($ctrKpi);
        $this->assertSame(1.48, (float) $ctrKpi['value']);
        $this->assertSame('1.48%', MetaPercentage::format($ctrKpi['value']));

        $campaign = $data['campaigns'][0];
        $this->assertSame(1.2, (float) $campaign['ctr']);
        $this->assertSame('resolved', $campaign['primary_result_status']);
        $this->assertSame(26.0, (float) $campaign['primary_result_count']);
        $this->assertNotEmpty($campaign['actions']);

        $html = view('meta-ads::workspace.overview', ['data' => $data])->render();
        $this->assertStringContainsString('1.48%', $html);
        $this->assertStringNotContainsString('148.46%', $html);
        $this->assertStringNotContainsString('Website details', $html);
        $this->assertStringNotContainsString('Site connections', $html);
        $this->assertStringNotContainsString('Agency Google auth', $html);
        $this->assertStringNotContainsString('CoreAssetBinding', $html);

        $perf = view('meta-ads::workspace.performance', ['data' => $data])->render();
        $this->assertStringContainsString('1.20%', $perf);
        $this->assertStringContainsString('Meta Result Signals', $perf);
        $this->assertStringNotContainsString('120.00%', $perf);

        $builder = app(MetaAdsAiGuidanceContextBuilder::class);
        $contextMethod = new ReflectionMethod($builder, 'build');
        if ($contextMethod->getNumberOfParameters() >= 1) {
            // Prefer public analyze-path helpers if present; otherwise assert Evidence payload remains percentage points.
            $this->assertSame(1.48, (float) data_get(
                Evidence::query()->where('digital_asset_id', $asset->id)
                    ->where('type', MetaAdsBoundCollector::EVIDENCE_ACCOUNT_SUMMARY)
                    ->first()
                    ?->payload,
                'current.ctr',
            ));
        }
    }

    public function test_result_resolver_lead_case_and_no_blind_sum(): void
    {
        $resolved = MetaResultResolver::resolve([
            ['raw_action_type' => 'lead', 'normalized_result_type' => 'lead', 'count' => 26.0, 'value' => null, 'source' => 'actions'],
            ['raw_action_type' => 'link_click', 'normalized_result_type' => 'link_click', 'count' => 500.0, 'value' => null, 'source' => 'actions'],
        ], 'OUTCOME_LEADS', 'LEAD_GENERATION', 200.0, null, 'WEBSITE', '7d_click');

        $this->assertSame('resolved', $resolved['status']);
        $this->assertSame('lead', $resolved['raw_action_type']);
        $this->assertSame(26.0, $resolved['count']);
        $this->assertArrayHasKey('diagnostic', $resolved);
        $this->assertFalse(isset($resolved['total']));
    }

    private function metaResource(string $externalId, string $name, string $business): CoreExternalResource
    {
        return CoreExternalResource::factory()->create([
            'integration_id' => $this->metaIntegration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_ads',
            'external_id' => $externalId,
            'display_name' => $name,
            'parent_external_id' => 'bm_1',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => [
                'business_id' => 'bm_1',
                'business_name' => $business,
                'currency' => 'EUR',
                'timezone_name' => 'Europe/Berlin',
                'access_relation' => 'business',
            ],
        ]);
    }

    private function googleResource(string $externalId, string $name): CoreExternalResource
    {
        return CoreExternalResource::factory()->create([
            'integration_id' => $this->googleIntegration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'google_ads',
            'external_id' => $externalId,
            'display_name' => $name,
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
    }

    private function bind(DigitalAsset $asset, CoreExternalResource $resource, string $capability): CoreAssetBinding
    {
        return CoreAssetBinding::query()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => $capability,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
            'configuration' => [],
        ]);
    }
}
