<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\Insights\AlertCauseAgent;
use App\Ai\Agents\Insights\LeadScoreAgent;
use App\Enums\DigitalAssetStatus;
use App\Livewire\Operator\Sales\LeadInboxPage;
use App\Livewire\Operator\Work\AlertsPage;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\AgencyLead;
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
        $plan = AdvisorPlan::query()->create(['channel' => 'google_ads', 'brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->ads->id, 'status' => 'completed', 'completed_at' => now()]);
        $item = AdvisorItem::query()->create([
            'channel' => 'google_ads', 'customer_id' => $this->brand->customer_id, 'brand_id' => $this->brand->id, 'digital_asset_id' => $this->ads->id,
            'item_key' => 'k1', 'category' => 'waste', 'rule_id' => 'segment-bid-adjustment', 'severity' => 'high', 'priority_score' => 300,
            'title' => 'Mobilde boşa harcama', 'reason' => 'Mobil CPA 3 kat', 'evidence' => ['cpa' => 900], 'checklist' => ['Mobil teklif düşür'], 'status' => 'open', 'currency' => 'TRY',
            'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id,
        ]);
        $alert = $this->alert();
        $lead = $this->lead();
        $subjects = [
            'advisor.explain' => $item, 'google_ads.search_term_triage' => $this->ads, 'google_ads.landing_fit' => $this->ads,
            'reviews.themes' => $this->brand, 'alerts.cause' => $alert, 'customer.brief' => $this->brand->customer,
            'sales.lead_score' => $lead, 'website.technical_tasks' => $this->site,
        ];
        $insights = app(AiInsightService::class);
        $this->assertEqualsCanonicalizing(array_keys($subjects), array_keys($insights->definitions()));

        foreach ($subjects as $kind => $subject) {
            $context = $insights->definition($kind)->context($subject);
            $this->assertIsArray($context, $kind);
            $this->assertNotFalse(json_encode($context), $kind);
        }
        $this->assertSame('Mobilde boşa harcama', $insights->definition('advisor.explain')->context($item)['title']);
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

    public function test_lead_score_never_sends_contact_details(): void
    {
        LeadScoreAgent::fake([['summary' => 'Ciddi bir talep.', 'items' => [['title' => 'Sıcak', 'detail' => 'Bütçe ve şehir belli.', 'tag' => 'hot']]]]);
        $lead = $this->lead();

        Livewire::test(LeadInboxPage::class)->call('runInsight', 'sales.lead_score', $lead->id)->assertSee('Ciddi bir talep.');

        LeadScoreAgent::assertPrompted(function ($prompt): bool {
            $text = (string) $prompt->prompt;

            return str_contains($text, 'implant') && ! str_contains($text, '0555') && ! str_contains($text, 'ali@example.com') && ! str_contains($text, 'Ali Veli');
        });
    }

    public function test_a_page_only_runs_insights_on_its_own_subjects(): void
    {
        AlertCauseAgent::fake();
        $alert = $this->alert();
        $alert->forceFill(['resolved_at' => now()])->save();

        Livewire::test(AlertsPage::class)->call('runInsight', 'alerts.cause', $alert->id);
        Livewire::test(AlertsPage::class)->call('runInsight', 'sales.lead_score', $this->lead()->id);

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

    private function lead(): AgencyLead
    {
        return AgencyLead::query()->create([
            'name' => 'Ali Veli', 'company' => 'Gülüş Kliniği', 'phone' => '0555 111 22 33', 'email' => 'ali@example.com',
            'message' => 'İzmir’de implant için Google reklamı yaptırmak istiyoruz, aylık bütçemiz 30 bin.', 'source' => 'meta_lead_ad',
            'utm' => ['utm_campaign' => 'Ajans Lead'], 'status' => 'new', 'received_at' => now(),
        ]);
    }
}
