<?php

namespace Tests\Feature\Reports;

use App\Ai\Agents\MonthlyReportCommentaryAgent;
use App\Enums\CustomerStatus;
use App\Livewire\Operator\Reports\MonthlyReportsPage;
use App\Models\AiProduction;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\MonthlyReport;
use App\Models\User;
use App\Services\MonthlyReport\MonthlyReportBuilder;
use App\Services\MonthlyReport\MonthlyReportService;
use App\Services\MonthlyReport\ReportChart;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 9a/9b: monthly report v2 — channel KPIs vs previous month and last year, daily series, AI commentary on
 * click, operator edits, publish and the signed client link.
 */
final class MonthlyReportV2Test extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $site;

    private DigitalAsset $ads;

    private int $resourceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.anthropic.api_key' => 'sk-ant-test']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $this->brand = Brand::factory()->create(['name' => 'Atlas Dental', 'customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website']);
        $this->ads = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads']);
        $this->resourceId = (int) CoreExternalResource::factory()->create()->id;
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->ads->id, 'external_resource_id' => $this->resourceId, 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        foreach (['2026-09' => [100, 1000], '2026-08' => [80, 1000], '2025-09' => [50, 500]] as $month => [$clicks, $impressions]) {
            foreach ([1, 2] as $day) {
                $this->row('gsc_property_daily', $this->site->id, $month.'-0'.$day, ['site_url' => 'sc-domain:atlas.test', 'clicks' => $clicks / 2, 'impressions' => $impressions / 2]);
            }
        }
        foreach (['2026-09' => [300, 6], '2026-08' => [300, 3]] as $month => [$cost, $conversions]) {
            $this->row('google_ads_campaign_daily', $this->ads->id, $month.'-10', ['customer_id' => '1', 'campaign_id' => 'c1', 'impressions' => 1000, 'clicks' => 50, 'cost_micros' => $cost * 1_000_000, 'cost_amount' => $cost, 'conversions' => $conversions, 'currency' => 'TRY']);
        }
        // A legacy per-asset copy next to a central row must not double count.
        $this->row('google_ads_campaign_daily', null, '2026-09-10', ['customer_id' => '1', 'campaign_id' => 'c1', 'impressions' => 1000, 'clicks' => 50, 'cost_micros' => 300_000_000, 'cost_amount' => 300, 'conversions' => 6, 'currency' => 'TRY']);
    }

    public function test_builder_compares_months_and_years(): void
    {
        DB::table('google_ads_campaign_daily')->whereNull('digital_asset_id')->delete();
        $payload = app(MonthlyReportBuilder::class)->build($this->brand, '2026-09');

        $this->assertSame(['Eylül 2026', 'Ağustos 2026', 'Eylül 2025'], [$payload['period']['label'], $payload['period']['previous_label'], $payload['period']['last_year_label']]);
        $search = collect($payload['channels']['search']['kpis'])->keyBy('key');
        $this->assertSame([100.0, 80.0, 50.0, 25.0, 100.0], [$search['clicks']['value'], $search['clicks']['previous'], $search['clicks']['last_year'], $search['clicks']['change_pct'], $search['clicks']['yoy_pct']]);
        $this->assertSame([10.0, 8.0], [$search['ctr']['value'], $search['ctr']['previous']], 'CTR in percent');
        $this->assertSame([1 => 50.0, 2 => 50.0], $payload['channels']['search']['series']['current']);

        $ads = collect($payload['channels']['google_ads']['kpis'])->keyBy('key');
        $this->assertSame([300.0, 6.0, 50.0, 100.0, -50.0], [$ads['cost']['value'], $ads['conversions']['value'], $ads['cpa']['value'], $ads['cpa']['previous'], $ads['cpa']['change_pct']]);
        $this->assertFalse($payload['channels']['meta']['available'], 'no data is missing, not zero');
        $this->assertFalse($payload['channels']['gbp']['available']);
        $this->assertTrue(collect($payload['highlights'])->contains(fn (string $h): bool => str_contains($h, 'Tıklama bir önceki aya göre %25 arttı')));
        $this->assertTrue(collect($payload['highlights'])->contains(fn (string $h): bool => str_contains($h, 'Dönüşüm başı maliyet') && ! str_contains($h, 'incelenecek')), 'lower CPA is good news');

        $svg = ReportChart::line([1 => 10, 2 => 30], [1 => 5, 2 => 5], 'Tıklama <günlük>');
        $this->assertStringContainsString('<polyline', $svg);
        $this->assertStringContainsString('Tıklama &lt;günlük&gt;', $svg);
    }

    public function test_central_rows_win_over_legacy_copies(): void
    {
        DB::table('google_ads_campaign_daily')->whereNotNull('digital_asset_id')->where('reporting_date', '2026-08-10')->delete();
        $payload = app(MonthlyReportBuilder::class)->build($this->brand, '2026-09');
        $this->assertSame(300.0, collect($payload['channels']['google_ads']['kpis'])->firstWhere('key', 'cost')['value']);
    }

    public function test_page_prepares_commentary_edit_publish_and_client_link(): void
    {
        MonthlyReportCommentaryAgent::fake([[
            'summary' => 'Eylülde Google aramadan gelen tıklamalar %25 arttı.', 'wins' => ['Tıklama 80 → 100'],
            'watch' => ['Reklam harcaması sabit kaldı'], 'next_month' => ['Implant sayfası yenilenecek'],
        ]]);
        $page = Livewire::test(MonthlyReportsPage::class, ['brand' => $this->brand->id, 'month' => '2026-09'])
            ->assertSee('Raporu hazırla')->call('prepare')->assertSee('Google arama (Search Console)')->assertSee('Eylül 2025');
        $report = MonthlyReport::query()->firstOrFail();

        $page->call('writeCommentary');
        $report->refresh();
        $this->assertSame('ready', $report->commentary_status, (string) json_encode($report->commentary));
        $this->assertSame('llm', $report->commentary['source']);
        $this->assertSame(1, AiProduction::query()->where('kind', 'report.monthly_commentary')->count(), 'archived');
        $page->call('$refresh')->assertSee('Eylülde Google aramadan gelen');

        $page->call('startEdit')->set('edit.summary', 'Düzenlenmiş özet.')->set('edit.note', 'Ekimde toplantı yapalım.')->call('saveEdit');
        $this->assertSame(['Düzenlenmiş özet.', 'operator', 'Ekimde toplantı yapalım.'], [$report->fresh()->commentary['summary'], $report->fresh()->commentary['source'], $report->fresh()->operator_note]);

        $this->get(route('operator.reports.monthly.preview', ['report' => $report->id]))->assertOk()->assertSee('Önizleme')->assertSee('Düzenlenmiş özet.');
        $unsigned = route('monthly-report.client', ['report' => $report->id]);
        $this->get($unsigned)->assertForbidden();

        $page->call('publish');
        $url = app(MonthlyReportService::class)->clientUrl($report->fresh());
        auth()->logout();
        $this->get($url)->assertOk()->assertSee('Atlas Dental — Eylül 2026 dijital raporu')->assertSee('Düzenlenmiş özet.')->assertSee('<svg', false)->assertDontSee('Önizleme');
        $this->travel(61)->days();
        $this->get($url)->assertForbidden();
    }

    public function test_draft_is_not_visible_by_link_and_invalid_month_is_rejected(): void
    {
        $report = app(MonthlyReportService::class)->prepare($this->brand, '2026-09');
        auth()->logout();
        $this->get(app(MonthlyReportService::class)->clientUrl($report))->assertNotFound();
        $this->expectException(ValidationException::class);
        app(MonthlyReportService::class)->prepare($this->brand, '2027-01');
    }

    /** @param array<string, mixed> $values */
    private function row(string $table, ?int $assetId, string $date, array $values): void
    {
        DB::table($table)->insert($values + [
            'digital_asset_id' => $assetId, 'external_resource_id' => $assetId === null ? $this->resourceId : null, 'reporting_date' => $date,
            'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', $table.$date.json_encode($values).$assetId), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
