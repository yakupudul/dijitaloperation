<?php

namespace Tests\Unit;

use App\Services\GoogleAds\GoogleAdsMeasurementControlService;
use Illuminate\Support\Collection;
use ReflectionClass;
use Tests\TestCase;

class GoogleAdsMeasurementControlServiceTest extends TestCase
{
    public function test_primary_low_intent_unmapped_action_generates_review_and_mapping_opportunity(): void
    {
        $service = (new ReflectionClass(GoogleAdsMeasurementControlService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(GoogleAdsMeasurementControlService::class, 'actionDecisions');
        $method->setAccessible(true);

        $decisions = $method->invoke($service, [
            'id' => '100',
            'action' => 'WhatsApp click',
            'role' => 'Primary',
            'status' => 'ENABLED',
            'observed' => true,
            'category' => 'OUTBOUND_CLICK',
            'business_mapped' => false,
            'counting_type' => 'ONE_PER_CLICK',
        ]);

        $codes = collect($decisions)->pluck('code')->all();
        $this->assertContains('low_intent_primary', $codes);
        $this->assertContains('business_mapping_missing', $codes);
        $this->assertNotContains('primary_not_enabled', $codes);
    }

    public function test_cross_origin_similar_primary_actions_are_only_flagged_as_possible_duplicate_candidates(): void
    {
        $service = (new ReflectionClass(GoogleAdsMeasurementControlService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(GoogleAdsMeasurementControlService::class, 'duplicateCandidates');
        $method->setAccessible(true);

        $candidates = $method->invoke($service, new Collection([
            [
                'id' => '1',
                'action' => 'Lead form',
                'role' => 'Primary',
                'observed' => true,
                'category' => 'SUBMIT_LEAD_FORM',
                'origin' => 'WEBSITE',
                'conversions' => 100.0,
            ],
            [
                'id' => '2',
                'action' => 'generate_lead',
                'role' => 'Primary',
                'observed' => true,
                'category' => 'SUBMIT_LEAD_FORM',
                'origin' => 'GOOGLE_ANALYTICS_4',
                'conversions' => 94.0,
            ],
        ]));

        $this->assertCount(1, $candidates);
        $this->assertSame('SUBMIT_LEAD_FORM', $candidates[0]['category']);
        $this->assertStringContainsString('Review whether', $candidates[0]['reason']);
    }
}
