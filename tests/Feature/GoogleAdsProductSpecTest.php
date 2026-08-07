<?php

namespace Tests\Feature;

use Tests\TestCase;

class GoogleAdsProductSpecTest extends TestCase
{
    private const BLUEPRINT = 'docs/product/google-ads/GOOGLE_ADS.md';

    public function test_google_ads_product_blueprint_exists_with_required_sections(): void
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

        $this->assertStringContainsString('google_ads', $contents);
        $this->assertStringContainsString('Harici write yok', $contents);
        $this->assertStringContainsString('read-only', $contents);
        $this->assertStringContainsString('ADR-034', $contents);
        $this->assertStringContainsString('ADR-027', $contents);
    }

    public function test_product_index_and_roadmap_map_google_ads_to_blueprint(): void
    {
        $index = (string) file_get_contents(base_path('docs/product/INDEX.md'));
        $roadmap = (string) file_get_contents(base_path('docs/IMPLEMENTATION_ROADMAP.md'));
        $future = (string) file_get_contents(base_path('docs/product/future/DIGITAL_ASSETS.md'));

        $this->assertStringContainsString(self::BLUEPRINT, $index);
        $this->assertStringContainsString('| Google Ads |', $index);
        $this->assertStringContainsString(self::BLUEPRINT, $roadmap);
        $this->assertStringContainsString(self::BLUEPRINT, $future);
    }
}
