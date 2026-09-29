<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\Insights\AlertCauseAgent;
use App\Enums\DigitalAssetStatus;
use App\Livewire\Operator\Work\AlertsPage;
use App\Models\AiProduction;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Ai\Insights\AiInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** On-click AI insights: every context builds from local data, answers are archived, subjects are checked. */
final class AiInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $site;

    private DigitalAsset $ads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_active' => true]));
        config(['moxdop.anthropic.api_key' => 'sk-ant-test']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas Diş']);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => DigitalAssetStatus::Active, 'name' => 'atlas.example']);
        $this->ads = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'status' => DigitalAssetStatus::Active, 'name' => 'Atlas Ads']);
    }

    public function test_every_insight_builds_its_context_from_local_data(): void
    {
        $alert = $this->alert();
        $subjects = [
            'google_ads.search_term_triage' => $this->ads, 'google_ads.landing_fit' => $this->ads,
            'alerts.cause' => $alert, 'website.technical_tasks' => $this->site,
            'meta.geo_results' => DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'meta_ads', 'status' => DigitalAssetStatus::Active, 'name' => 'Atlas Meta']),
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

    public function test_alert_cause_runs_on_click_and_is_archived(): void
    {
        AlertCauseAgent::fake([['summary' => 'Düşüş reklam bütçesinin kesildiği gün başlıyor.', 'items' => [
            ['title' => 'Google Ads bütçesi durduruldu', 'detail' => 'Harcama 12 Eylül’de sıfıra indi.', 'tag' => 'likely'],
        ]]]);
        $alert = $this->alert();

        Livewire::test(AlertsPage::class)
            ->assertSee('Olası neden')
            ->set('causeFor', $alert->id)
            ->call('runInsight', 'alerts.cause', $alert->id)
            ->assertSee('Düşüş reklam bütçesinin kesildiği gün başlıyor.')
            ->assertSee('Büyük ihtimalle');

        $this->assertSame(1, AiProduction::query()->where('kind', 'alerts.cause')->where('subject_id', $alert->id)->count());
        AlertCauseAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'Site dönüşümleri düştü'));
    }

    public function test_a_page_only_runs_insights_on_its_own_subjects(): void
    {
        AlertCauseAgent::fake();
        $alert = $this->alert();
        $alert->forceFill(['resolved_at' => now()])->save();

        Livewire::test(AlertsPage::class)->call('runInsight', 'alerts.cause', $alert->id);
        Livewire::test(AlertsPage::class)->call('runInsight', 'alerts.cause', 999999);

        AlertCauseAgent::assertNeverPrompted();
        $this->assertSame(0, AiProduction::query()->count());
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
