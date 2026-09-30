<?php

namespace Tests\Feature\Portfolio;

use App\Livewire\Operator\Portfolio\BrandShow;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\SeedsMetaAccount;
use Tests\TestCase;

/** Brand "Özet": Meta ad spend and results join the ad KPIs (Panorama Meta account: 4 200 spend, 56 → 112 results). */
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
}
