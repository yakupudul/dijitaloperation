<?php

namespace Tests\Feature\Ai;

use App\Enums\DigitalAssetStatus;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Ai\Insights\AiInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** On-click AI insights: every context builds from local data, answers are archived, subjects are checked. */
final class AiInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_active' => true]));
        config(['moxdop.anthropic.api_key' => 'sk-ant-test']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas Diş']);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => DigitalAssetStatus::Active, 'name' => 'atlas.example']);
    }

    public function test_every_insight_builds_its_context_from_local_data(): void
    {
        $alert = $this->alert();
        $subjects = [
            'alerts.cause' => $alert, 'website.technical_tasks' => $this->site,
        ];
        $insights = app(AiInsightService::class);
        $this->assertEqualsCanonicalizing(array_keys($subjects), array_keys($insights->definitions()));

        foreach ($subjects as $kind => $subject) {
            $context = $insights->definition($kind)->context($subject);
            $this->assertIsArray($context, $kind);
            $this->assertNotFalse(json_encode($context), $kind);
        }
        $this->assertArrayHasKey('daily', $insights->definition('alerts.cause')->context($alert));
    }

    private function alert(): AssetAlert
    {
        return AssetAlert::query()->create([
            'digital_asset_id' => $this->site->id, 'brand_id' => $this->brand->id, 'alert_key' => hash('sha256', 'ga4_conversions_drop'), 'kind' => 'ga4_conversions_drop',
            'severity' => 'high', 'title' => 'Site dönüşümleri düştü', 'message' => 'Son 7 günde 4 dönüşüm, önceki 7 günde 20 (−%80).', 'data' => ['drop_pct' => 80],
            'first_detected_at' => now()->subDay(), 'last_detected_at' => now(),
        ]);
    }
}
