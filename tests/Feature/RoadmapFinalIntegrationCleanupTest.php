<?php

namespace Tests\Feature;

use App\Filament\App\Widgets\OpsActionOverviewWidget;
use App\Models\Brand;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\ModuleRegistry;
use App\Models\Recommendation;
use App\Models\Run;
use App\Models\Task;
use App\Models\User;
use App\Services\WebsiteDiagnosisService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use ReflectionClass;
use Tests\TestCase;

class RoadmapFinalIntegrationCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        Filament::setCurrentPanel('app');
    }

    public function test_canonical_roadmap_product_blueprints_and_first_modules_exist(): void
    {
        $requiredSpecs = [
            'docs/product/google-business-profile/GOOGLE_BUSINESS_PROFILE.md',
            'docs/product/google-ads/GOOGLE_ADS.md',
            'docs/product/meta-ads/META_ADS.md',
            'docs/product/instagram/INSTAGRAM.md',
            'docs/product/cross-asset/CROSS_ASSET_ANALYSIS.md',
            'docs/product/DASHBOARD.md',
            'docs/website/DIAGNOSIS_CATALOG.md',
        ];

        foreach ($requiredSpecs as $path) {
            $this->assertFileExists(base_path($path), "Missing product/spec path: {$path}");
        }

        $requiredServices = [
            'app/Services/GoogleBusinessProfileConnectionProbeService.php',
            'app/Services/GoogleAdsConnectionProbeService.php',
            'app/Services/MetaAdsConnectionProbeService.php',
            'app/Services/InstagramConnectionProbeService.php',
            'app/Services/CrossAssetWebsiteGbpWebsiteUrlConsistencyService.php',
            'app/Services/CrossAssetWebsiteGbpPhoneConsistencyService.php',
            'app/Services/CrossAssetWebsiteGbpAddressConsistencyService.php',
            'app/Services/CrossAssetWebsiteGoogleAdsLandingConsistencyService.php',
            'app/Services/CrossAssetWebsiteMetaAdsDestinationConsistencyService.php',
            'app/Services/CrossAssetWebsiteInstagramWebsiteUrlConsistencyService.php',
            'app/Services/CrossAssetInstagramMetaAdsDestinationConsistencyService.php',
            'app/Filament/App/Widgets/OpsActionOverviewWidget.php',
            'tests/Feature/AgencyOpsDashboardProductionHardeningTest.php',
        ];

        foreach ($requiredServices as $path) {
            $this->assertFileExists(base_path($path), "Missing implementation path: {$path}");
        }
    }

    public function test_website_diagnosis_starter_catalog_items_are_implemented(): void
    {
        $catalog = File::get(base_path('docs/website/DIAGNOSIS_CATALOG.md'));
        $starterIds = [
            'reachability-http',
            'https-tls-validity',
            'redirect-http-to-https',
            'robots-txt-availability',
            'sitemap-xml-availability',
            'canonical-link-consistency',
        ];

        foreach ($starterIds as $id) {
            $this->assertStringContainsString("`{$id}`", $catalog);
        }

        $this->assertSame('reachability-http', WebsiteDiagnosisService::CATALOG_REACHABILITY_HTTP);
        $this->assertSame('https-tls-validity', WebsiteDiagnosisService::CATALOG_HTTPS_TLS_VALIDITY);
        $this->assertSame('redirect-http-to-https', WebsiteDiagnosisService::CATALOG_REDIRECT_HTTP_TO_HTTPS);
        $this->assertSame('robots-txt-availability', WebsiteDiagnosisService::CATALOG_ROBOTS_TXT_AVAILABILITY);
        $this->assertSame('sitemap-xml-availability', WebsiteDiagnosisService::CATALOG_SITEMAP_XML_AVAILABILITY);
        $this->assertSame('canonical-link-consistency', WebsiteDiagnosisService::CATALOG_CANONICAL_LINK_CONSISTENCY);
    }

    public function test_connector_and_cross_asset_services_declare_read_only_boundaries(): void
    {
        $servicePaths = [
            'app/Services/WordPressConnectionProbeService.php',
            'app/Services/SearchConsoleConnectionProbeService.php',
            'app/Services/Ga4ConnectionProbeService.php',
            'app/Services/PageSpeedConnectionProbeService.php',
            'app/Services/DataForSeoConnectionProbeService.php',
            'app/Services/GoogleBusinessProfileConnectionProbeService.php',
            'app/Services/GoogleAdsConnectionProbeService.php',
            'app/Services/MetaAdsConnectionProbeService.php',
            'app/Services/InstagramConnectionProbeService.php',
            'app/Services/CrossAssetWebsiteGbpWebsiteUrlConsistencyService.php',
            'app/Services/CrossAssetWebsiteGoogleAdsLandingConsistencyService.php',
            'app/Services/CrossAssetWebsiteMetaAdsDestinationConsistencyService.php',
            'app/Services/CrossAssetInstagramMetaAdsDestinationConsistencyService.php',
        ];

        foreach ($servicePaths as $relative) {
            $contents = File::get(base_path($relative));
            $this->assertMatchesRegularExpression(
                '/read-only|no (external )?writes|no .* writes/i',
                $contents,
                "Expected read-only boundary documentation in {$relative}"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\bHttp::(?:post|put|patch|delete)\s*\(/i',
                $contents,
                "Unexpected mutating HTTP helper in {$relative}"
            );
        }
    }

    public function test_final_integration_lifecycle_and_ops_surfaces(): void
    {
        $this->assertTrue($this->app->isBooted());
        $this->get('/up')->assertOk();
        $this->get('/admin')->assertOk()
            ->assertSeeLivewire(OpsActionOverviewWidget::class);

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $website = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
        ]);
        $this->assertTrue($website->brand->is($brand));
        $this->assertTrue($brand->customer->is($customer));

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $website->id,
            'type' => 'ga4',
            'enabled' => true,
        ]);
        $secretPayload = [
            'client_id' => 'cleanup-client-id',
            'client_secret' => 'cleanup-client-secret',
            'refresh_token' => 'cleanup-refresh-token',
        ];
        $credential = CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => $secretPayload,
        ]);
        $storedPayload = DB::table('core_connection_credentials')
            ->where('id', $credential->id)
            ->value('encrypted_payload');
        $this->assertIsString($storedPayload);
        $this->assertStringNotContainsString('cleanup-client-secret', $storedPayload);
        $this->assertSame($secretPayload, $credential->fresh()->encrypted_payload);

        $module = ModuleRegistry::query()->create([
            'module_id' => 'website',
            'enabled' => true,
            'installed_version' => '1.0.0',
        ]);
        $this->assertTrue(ModuleRegistry::isEnabled('website'));
        $module->update(['enabled' => false]);
        $this->assertFalse(ModuleRegistry::isEnabled('website'));

        $run = Run::factory()->create([
            'digital_asset_id' => $website->id,
            'module_id' => 'website-diagnosis',
            'status' => 'completed',
        ]);
        $evidence = Evidence::factory()->create([
            'run_id' => $run->id,
            'type' => 'http_fetch',
        ]);
        $this->assertTrue($run->evidence->contains($evidence));

        $finding = Finding::factory()->create([
            'digital_asset_id' => $website->id,
            'category' => 'cross-channel',
            'severity' => 'high',
            'status' => 'open',
            'source_module' => 'cross-asset-analysis',
            'last_run_id' => $run->id,
        ]);
        $recommendation = Recommendation::factory()->create([
            'finding_id' => $finding->id,
            'status' => 'open',
            'priority' => 'high',
        ]);
        $task = Task::factory()->create([
            'status' => 'open',
            'due_date' => now()->addDay()->toDateString(),
        ]);
        $this->assertTrue($finding->recommendations->contains($recommendation));
        $this->assertSame('open', $task->status);

        $teamMember = User::factory()->create();
        $teamMember->assignRole(Roles::TEAM_MEMBER);
        $this->assertTrue($teamMember->hasRole(Roles::TEAM_MEMBER));
        $this->assertFalse($teamMember->hasRole(Roles::ADMIN));

        Livewire::test(OpsActionOverviewWidget::class)
            ->assertOk()
            ->assertSee('What needs attention')
            ->assertSee('Open cross-channel Findings')
            ->assertSee('Open Recommendations')
            ->assertSee('Open Tasks');

        $diagnosisReflection = new ReflectionClass(WebsiteDiagnosisService::class);
        $this->assertTrue($diagnosisReflection->hasMethod('diagnose'));
    }
}
