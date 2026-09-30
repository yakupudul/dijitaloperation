<?php

namespace Tests\Feature\TrackA;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GscWebsiteDecisionConsistencyContractTest extends TestCase
{
    #[Test]
    public function website_gsc_summary_counts_are_not_derived_from_display_limited_lists(): void
    {
        $source = file_get_contents(app_path('Services/Gsc/WebsiteSearchConsoleAnalysisService.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("'rising_queries' => count(\$queryMovements['rising'])", $source);
        $this->assertStringContainsString("'falling_pages' => count(\$pageMovements['falling'])", $source);
        $this->assertStringContainsString("'opportunity_candidates' => (int) (\$opportunities['total_count'] ?? 0)", $source);
        $this->assertStringContainsString('cannibalizationCandidateCount(', $source);
        $this->assertStringContainsString("'rising' => array_slice(\$queryMovements['rising'], 0, 20)", $source);

        $this->assertStringNotContainsString("'rising' => array_slice(\$rising, 0, 20)", $source);
        $this->assertStringNotContainsString("'falling' => array_slice(\$falling, 0, 20)", $source);
    }
}
