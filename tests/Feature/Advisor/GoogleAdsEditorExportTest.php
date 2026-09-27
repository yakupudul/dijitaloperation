<?php

namespace Tests\Feature\Advisor;

use App\Livewire\Operator\Advisor\AdvisorPanel;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Advisor\AdvisorPlanWriter;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorRuleEngine;
use App\Services\Advisor\GoogleAds\GoogleAdsEditorExport;
use App\Services\CommandCenter\CommandCenter;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Value loop: advisor recommendations → Google Ads Editor import file. Nothing is written to Google Ads.
 */
final class GoogleAdsEditorExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $ads;

    private AdvisorPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00', 'UTC'));
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Örnek Klinik']);
        $this->ads = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'status' => 'active', 'name' => 'Örnek Ads', 'module_id' => 'google_ads']);
        $this->plan = AdvisorPlan::query()->create(['channel' => 'google_ads', 'brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->ads->id, 'status' => 'completed', 'completed_at' => now()]);
    }

    public function test_file_has_editor_columns_utf16_bom_and_maps_each_supported_rule(): void
    {
        $negatives = $this->item('negative-keywords', 'Negatif liste', [
            'words' => [['word' => 'ücretsiz', 'campaigns' => ['Arama – İmplant']]],
            'terms' => [
                ['term' => 'diş hekimi maaşları', 'campaigns' => ['Arama – İmplant', 'Arama – Ortodonti']],
                ['term' => 'eski terim'],
            ],
        ]);
        $opportunities = $this->item('keyword-opportunities', 'Fırsat', ['terms' => [
            ['term' => 'implant fiyatları istanbul', 'target' => ['campaign' => 'Arama – İmplant', 'ad_group' => 'İmplant fiyat']],
            ['term' => 'pmax terimi', 'target' => null],
        ]]);
        $budget = $this->item('budget-limited-profitable', 'Bütçe', ['campaigns' => [['name' => 'Arama – İmplant', 'daily_budget' => 500.0]]]);
        $waste = $this->item('budget-waste', 'Boşa harcama', ['campaigns' => [['name' => 'Görüntülü – Genel', 'cost' => 900]]]);
        $manual = $this->item('quality-score', 'Kalite puanı', ['keywords' => []]);

        $export = app(GoogleAdsEditorExport::class);
        $result = $export->build(collect([$negatives, $opportunities, $budget, $waste, $manual]));

        $this->assertEqualsCanonicalizing([$negatives->id, $opportunities->id, $budget->id, $waste->id], $result['exported']);
        $manualById = collect($result['manual'])->groupBy('id');
        $this->assertTrue($manualById->has($manual->id), 'unsupported rule is manual');
        $this->assertStringContainsString('1 satırın kampanyası bilinmiyor', $manualById[$negatives->id][0]['reason']);
        $this->assertStringContainsString('1 satırın', $manualById[$opportunities->id][0]['reason']);

        $file = $export->file($result['rows']);
        $this->assertStringStartsWith("\xFF\xFE", $file, 'UTF-16LE BOM');
        $text = mb_convert_encoding(substr($file, 2), 'UTF-8', 'UTF-16LE');
        $lines = array_map(static fn (string $l): array => explode("\t", $l), array_values(array_filter(explode("\r\n", $text))));

        $this->assertSame(['Campaign', 'Campaign Status', 'Budget', 'Ad group', 'Keyword', 'Criterion Type'], $lines[0]);
        $rows = array_slice($lines, 1);
        $this->assertContains(['Arama – İmplant', '', '', '', 'ücretsiz', 'Campaign Negative Phrase'], $rows);
        $this->assertContains(['Arama – İmplant', '', '', '', 'diş hekimi maaşları', 'Campaign Negative Exact'], $rows);
        $this->assertContains(['Arama – Ortodonti', '', '', '', 'diş hekimi maaşları', 'Campaign Negative Exact'], $rows);
        $this->assertContains(['Arama – İmplant', '', '', 'İmplant fiyat', 'implant fiyatları istanbul', 'Exact'], $rows);
        $this->assertContains(['Arama – İmplant', '', '600.00', '', '', ''], $rows);
        $this->assertContains(['Görüntülü – Genel', 'Paused', '', '', '', ''], $rows);
        $this->assertCount(6, $rows);
        foreach ($rows as $row) {
            $this->assertCount(6, $row);
        }
    }

    public function test_item_without_campaign_names_is_manual_and_cells_are_sanitised(): void
    {
        $old = $this->item('negative-keywords', 'Eski liste', ['terms' => [['term' => 'ücretsiz']]]);
        $export = app(GoogleAdsEditorExport::class);
        $result = $export->build(collect([$old]));
        $this->assertSame([], $result['rows']);
        $this->assertSame([], $result['exported']);
        $this->assertStringContainsString('kampanya / reklam grubu adı yok', $result['manual'][0]['reason']);

        $file = mb_convert_encoding(substr($export->file([['Campaign' => "=HYPERLINK(1)\tx", 'Keyword' => "a\nb"]]), 2), 'UTF-8', 'UTF-16LE');
        $this->assertStringContainsString("'=HYPERLINK(1) x\t\t\t\ta b\t", $file);
    }

    public function test_panel_download_marks_items_exported_and_lists_manual_items_without_calling_google(): void
    {
        Http::fake();
        $negatives = $this->item('negative-keywords', 'Negatif liste', ['terms' => [['term' => 'ücretsiz', 'campaigns' => ['Arama']]]]);
        $manual = $this->item('ad-strength', 'Reklam gücü zayıf', ['ad_group' => 'x']);

        Livewire::test(AdvisorPanel::class, ['assetId' => $this->ads->id])
            ->set('bulkIds', [$negatives->id, $manual->id])
            ->call('exportEditor')
            ->assertFileDownloaded()
            ->assertSee('Elle yapılacak (1)')
            ->assertSee('Reklam gücü zayıf')
            ->assertSee('dışa aktarıldı, Editor');

        Http::assertNothingSent();
        $this->assertNotNull($negatives->fresh()->exported_at);
        $this->assertSame($this->admin->id, $negatives->fresh()->exported_by);
        $this->assertNull($manual->fresh()->exported_at);
        $this->assertSame('open', $negatives->fresh()->status->value, 'export does not close the item; the operator marks it done');

        $item = collect(app(CommandCenter::class)->items())->firstWhere('key', 'advisor:'.$negatives->id);
        $this->assertStringContainsString('dışa aktarıldı, Editor\'da yüklenmeyi bekliyor', $item['detail']);

        Livewire::test(AdvisorPanel::class, ['assetId' => $this->ads->id])->call('markDone', $negatives->id);
        $this->assertSame('done', $negatives->fresh()->status->value);
    }

    public function test_nothing_exportable_gives_error_and_no_download(): void
    {
        $manual = $this->item('ad-strength', 'Reklam gücü zayıf', []);
        Livewire::test(AdvisorPanel::class, ['assetId' => $this->ads->id])
            ->set('bulkIds', [$manual->id])
            ->call('exportEditor')
            ->assertNoFileDownloaded()
            ->assertSee('elle yapılacak');
        $this->assertNull($manual->fresh()->exported_at);
    }

    public function test_rule_engine_puts_search_campaign_names_on_negative_rows_and_ad_group_on_opportunities(): void
    {
        config(['moxdop-advisor.google_ads.negatives.min_cost' => 10, 'moxdop-advisor.google_ads.negatives.min_clicks' => 1, 'moxdop-advisor.google_ads.keyword_opportunities.min_conversions' => 1]);
        $term = fn (string $text, float $cost, float $conv, array $campaigns, array $groups): array => ['term' => $text, 'cost' => $cost, 'clicks' => 5, 'impressions' => 50, 'conversions' => $conv, 'statuses' => ['NONE'], 'campaign_ids' => $campaigns, 'ad_group_ids' => $groups, 'pmax' => false];
        $input = [
            'bound' => true, 'currency' => 'TRY', 'period' => ['days' => 30, 'start' => '2026-08-24', 'end' => '2026-09-22'],
            'asset' => ['id' => $this->ads->id, 'name' => 'Örnek Ads', 'brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'brand_name' => 'Örnek Klinik'],
            'account' => ['has_data' => true, 'cost' => 5000.0, 'conversions' => 20.0, 'cpa' => 250.0, 'clicks' => 1000, 'auto_tagging_enabled' => true],
            'targets' => ['target_cpa' => null],
            'campaigns' => [
                'c1' => ['id' => 'c1', 'name' => 'Arama – İmplant', 'status' => 'ENABLED', 'channel' => 'SEARCH', 'budget_amount' => 100.0, 'cost' => 3000.0, 'clicks' => 500, 'conversions' => 20.0, 'cpa' => 150.0, 'lost_is_budget' => null],
                'p1' => ['id' => 'p1', 'name' => 'PMax', 'status' => 'ENABLED', 'channel' => 'PERFORMANCE_MAX', 'budget_amount' => 100.0, 'cost' => 2000.0, 'clicks' => 500, 'conversions' => 0.0, 'cpa' => null, 'lost_is_budget' => null],
            ],
            'search_terms' => [
                'diş hekimi maaşları' => $term('diş hekimi maaşları', 300.0, 0, ['c1', 'p1'], ['g1']),
                'implant fiyatları' => $term('implant fiyatları', 200.0, 4, ['c1'], ['g1']),
            ],
            'keywords' => [['ad_group_id' => 'g1', 'criterion_id' => 'k1', 'campaign_id' => 'c1', 'text' => 'implant', 'match_type' => 'PHRASE', 'status' => 'ENABLED', 'quality_score' => 7, 'cost' => 100.0, 'conversions' => 1.0]],
            'ads' => ['available' => false, 'items' => [], 'ad_groups' => ['g1' => 'İmplant fiyat'], 'metrics_available' => false],
            'negatives' => [],
        ];
        $items = collect(app(GoogleAdsAdvisorRuleEngine::class)->evaluate($input)['items'])->keyBy('rule_id');

        $this->assertSame(['Arama – İmplant'], $items['negative-keywords']['evidence']['terms'][0]['campaigns'], 'PMax campaign left out');
        $this->assertSame(['campaign' => 'Arama – İmplant', 'ad_group' => 'İmplant fiyat'], $items['keyword-opportunities']['evidence']['terms'][0]['target']);
    }

    public function test_reopened_item_loses_its_export_mark(): void
    {
        $item = $this->item('budget-waste', 'Boşa harcama', ['campaigns' => [['name' => 'A']]]);
        $item->forceFill(['status' => 'done', 'resolved_at' => now()->subDays(10), 'exported_at' => now()->subDays(10)])->save();
        $plan = AdvisorPlan::query()->create(['channel' => 'google_ads', 'brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->ads->id, 'status' => 'running']);
        app(AdvisorPlanWriter::class)->write($plan, [[
            'item_key' => $item->item_key, 'category' => 'waste', 'rule_id' => 'budget-waste', 'severity' => 'high', 'priority_score' => 1, 'impact_amount' => 1, 'impact_label' => null,
            'currency' => 'TRY', 'title' => 'Boşa harcama', 'reason' => 'r', 'evidence' => ['campaigns' => [['name' => 'A']]], 'checklist' => [], 'copy_text' => null, 'baseline' => null,
        ]]);
        $this->assertSame('open', $item->fresh()->status->value);
        $this->assertNull($item->fresh()->exported_at);
    }

    /** @param array<string, mixed> $evidence */
    private function item(string $rule, string $title, array $evidence): AdvisorItem
    {
        return AdvisorItem::query()->create([
            'channel' => 'google_ads', 'customer_id' => $this->brand->customer_id, 'brand_id' => $this->brand->id, 'digital_asset_id' => $this->ads->id,
            'item_key' => hash('sha256', $rule.$title), 'category' => 'waste', 'rule_id' => $rule, 'severity' => 'high', 'priority_score' => 500,
            'title' => $title, 'reason' => 'Neden', 'evidence' => $evidence, 'checklist' => [], 'status' => 'open', 'currency' => 'TRY',
            'first_seen_plan_id' => $this->plan->id, 'last_seen_plan_id' => $this->plan->id,
        ]);
    }
}
