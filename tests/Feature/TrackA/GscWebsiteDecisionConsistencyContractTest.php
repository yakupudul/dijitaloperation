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

    #[Test]
    public function website_gsc_distinguishes_unavailable_search_appearance_from_true_period_empty_state(): void
    {
        $source = file_get_contents(app_path('Services/Gsc/WebsiteSearchConsoleAnalysisService.php'));
        $view = file_get_contents(resource_path('views/livewire/operator/website/tabs/search-console.blade.php'));

        $this->assertIsString($source);
        $this->assertIsString($view);
        $this->assertStringContainsString("'status' => 'unavailable', 'reason' => 'dataset_table_missing'", $source);
        $this->assertStringContainsString("'status' => \$items === [] ? 'empty' : 'available'", $source);
        $this->assertStringContainsString("'search_appearance_status' => \$appearanceState['status']", $source);
        $this->assertStringContainsString("\$searchAppearanceStatus === 'unavailable'", $view);
        $this->assertStringContainsString("\$searchAppearanceStatus === 'empty'", $view);
    }

    #[Test]
    public function website_gsc_labels_url_inspection_as_a_sample_and_explains_signal_promotion(): void
    {
        $view = file_get_contents(resource_path('views/livewire/operator/website/tabs/search-console.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('URL Inspection örnek sağlığı', $view);
        $this->assertStringContainsString('kontrollü URL Inspection örneğini özetler', $view);
        $this->assertStringContainsString('Sinyal, doğrulanmış bulgu değildir', $view);
        $this->assertStringContainsString("\$gsc['opportunities']['total_count']", $view);
        $this->assertStringContainsString("\$gsc['health_summary']['cannibalization_candidates']", $view);
    }
}
