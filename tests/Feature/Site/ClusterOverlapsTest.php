<?php

namespace Tests\Feature\Site;

use App\Livewire\Operator\Work\WorkPage;
use App\Models\BrandClusterPage;
use App\Models\Cluster;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Site\ClusterOverlaps;
use Livewire\Livewire;

/**
 * Küme çakışmaları (yakup, 2026-10-04: "aynı iş bir marka için tekrar tekrar söylüyor"): cluster rows are per language,
 * yet a page in another language is never an overlap and one cluster · main page · page pair is one work item; in
 * Genel işler the overlaps of a site sit in one card, under their cluster.
 */
final class ClusterOverlapsTest extends SiteTestCase
{
    private Cluster $treatment;

    private Page $main;

    private Page $copy;

    private Page $enMain;

    private Page $enCopy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->main = $this->page('/implant/', 'İmplant Tedavisi', ['category' => 'hizmet']);
        $this->copy = $this->page('/implant-tedavisi-nedir/', 'İmplant tedavisi nedir', ['category' => 'blog']);
        $this->enMain = $this->page('/en/dental-implant/', 'Dental Implant', ['category' => 'hizmet', 'language' => 'en']);
        $this->enCopy = $this->page('/en/what-is-an-implant/', 'What is an implant', ['category' => 'blog', 'language' => 'en']);
        $this->treatment = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi']);
    }

    public function test_a_page_in_another_language_is_never_an_overlap_and_language_rows_share_one_item(): void
    {
        $this->row('tr', $this->main, [$this->copy->id, $this->enCopy->id]);
        $this->row(null, $this->main, [$this->copy->id]);
        $this->row('en', $this->enMain, [$this->copy->id, $this->enCopy->id]);

        $this->assertSame(2, app(ClusterOverlaps::class)->sync($this->site, $this->brand));

        $open = Suggestion::query()->where('decision_key', ClusterOverlaps::DECISION)->actionable()->get();
        $this->assertSame([$this->copy->id => $this->main->id, $this->enCopy->id => $this->enMain->id],
            $open->mapWithKeys(fn (Suggestion $s): array => [(int) $s->page_id => (int) data_get($s->action, 'main_page_id')])->sortKeys()->all(),
            'TR row ↔ EN page and EN row ↔ TR page are translations; the TR and language-less rows find the same pair once');
    }

    public function test_an_old_per_language_duplicate_keeps_its_decision_and_the_extra_copy_closes(): void
    {
        $tr = $this->row('tr', $this->main, [$this->copy->id]);
        $plain = $this->row(null, $this->main, [$this->copy->id]);
        $kept = $this->legacy($tr, Suggestion::DISMISSED);
        $extra = $this->legacy($plain, Suggestion::OPEN);
        $wrongLanguage = $this->legacy($this->row('en', $this->enMain, [$this->copy->id]), Suggestion::OPEN, $this->enMain);

        $this->artisan('moxdop:clusters:sync-overlaps')->expectsOutputToContain('küme çakışması')->assertSuccessful();

        $this->assertSame(Suggestion::DISMISSED, $kept->fresh()->status, '"Ayrı kalsın" is not asked again');
        $this->assertSame(hash('sha256', implode('|', [$this->brand->id, 'cluster-overlap', $this->treatment->id, $this->main->id, $this->copy->id])), $kept->fresh()->fingerprint);
        $this->assertSame([Suggestion::APPLIED, 'Çakışma kalktı.'], [$extra->fresh()->status, $extra->fresh()->operator_note]);
        $this->assertSame(Suggestion::APPLIED, $wrongLanguage->fresh()->status, 'the cross-language 301 proposal is gone');
        $this->assertSame([], Suggestion::query()->where('decision_key', ClusterOverlaps::DECISION)->actionable()->pluck('id')->all());
    }

    public function test_genel_isler_shows_a_sites_overlaps_in_one_card_under_their_cluster(): void
    {
        $second = $this->page('/tek-seansta-implant/', 'Tek seansta implant', ['category' => 'blog']);
        $this->row('tr', $this->main, [$this->copy->id, $second->id]);
        app(ClusterOverlaps::class)->sync($this->site, $this->brand);

        $html = Livewire::test(WorkPage::class)->assertSee('«İmplant tedavisi»')->assertSee('/implant-tedavisi-nedir/')->assertSee('/tek-seansta-implant/')
            ->assertSee('2 sayfa aynı ihtiyaca yanıt veriyor')->assertSee('301 öneriliyor')->assertSeeHtml('data-work-action="merge"')->html();

        $this->assertSame(1, substr_count($html, 'data-work-group='), 'one card for the site');
        $this->assertSame(1, substr_count($html, 'data-work-rule'), 'the rule is said once');
        $this->assertSame(1, substr_count($html, '«İmplant tedavisi»'), 'the cluster is named once');
        $this->assertStringNotContainsString('Çakışma: «', $html, 'no row repeats the cluster and the main page');
    }

    /** @param  list<int>  $overlaps */
    private function row(?string $language, Page $main, array $overlaps): BrandClusterPage
    {
        return BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $this->treatment->id, 'website_asset_id' => $this->site->id,
            'language' => $language, 'state' => 'possible_conflict', 'page_id' => $main->id, 'overlap_page_ids' => $overlaps]);
    }

    /** A suggestion as the old sync stored it: fingerprint with the language row id. */
    private function legacy(BrandClusterPage $row, string $status, ?Page $main = null): Suggestion
    {
        $main ??= $this->main;

        return Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => ClusterOverlaps::DECISION,
            'fingerprint' => hash('sha256', implode('|', [$this->brand->id, 'cluster-overlap', $row->id, $this->copy->id])),
            'material_hash' => hash('sha256', ClusterOverlaps::REDIRECT.'|'.$main->id), 'title' => 'Çakışma', 'reason' => 'Eski kayıt', 'priority' => 2,
            'evidence' => [], 'action_type' => ClusterOverlaps::TYPE, 'target_type' => 'page', 'target_id' => $this->copy->id, 'page_id' => $this->copy->id,
            'cluster_id' => $this->treatment->id, 'status' => $status,
            'action' => ['site_id' => $this->site->id, 'row_id' => $row->id, 'main_page_id' => $main->id, 'recommendation' => ClusterOverlaps::REDIRECT],
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }
}
