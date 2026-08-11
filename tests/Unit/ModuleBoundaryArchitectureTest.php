<?php

namespace Tests\Unit;

use App\Support\Modules\ModuleCatalog;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use Tests\TestCase;

/**
 * Lightweight architecture regression protection for Core ↔ Module boundaries.
 *
 * Prefer namespace/dependency checks over brittle filename-only rules.
 * Legacy compatibility exceptions use a small explicit allowlist.
 */
class ModuleBoundaryArchitectureTest extends TestCase
{
    /**
     * Core files allowed to import Website module implementation namespaces.
     * Keep this list small; prefer moving domain logic into app-modules/website.
     *
     * @var list<string>
     */
    private const CORE_WEBSITE_IMPORT_ALLOWLIST = [
        // Thin compatibility facades (domain already in module).
        'app/Services/WebsiteAiInsightService.php',
        'app/Ai/Agents/WebsiteFindingInsightAgent.php',
        // Legacy Website Diagnosis orchestration still in Core; DocumentHead* lives in module.
        'app/Services/WebsiteDiagnosisService.php',
        // Core Filament composition surfaces that delegate to module presenters/services.
        'app/Filament/App/Resources/Customers/Resources/Brands/Resources/DigitalAssets/Pages/ViewDigitalAsset.php',
        'app/Filament/App/Resources/Customers/Resources/Brands/Resources/DigitalAssets/RelationManagers/WebsiteHealthRelationManager.php',
        'app/Filament/App/Resources/Customers/Resources/Brands/Resources/DigitalAssets/RelationManagers/WebsiteSettingsRelationManager.php',
        'app/Filament/App/Resources/Customers/Resources/Brands/Resources/DigitalAssets/RelationManagers/WebsitePerformanceRelationManager.php',
        'app/Filament/App/Resources/Customers/Resources/Brands/Resources/DigitalAssets/RelationManagers/WebsiteActivityRelationManager.php',
        'app/Filament/App/Resources/Customers/Resources/Brands/Resources/DigitalAssets/RelationManagers/WebsiteConnectionsRelationManager.php',
        'app/Filament/App/Resources/Customers/Resources/Brands/Resources/DigitalAssets/RelationManagers/WebsiteDiscoveryRelationManager.php',
        'app/Filament/App/Resources/Runs/RunResource.php',
        // Async platform jobs: thin Core orchestration that dispatches module domain services.
        'app/Jobs/Async/PublicDiscoveryJob.php',
        'app/Jobs/Async/SeoIntelligenceRefreshJob.php',
        'app/Jobs/Async/WebsiteAiGuidanceJob.php',
    ];

    /**
     * Core Filament composition surfaces allowed to import Google Ads module presenters/services.
     *
     * @var list<string>
     */
    private const CORE_GOOGLE_ADS_IMPORT_ALLOWLIST = [
        'app/Filament/App/Resources/Customers/Resources/Brands/Resources/DigitalAssets/Pages/ViewDigitalAsset.php',
        'app/Filament/App/Resources/Customers/Resources/Brands/Resources/DigitalAssets/RelationManagers/GoogleAdsPerformanceRelationManager.php',
        'app/Filament/App/Resources/Customers/Resources/Brands/Resources/DigitalAssets/RelationManagers/GoogleAdsSearchTermsRelationManager.php',
        'app/Filament/App/Resources/Customers/Resources/Brands/Resources/DigitalAssets/RelationManagers/GoogleAdsIntelligenceRelationManager.php',
        'app/Filament/App/Resources/Customers/Resources/Brands/Resources/DigitalAssets/RelationManagers/GoogleAdsConnectionsRelationManager.php',
        'app/Filament/App/Resources/Customers/Resources/Brands/Resources/DigitalAssets/RelationManagers/GoogleAdsActivityRelationManager.php',
        'app/Jobs/Async/GoogleAdsAiGuidanceJob.php',
    ];

    /**
     * Core Filament composition surfaces allowed to import Meta Ads module presenters.
     *
     * @var list<string>
     */
    private const CORE_META_ADS_IMPORT_ALLOWLIST = [
        'app/Filament/App/Resources/Customers/Resources/Brands/Resources/DigitalAssets/Pages/ViewDigitalAsset.php',
        'app/Filament/App/Resources/Customers/Resources/Brands/Resources/DigitalAssets/RelationManagers/MetaAdsPerformanceRelationManager.php',
        'app/Filament/App/Resources/Customers/Resources/Brands/Resources/DigitalAssets/RelationManagers/MetaAdsIntelligenceRelationManager.php',
        'app/Filament/App/Resources/Customers/Resources/Brands/Resources/DigitalAssets/RelationManagers/MetaAdsConnectionsRelationManager.php',
        'app/Filament/App/Resources/Customers/Resources/Brands/Resources/DigitalAssets/RelationManagers/MetaAdsActivityRelationManager.php',
        'app/Jobs/Async/MetaAdsAiGuidanceJob.php',
    ];

