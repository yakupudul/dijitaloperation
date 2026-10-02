<?php

namespace Tests\Feature\Site;

use App\Livewire\Operator\Workspace\BrandDossierTab;
use App\Models\BrandClusterPage;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Brand\BrandAudit;
use Livewire\Livewire;

/**
 * Şef denetimi: errors in what the AI decided for the brand, found with rules (no AI), fixed only by taking the wrong
 * decision back, never reported again once accepted — no "check → find more work → check" loop.
 */
final class BrandAuditTest extends SiteTestCase
{
    public function test_errors_in_ai_decisions_are_found_fixed_by_taking_them_back_or_accepted_once(): void
    {
        // 1. AI called a keyword page a service page.
        $keyword = $this->page('/kws/ankara-cankaya-implant/', 'Ankara Çankaya implant', ['category' => 'hizmet', 'category_source' => 'ai']);
        // 2. AI linked the zirkonyum page to the implant service.
        $crownPage = $this->page('/zirkonyum-kaplama/', 'Zirkonyum Kaplama', ['category' => 'hizmet', 'category_source' => 'rule']);
        OfferingPage::query()->create(['brand_offering_id' => $this->implantOffering->id, 'page_id' => $crownPage->id, 'source' => 'ai', 'locked' => false]);
        // 3. AI matched an implant cluster to a page of the zirkonyum service.
        $veneer = $this->page('/zirkonyum/', 'Zirkonyum', ['category' => 'hizmet']);
        OfferingPage::query()->create(['brand_offering_id' => $this->zirkonyumOffering->id, 'page_id' => $veneer->id, 'source' => 'rule', 'locked' => false]);
        $cluster = $this->cluster($this->implant, 'İmplant fiyatları', ['implant fiyatları']);
        $row = BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id,
            'page_id' => $veneer->id, 'state' => 'sufficient', 'decided_by' => 'ai', 'coverage' => 'full', 'audited_at' => now()]);
        // 4. A work item whose page is gone; a right decision that must stay untouched.
        $gone = $this->page('/eski/', 'Eski sayfa');
        $dead = Suggestion::query()->create(['brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.missing_topic', 'fingerprint' => 'dead-1', 'material_hash' => 'x',
            'title' => 'Eski sayfaya bölüm ekle', 'reason' => '-', 'status' => Suggestion::OPEN, 'action_type' => 'missing_topic', 'target_type' => 'page', 'target_id' => $gone->id, 'page_id' => $gone->id]);
        $gone->delete();
        $implantPage = $this->page('/implant/', 'Diş İmplantı', ['category' => 'hizmet', 'category_source' => 'ai']);
        OfferingPage::query()->create(['brand_offering_id' => $this->implantOffering->id, 'page_id' => $implantPage->id, 'source' => 'ai', 'locked' => false]);

        $this->assertSame(4, app(BrandAudit::class)->sync($this->brand));
        $findings = Suggestion::query()->where('decision_key', BrandAudit::DECISION)->get()->keyBy(fn (Suggestion $s): string => explode(':', (string) data_get($s->action, 'check'))[0]);
        $this->assertSame([$keyword->id], data_get($findings['service_pages']->action, 'items'));
        $this->assertSame([$crownPage->id], data_get($findings['service_links']->action, 'items'), 'a right AI link is no error');
        $this->assertSame([$row->id], data_get($findings['cluster_pages']->action, 'items'));
        $this->assertSame([$dead->id], data_get($findings['dead_work']->action, 'items'));

        $tab = Livewire::test(BrandDossierTab::class, ['brandId' => $this->brand->id])
            ->assertSeeHtml('data-brand-audit')->assertSee('Şef denetimi · 4 hata')->assertSee('AI 1 kümeyi başka hizmetin sayfasıyla eşleştirmiş');

        $tab->call('fixAudit', $findings['service_pages']->id)->assertSee('Sınıflandırma kurallarla yeniden yapıldı');
        $this->assertSame(['diger', 'rule'], [$keyword->fresh()->category, $keyword->fresh()->category_source]);

        $tab->call('fixAudit', $findings['service_links']->id)->assertSee('1 sayfanın hizmeti düzeltildi');
        $link = OfferingPage::query()->where('page_id', $crownPage->id)->sole();
        $this->assertSame([$this->zirkonyumOffering->id, true], [(int) $link->brand_offering_id, (bool) $link->locked], 'pinned to the service the page is named after');

        $tab->call('fixAudit', $findings['dead_work']->id)->assertSee('1 iş kapatıldı');
        $this->assertSame(Suggestion::DISMISSED, $dead->fresh()->status);

        // "Doğru, bırak": accepted items are never reported again; a NEW error of the same kind opens it with the new item only.
        $tab->call('acceptAudit', $findings['cluster_pages']->id)->assertSee('bir daha hata sayılmaz');
        $this->assertSame(0, app(BrandAudit::class)->sync($this->brand), 'nothing new: no finding, no work');
        $this->assertSame($veneer->id, $row->fresh()->page_id, 'accepted: untouched');
        $second = $this->cluster($this->implant, 'İmplant markaları', ['implant markaları']);
        $newRow = BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $second->id, 'website_asset_id' => $this->site->id,
            'page_id' => $veneer->id, 'state' => 'sufficient', 'decided_by' => 'ai', 'coverage' => 'full', 'audited_at' => now()]);
        $this->assertSame(1, app(BrandAudit::class)->sync($this->brand));
        $reopened = $findings['cluster_pages']->fresh();
        $this->assertSame([Suggestion::OPEN, [$newRow->id]], [$reopened->status, data_get($reopened->action, 'items')]);
        $tab->call('fixAudit', $reopened->id)->assertSee('1 küme eşleşmesi kaldırıldı');
        $this->assertSame([null, 'no_page', 'audit'], [$newRow->fresh()->page_id, $newRow->fresh()->state, $newRow->fresh()->decided_by]);
        $this->assertSame(0, app(BrandAudit::class)->sync($this->brand));
        $this->assertSame(1, Page::query()->where('category', 'hizmet')->where('path', '/implant/')->count());
    }
}
