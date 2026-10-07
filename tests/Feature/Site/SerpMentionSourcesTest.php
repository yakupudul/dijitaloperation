<?php

namespace Tests\Feature\Site;

use App\Livewire\Operator\Website\V2\BacklinksTab;
use App\Models\Backlink;
use App\Models\BacklinkSource;
use App\Models\BrandClusterSerp;
use Livewire\Livewire;

/**
 * Anılma fırsatları (yakup, 2026-10-07): directories and news sites in the top 10 of the brand's own searches become
 * link sources with the searches they show for; own, social, already-linking and already-listed sites are skipped.
 */
final class SerpMentionSourcesTest extends SiteTestCase
{
    public function test_directories_and_news_ranking_for_the_brand_become_sources(): void
    {
        $implant = $this->cluster($this->implant, 'İmplant fiyatları', ['implant fiyatları']);
        $zirkonyum = $this->cluster($this->zirkonyum, 'Zirkonyum kaplama', ['zirkonyum kaplama']);
        $serp = fn (int $clusterId, string $query, array $results) => BrandClusterSerp::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $clusterId, 'website_asset_id' => $this->site->id,
            'query' => $query, 'location_code' => 2792, 'language_code' => 'tr', 'device' => 'mobile', 'status' => 'ready', 'fetched_at' => now(), 'results' => $results]);
        $r = fn (int $rank, string $domain, string $class): array => ['rank' => $rank, 'url' => 'https://'.$domain.'/sayfa', 'domain' => $domain, 'title' => $domain, 'class' => $class];
        $serp($implant->id, 'implant fiyatları ankara', [$r(1, 'panorama.com.tr', 'kendi'), $r(2, 'www.doktortakvimi.com', 'dizin'), $r(3, 'rakip.com', 'ticari'), $r(4, 'youtube.com', 'dizin'), $r(6, 'ankarahaber.com', 'haber'), $r(7, 'baglanan.com', 'dizin')]);
        $serp($zirkonyum->id, 'zirkonyum kaplama', [$r(5, 'www.doktortakvimi.com', 'dizin'), $r(8, 'eklenmis.com', 'dizin')]);
        BacklinkSource::query()->create(['brand_id' => $this->brand->id, 'name' => 'Eklenmiş', 'url' => 'https://eklenmis.com/', 'domain' => 'eklenmis.com', 'kind' => 'dizin', 'fee' => 'teyit', 'origin' => 'manual', 'status' => BacklinkSource::NONE]);
        Backlink::query()->create(['brand_id' => $this->brand->id, 'source_url' => 'https://baglanan.com/x', 'source_domain' => 'baglanan.com', 'target_url' => 'https://panorama.com.tr/', 'link_hash' => md5('x'), 'first_seen' => now()->toDateString(), 'source' => 'manual', 'status' => 'live']);

        $this->artisan('moxdop:mentions:sync')->expectsOutputToContain('2 yeni kaynak')->assertSuccessful();

        $sources = BacklinkSource::query()->where('origin', 'serp')->get()->keyBy('domain');
        $this->assertSame(['www.doktortakvimi.com', 'ankarahaber.com'], $sources->keys()->all(), 'most searches first; own, rival, social, linking and listed sites skipped');
        $this->assertStringContainsString('2 aramanızda', $sources['www.doktortakvimi.com']->reason);
        $this->assertStringContainsString('en iyi 2. sıra', $sources['www.doktortakvimi.com']->reason);
        $this->assertSame('yerel_haber', $sources['ankarahaber.com']->kind);

        $this->artisan('moxdop:mentions:sync')->expectsOutputToContain('0 yeni kaynak')->assertSuccessful();
        Livewire::test(BacklinksTab::class, ['assetId' => $this->site->id])->assertSeeHtml('data-source-serp')->assertSee('www.doktortakvimi.com');
    }
}
