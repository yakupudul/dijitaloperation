<?php

namespace Tests\Feature;

use Tests\TestCase;

class CrossAssetAnalysisProductSpecTest extends TestCase
{
    private const BLUEPRINT = 'docs/product/cross-asset/CROSS_ASSET_ANALYSIS.md';

    public function test_cross_asset_analysis_product_blueprint_exists_with_required_sections(): void
    {
        $path = base_path(self::BLUEPRINT);

        $this->assertFileExists($path);

        $contents = (string) file_get_contents($path);

        foreach ([
            '## Purpose',
            '## User value',
            '## Core concepts',
            '## MVP behavior',
            '## Important data / attributes',
            '## Relationships',
            '## Main screens / workflows',
            '## Rules / invariants',
            '## Derived information',
            '## Later enhancements',
            '## Explicit non-goals',
            '## Acceptance intent',
        ] as $section) {
            $this->assertStringContainsString($section, $contents);
        }

        $this->assertStringContainsString('Brand', $contents);
        $this->assertStringContainsString('Harici write yok', $contents);
        $this->assertStringContainsString('ADR-034', $contents);
        $this->assertStringContainsString('ADR-036', $contents);
        $this->assertStringContainsString('NAP', $contents);
        $this->assertStringContainsString('landing-page', $contents);
        $this->assertStringContainsString('sahte', $contents);
    }

    public function test_product_index_and_roadmap_map_cross_asset_to_blueprint(): void
    {
        $index = (string) file_get_contents(base_path('docs/product/INDEX.md'));
        $roadmap = (string) file_get_contents(base_path('docs/IMPLEMENTATION_ROADMAP.md'));
        $future = (string) file_get_contents(base_path('docs/product/future/DIGITAL_ASSETS.md'));
        $dashboard = (string) file_get_contents(base_path('docs/product/DASHBOARD.md'));

        $this->assertStringContainsString(self::BLUEPRINT, $index);
        $this->assertStringContainsString('| Cross-asset / cross-channel analysis |', $index);
        $this->assertStringContainsString(self::BLUEPRINT, $roadmap);
        $this->assertStringContainsString(self::BLUEPRINT, $future);
        $this->assertStringContainsString(self::BLUEPRINT, $dashboard);
    }
}
