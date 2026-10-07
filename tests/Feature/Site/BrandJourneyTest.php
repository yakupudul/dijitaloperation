<?php

namespace Tests\Feature\Site;

use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\BrandClusterPage;
use App\Models\BrandQuery;
use App\Models\Query;
use App\Models\Suggestion;
use App\Services\Brand\BrandJourney;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Queries\QueryNormalizer;
use App\Services\Site\SiteSuggestionTypes;
use Livewire\Livewire;

/**
 * Yolculuk (yakup, 2026-10-07): the brand page counts searches → its services → clusters → site matches → idea pool →
 * articles sent, marks the first empty stage with its reason and lists searches of services the brand does not offer.
 */
final class BrandJourneyTest extends SiteTestCase
{
    public function test_the_journey_counts_each_stage_and_marks_where_the_chain_breaks(): void
    {
        $journey = fn (): array => app(BrandJourney::class)->for($this->brand->fresh());
        $this->assertSame('searches', $journey()['broken']['key']);

        $cluster = $this->cluster($this->implant, 'İmplant fiyatları', ['implant fiyatları', 'implant fiyat']);
        foreach (Query::query()->pluck('id') as $id) {
            BrandQuery::query()->create(['brand_id' => $this->brand->id, 'query_id' => $id, 'language' => 'tr', 'impressions_28d' => 10]);
        }
        $this->assertSame('matched', $journey()['broken']['key'], 'searches, services and clusters are there; the site was not matched');

        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id, 'state' => 'no_page', 'language' => 'tr']);
        $this->assertSame('pool', $journey()['broken']['key']);

        Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => md5('x'), 'material_hash' => md5('x'),
            'title' => 'İmplant fiyatı neye göre değişir', 'reason' => 'Talep var.', 'priority' => 1, 'evidence' => [], 'action_type' => SiteSuggestionTypes::CONTENT,
            'action' => ['site_id' => $this->site->id, 'kind' => 'new'], 'status' => Suggestion::OPEN, 'target_type' => 'site', 'target_id' => $this->site->id,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $result = $journey();
        $this->assertNull($result['broken']);
        $this->assertSame([2, 2, 1, 1, 1, 0], array_column($result['stages'], 'value'));
        $this->assertSame('1 kümenin sayfası yok ya da zayıf', $result['stages'][3]['detail']);

        $orthodontics = app(ServiceCatalogService::class)->resolveOrCreate('Ortodonti', 'dental', actor: $this->admin)['service'];
        foreach (range(1, 5) as $n) {
            $query = Query::query()->create(['text' => 'tel tedavisi '.$n, 'text_hash' => QueryNormalizer::hash('tel tedavisi '.$n), 'sector_id' => $this->dental->id, 'service_id' => $orthodontics->id, 'assignment' => 'rule']);
            BrandQuery::query()->create(['brand_id' => $this->brand->id, 'query_id' => $query->id, 'language' => 'tr']);
        }
        $this->assertSame([['service' => 'Ortodonti', 'queries' => 5]], $journey()['foreign']);

        Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSeeHtml('data-brand-journey')->assertSee('Yolculuk')->assertSee('Ortodonti (5)');
    }
}
