<?php

namespace Tests\Feature\Portfolio;

use App\Enums\DigitalAssetStatus;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Services\Meta\MetaScreen;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Support\Integrations\Meta\MetaResourceType;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\SeedsMetaAccount;
use Tests\TestCase;

/**
 * Brand "Özet": Meta ad spend and results join the ad KPIs (Panorama Meta account: 4 200 spend, 56 → 112 results),
 * read without the entity snapshots and with both periods in one query, scoped like the Meta screen.
 */
final class BrandOverviewMetaTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMetaAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->seedMetaAccount();
        $this->actingAs($this->admin);
    }

    public function test_meta_spend_and_results_are_ad_kpis_with_change(): void
    {
        $page = Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSee('data-asset-card="'.$this->asset->id.'"', false)
            ->assertSee(route('operator.meta.overview', ['assetId' => $this->asset->id]), false);
        $kpis = collect($page->viewData('kpis'))->keyBy('key');

        $this->assertSame(['ok', '₺4.200', 0, 'Meta'], [$kpis['ad_spend']['state'], $kpis['ad_spend']['value'], $kpis['ad_spend']['delta'], $kpis['ad_spend']['source']]);
        $this->assertSame(['56', -50], [$kpis['ad_conversions']['value'], $kpis['ad_conversions']['delta']]);
        $this->assertSame('not_bound', $kpis['gbp_actions']['state']);

        Livewire::withQueryParams(['tab' => 'meta'])->test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSee('Panorama Meta')->assertSee('Meta Ads')->assertDontSee('data-channel-missing', false);
    }

    public function test_the_ad_kpis_read_no_entity_snapshots(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $kpis = collect(Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])->viewData('kpis'))->keyBy('key');
        $log = collect(DB::getQueryLog());
        DB::disableQueryLog();
        $snapshots = $log->filter(fn (array $q): bool => preg_match('/from "meta_(campaign|adset|adset_targeting|ad|creative)_snapshot"/', $q['query']) === 1)->count();
        $adDaily = $log->filter(fn (array $q): bool => str_contains($q['query'], 'from "meta_ad_daily"') && str_contains($q['query'], 'sum('))->count();

        $this->assertSame(0, $snapshots, 'spend and results need no campaign / ad set / ad / creative snapshot');
        $this->assertSame(1, $adDaily, 'current and previous period come from one ad daily query');
        $this->assertSame(['₺4.200', '56', -50], [$kpis['ad_spend']['value'], $kpis['ad_conversions']['value'], $kpis['ad_conversions']['delta']]);
    }

    public function test_each_table_keeps_its_own_scope_and_the_numbers_match_the_meta_screen(): void
    {
        // The ad daily rows were also collected centrally (no asset, the account's resource) with double the spend; the
        // typed actions only per asset (older rows without a resource). Central rows win per table, so spend is read
        // from the central copies once and results from the per-asset rows: never both copies.
        DB::table('meta_ad_daily')->where('digital_asset_id', $this->asset->id)->update(['external_resource_id' => null]);
        foreach (DB::table('meta_ad_daily')->where('digital_asset_id', $this->asset->id)->get() as $row) {
            DB::table('meta_ad_daily')->insert(['digital_asset_id' => null, 'external_resource_id' => $this->resource->id, 'spend' => (float) $row->spend * 2,
                'record_fingerprint' => hash('sha256', 'central'.$row->id)] + array_diff_key((array) $row, array_flip(['id', 'digital_asset_id', 'external_resource_id', 'spend', 'record_fingerprint'])));
        }

        $kpis = collect(Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])->viewData('kpis'))->keyBy('key');

        $this->assertSame(['₺8.400', 0], [$kpis['ad_spend']['value'], $kpis['ad_spend']['delta']]);
        $this->assertSame(['56', -50], [$kpis['ad_conversions']['value'], $kpis['ad_conversions']['delta']]);

        $screen = app(MetaScreen::class);
        $account = $screen->account($this->asset);
        $window = $screen->window($account, 28);
        $totals = $screen->kpiTotals($screen->accounts([$this->asset]), 28)[$this->asset->id];
        $this->assertEquals(MetaScreen::totals($screen->adPerformance($account, $window['from'], $window['to'])), $totals['current']);
        $this->assertEquals(MetaScreen::totals($screen->adPerformance($account, $window['prev_from'], $window['prev_to'])), $totals['previous']);
    }

    public function test_an_account_without_ad_rows_is_bound_without_data(): void
    {
        DB::table('meta_ad_daily')->delete();

        $kpis = collect(Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])->viewData('kpis'))->keyBy('key');

        $this->assertSame([], app(MetaScreen::class)->kpiTotals(app(MetaScreen::class)->accounts([$this->asset]), 28));
        $this->assertSame(['no_data', null, 'Meta'], [$kpis['ad_spend']['state'], $kpis['ad_spend']['value'], $kpis['ad_spend']['source']]);
    }

    public function test_accounts_with_their_own_last_day_and_scope_each_match_the_meta_screen(): void
    {
        // Panorama's ad rows are read centrally (its typed actions per asset); a second account, collected per asset
        // only, ends 10 days earlier (2026-10-19) and has 10 + i spend and 1 purchase on its i-th day back.
        DB::table('meta_ad_daily')->where('digital_asset_id', $this->asset->id)->update(['digital_asset_id' => null]);
        $second = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'meta_ads', 'module_id' => 'meta-ads', 'status' => DigitalAssetStatus::Active,
            'name' => 'Panorama Meta İzmir']);
        $resource = CoreExternalResource::factory()->create(['integration_id' => $this->resource->integration_id, 'provider' => 'meta', 'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_888', 'status' => CoreExternalResource::STATUS_AVAILABLE, 'metadata' => ['currency' => 'TRY', 'timezone_name' => 'Europe/Istanbul']]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $second->id, 'external_resource_id' => $resource->id, 'capability' => MetaAdsSpecialistBindingResolver::CAPABILITY,
            'status' => CoreAssetBinding::STATUS_ACTIVE]);
        for ($i = 0; $i < 40; $i++) {
            $date = CarbonImmutable::parse('2026-10-19')->subDays($i)->toDateString();
            $scope = ['digital_asset_id' => $second->id, 'external_resource_id' => null, 'account_id' => '888', 'reporting_date' => $date, 'currency' => 'TRY'];
            DB::table('meta_ad_daily')->insert($scope + ['ad_id' => 'ad9', 'spend' => 10 + $i, 'impressions' => 500, 'clicks' => 5, 'reach' => 400,
                'metadata' => json_encode(['campaign_id' => 'c9', 'adset_id' => 'as9'])] + $this->provenance('ad9'.$date));
            DB::table('meta_typed_action_daily')->insert($scope + ['entity_level' => 'ad', 'entity_id' => 'ad9', 'action_type' => 'purchase', 'action_value' => 1]
                + $this->provenance('ad9'.$date.'purchase'));
        }

        $screen = app(MetaScreen::class);
        $totals = $screen->kpiTotals($screen->accounts([$this->asset, $second]), 28);
        foreach (['2026-10-29' => $this->asset, '2026-10-19' => $second] as $end => $asset) {
            $reference = app(MetaScreen::class);
            $account = $reference->account($asset);
            $window = $reference->window($account, 28);
            $this->assertSame($end, $window['to']);
            $this->assertEquals(MetaScreen::totals($reference->adPerformance($account, $window['from'], $window['to'])), $totals[$asset->id]['current']);
            $this->assertEquals(MetaScreen::totals($reference->adPerformance($account, $window['prev_from'], $window['prev_to'])), $totals[$asset->id]['previous']);
        }

        // 4 200 + 658 now, 4 200 + 522 before (12 days collected); 56 + 28 results now, 112 + 12 before.
        $kpis = collect(Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])->viewData('kpis'))->keyBy('key');
        $this->assertSame(['₺4.858', 3], [$kpis['ad_spend']['value'], $kpis['ad_spend']['delta']]);
        $this->assertSame(['84', -32], [$kpis['ad_conversions']['value'], $kpis['ad_conversions']['delta']]);
    }
}