    #[Test]
    public function core_must_not_import_module_implementation_namespaces_outside_allowlist(): void
    {
        $violations = [];

        foreach ($this->phpFilesUnder(base_path('app')) as $absolutePath) {
            $relative = $this->relativePath($absolutePath);
            $contents = file_get_contents($absolutePath);
            if ($contents === false) {
                continue;
            }

            if (preg_match('/use\s+MoxDop\\\\Website\\\\/', $contents) === 1) {
                if (! in_array($relative, self::CORE_WEBSITE_IMPORT_ALLOWLIST, true)) {
                    $violations[] = "{$relative} imports MoxDop\\Website\\* (not allowlisted)";
                }
            }

            if (preg_match('/use\s+MoxDop\\\\GoogleAds\\\\/', $contents) === 1) {
                if (! in_array($relative, self::CORE_GOOGLE_ADS_IMPORT_ALLOWLIST, true)) {
                    $violations[] = "{$relative} imports MoxDop\\GoogleAds\\* (not allowlisted)";
                }
            }

            if (preg_match('/use\s+MoxDop\\\\MetaAds\\\\/', $contents) === 1) {
                if (! in_array($relative, self::CORE_META_ADS_IMPORT_ALLOWLIST, true)) {
                    $violations[] = "{$relative} imports MoxDop\\MetaAds\\* (not allowlisted)";
                }
            }

            if (preg_match('/use\s+MoxDop\\\\GoogleBusinessProfile\\\\/', $contents) === 1) {
                $violations[] = "{$relative} imports MoxDop\\GoogleBusinessProfile\\* (Core must not depend on GBP module implementations)";
            }

            if (preg_match('/use\s+MoxDop\\\\SampleModule\\\\/', $contents) === 1) {
                $violations[] = "{$relative} imports MoxDop\\SampleModule\\*";
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    #[Test]
    public function modules_must_not_import_sibling_module_implementation_namespaces(): void
    {
        $violations = [];

        $siblingMap = [
            'website' => ['GoogleAds', 'GoogleBusinessProfile', 'MetaAds', 'SampleModule'],
            'google-ads' => ['Website', 'GoogleBusinessProfile', 'MetaAds', 'SampleModule'],
            'google-business-profile' => ['Website', 'GoogleAds', 'MetaAds', 'SampleModule'],
            'meta-ads' => ['Website', 'GoogleAds', 'GoogleBusinessProfile', 'SampleModule'],
            'sample-module' => ['Website', 'GoogleAds', 'GoogleBusinessProfile', 'MetaAds'],
        ];

        foreach ($siblingMap as $moduleDir => $forbiddenPackages) {
            $root = base_path('app-modules/'.$moduleDir);
            if (! is_dir($root)) {
                continue;
            }

            foreach ($this->phpFilesUnder($root) as $absolutePath) {
                $relative = $this->relativePath($absolutePath);
                $contents = file_get_contents($absolutePath);
                if ($contents === false) {
                    continue;
                }

                foreach ($forbiddenPackages as $package) {
                    if (preg_match('/use\s+MoxDop\\\\'.$package.'\\\\/', $contents) === 1) {
                        $violations[] = "{$relative} imports MoxDop\\{$package}\\* (sibling module coupling)";
                    }
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    #[Test]
    public function modules_may_depend_on_core_models_and_shared_infrastructure_namespaces(): void
    {
        $websiteService = base_path('app-modules/website/src/Ai/WebsiteAiRecommendationService.php');
        $this->assertFileExists($websiteService);
        $contents = (string) file_get_contents($websiteService);

        $this->assertMatchesRegularExpression('/use\s+App\\\\Models\\\\/', $contents);
        $this->assertMatchesRegularExpression('/use\s+App\\\\Services\\\\(?:Ai|Integrations)\\\\/', $contents);
    }

    #[Test]
    public function operator_module_catalog_excludes_fixtures_and_providers(): void
    {
        foreach (ModuleCatalog::PRODUCT_MODULE_IDS as $moduleId) {
            $this->assertTrue(ModuleCatalog::isOperatorVisible($moduleId));
            $this->assertFalse(ModuleCatalog::isDeveloperFixture($moduleId));
        }

        foreach (ModuleCatalog::DEVELOPER_FIXTURE_MODULE_IDS as $moduleId) {
            $this->assertFalse(ModuleCatalog::isOperatorVisible($moduleId));
            $this->assertTrue(ModuleCatalog::isDeveloperFixture($moduleId));
        }

        foreach (ModuleCatalog::INTEGRATION_PROVIDER_KEYS_NOT_MODULES as $provider) {
            $this->assertNotContains($provider, ModuleCatalog::PRODUCT_MODULE_IDS);
            $this->assertNotContains($provider, ModuleCatalog::DEVELOPER_FIXTURE_MODULE_IDS);
        }
    }

    #[Test]
    public function website_ai_core_facade_remains_thin_compatibility_delegate(): void
    {
        $path = base_path('app/Services/WebsiteAiInsightService.php');
        $contents = (string) file_get_contents($path);

        $this->assertStringContainsString('WebsiteAiRecommendationService', $contents);
        $this->assertMatchesRegularExpression('/compatible|facade/i', $contents);
        $this->assertDoesNotMatchRegularExpression('/function\s+buildPrompt|function\s+ground|function\s+scoreFinding/', $contents);
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        $phpFiles = new RegexIterator($iterator, '/^.+\.php$/i', RegexIterator::GET_MATCH);

        $paths = [];
        foreach ($phpFiles as $match) {
            $paths[] = $match[0];
        }

        sort($paths);

        return $paths;
    }

    private function relativePath(string $absolutePath): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $absolutePath);
    }
}
