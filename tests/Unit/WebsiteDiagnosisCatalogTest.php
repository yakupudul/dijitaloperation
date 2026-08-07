<?php

namespace Tests\Unit;

use App\Support\WebsiteDiagnosisCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WebsiteDiagnosisCatalogTest extends TestCase
{
    public function test_loads_recommendation_logic_from_catalog_markdown(): void
    {
        $catalog = new WebsiteDiagnosisCatalog(dirname(__DIR__, 2).'/docs/website/DIAGNOSIS_CATALOG.md');

        $logic = $catalog->recommendationLogic('sitemap-xml-availability');

        $this->assertNotNull($logic);
        $this->assertStringContainsString('Publish a UTF-8 XML sitemap', $logic);
        $this->assertStringContainsString('Sitemap:', $logic);
    }

    public function test_all_starter_catalog_items_have_recommendation_logic(): void
    {
        $catalog = new WebsiteDiagnosisCatalog(dirname(__DIR__, 2).'/docs/website/DIAGNOSIS_CATALOG.md');

        foreach ([
            'reachability-http',
            'https-tls-validity',
            'redirect-http-to-https',
            'robots-txt-availability',
            'sitemap-xml-availability',
            'canonical-link-consistency',
        ] as $id) {
            $this->assertNotNull(
                $catalog->recommendationLogic($id),
                "Missing recommendation_logic for {$id}",
            );
        }
    }

    public function test_missing_catalog_file_fails_closed(): void
    {
        $catalog = new WebsiteDiagnosisCatalog('/tmp/does-not-exist-diagnosis-catalog.md');

        $this->expectException(RuntimeException::class);
        $catalog->items();
    }
}
