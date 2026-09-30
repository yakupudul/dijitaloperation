<?php

namespace Tests\Feature\Queries;

use App\Jobs\Queries\RescanQueriesJob;
use App\Livewire\Operator\Library\QueriesPage;
use App\Livewire\Operator\Library\QueryPlanWizard;
use App\Models\Cluster;
use App\Models\ClusterQuery;
use App\Models\Query;
use App\Models\QueryReviewItem;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Queries\KeywordInsights;
use App\Services\Queries\QueryRescanner;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Eşleme kelimeleri: kelime etkisi önizlemesi, kelime önerileri, çakışmalar, sektör uyumu. */
final class KeywordInsightsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceCategory $dental;

    private ServiceCategory $hair;

    private ServiceCatalogItem $implant;

    private ServiceCatalogItem $zirkonyum;

    private ServiceCatalogItem $kaplama;

    private ServiceCatalogItem $ortodonti;

    private ServiceCatalogItem $sacEkimi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $this->hair = ServiceCategory::query()->firstOrCreate(['code' => 'hair'], ['name' => 'Saç ekimi', 'normalized_key' => 'sac ekimi']);
        $catalog = app(ServiceCatalogService::class);
        $this->implant = $catalog->resolveOrCreate('Diş İmplantı', 'dental', actor: $this->admin)['service'];
        $this->zirkonyum = $catalog->resolveOrCreate('Zirkonyum', 'dental', actor: $this->admin)['service'];
        $this->kaplama = $catalog->resolveOrCreate('Diş Kaplama', 'dental', actor: $this->admin)['service'];
        $this->ortodonti = $catalog->resolveOrCreate('Ortodonti', 'dental', actor: $this->admin)['service'];
        $this->sacEkimi = $catalog->resolveOrCreate('Saç Ekimi', 'hair', actor: $this->admin)['service'];
        $keywords = app(ServiceKeywordService::class);
        $keywords->replace($this->implant, 'implant');
        $keywords->replace($this->zirkonyum, "zirkonyum\nzirkonyum kaplama");
        $keywords->replace($this->sacEkimi, 'saç ekimi');
    }

    public function test_keyword_impact_is_shown_before_saving_with_the_same_matcher_semantics(): void
    {
        $this->kaplamaLibrary();

        $impact = app(KeywordInsights::class)->impact($this->kaplama, 'Kaplama');
        $this->assertNull($impact['error']);
        $this->assertSame(6, $impact['total']);
        $this->assertSame(1, $impact['here'], 'porselen kaplama is already in the service');
        $this->assertSame(['Diş İmplantı' => 1], $impact['from']);
        $this->assertSame(1, $impact['unassigned']);
        $this->assertSame(1, $impact['kept'], 'the manual (locked) assignment stays');
        $this->assertSame(1, $impact['elsewhere'], '"kaplama" ⊂ "zirkonyum kaplama": zirkonyum keeps its query');
        $this->assertSame(1, $impact['conflict'], '"implant kaplama": implant / kaplama, neither contains the other');
        $this->assertSame(['zirkonyum kaplama fiyatları', 'kaplama nedir', 'diş kaplama', 'implant kaplama', 'porselen kaplama'], array_column($impact['examples'], 'text'), 'most impressions first');
        $this->assertSame(['elsewhere', 'comes', 'kept', 'conflict', 'stays'], array_column($impact['examples'], 'outcome'));
        $this->assertSame(2, $impact['pages']);
        $this->assertSame(['kaplama renkleri'], array_column(app(KeywordInsights::class)->impact($this->kaplama, 'kaplama', 1)['examples'], 'text'));
        $this->assertStringContainsString('Zirkonyum', (string) app(KeywordInsights::class)->impact($this->kaplama, 'zirkonyum')['error'], 'a keyword of another service in the sector');
        $this->assertSame('Bu kelime bu hizmette zaten var.', app(KeywordInsights::class)->impact($this->implant, 'İmplant')['error']);

        Queue::fake();
        $page = Livewire::test(QueriesPage::class)->set('sector', (string) $this->dental->id)->call('setTab', 'keywords')
            ->set('newKeyword.'.$this->kaplama->id, 'Kaplama')
            ->assertSee("Bu kelime 6 sorgu yakalayacak · 1'si şu an bu hizmette · 1'si başka hizmetten gelecek (Diş İmplantı: 1) · 1'si atanmamış")
            ->assertSee('1 sorgu elle / AI ile atanmış veya kilitli')->assertSee('1 sorgu çakışmaya düşer')->assertSee('zirkonyum kaplama fiyatları')
            ->call('impactPage', 1)->assertSee('kaplama renkleri')
            ->call('addKeyword', $this->kaplama->id)->assertHasNoErrors()->assertSet('impact', [])->assertDontSee('sorgu yakalayacak');
        Queue::assertPushed(RescanQueriesJob::class);
        $this->assertTrue($this->kaplama->matchingKeywords()->where('normalized_key', 'kaplama')->exists(), 'saved as today');

        // The rescan follows the preview: a rule assignment that turns into a conflict is proposed for removal.
        app(QueryRescanner::class)->scan($this->admin->id);
        $conflict = QueryReviewItem::query()->where('query_id', $this->queryId('implant kaplama'))->sole();
        $this->assertSame([QueryReviewItem::REASON_CONFLICT, 'implant / kaplama', $this->implant->id, null], [$conflict->reason, $conflict->term, $conflict->from_service_id, $conflict->to_service_id]);
        $this->assertSame($this->kaplama->id, QueryReviewItem::query()->where('query_id', $this->queryId('kaplama nedir'))->value('to_service_id'));
        $this->assertFalse(QueryReviewItem::query()->where('query_id', $this->queryId('zirkonyum kaplama fiyatları'))->exists());
        $this->assertFalse(QueryReviewItem::query()->where('query_id', $this->queryId('diş kaplama'))->exists());
        $page->set('reviewKind', QueryReviewItem::SERVICE)->call('setTab', 'deletions')->assertSee('atama kalkıyor · çakışma: implant / kaplama');
    }

    public function test_step_two_of_the_plan_wizard_previews_an_inline_keyword(): void
    {
        $this->kaplamaLibrary();

        Livewire::test(QueryPlanWizard::class)->call('goTo', 2)
            ->set('newKeyword.'.$this->kaplama->id, 'kaplama')
            ->assertSet('impact.total', 6)->assertSet('impact.conflict', 1)
            ->call('addKeyword', $this->kaplama->id)->assertHasNoErrors()->assertSet('impact', []);
    }

    public function test_keyword_suggestions_come_from_unassigned_queries_without_intent_words_locations_or_existing_keywords(): void
    {
        foreach (['diş teli fiyatları' => 100, 'şeffaf diş teli' => 80, 'diş teli ankara' => 60, 'diş teli nedir' => 40, 'istanbul diş teli yorumları' => 30,
            'kanal tedavisi' => 20, 'kanal tedavisi fiyat' => 10, 'ankara diş kliniği' => 5, 'kadıköy diş kliniği' => 5] as $text => $impressions) {
            $this->libraryQuery($text, null, $impressions);
        }
        $this->libraryQuery('implant fiyatı', $this->implant->id, 999, 'rule');
        $this->libraryQuery('implant fiyatı yorum', $this->implant->id, 999, 'rule');
        $insights = app(KeywordInsights::class);

        $suggestions = collect($insights->openSuggestions($this->dental->id))->keyBy('ngram');
        $this->assertSame(['dis', 'dis teli', 'kanal tedavisi', 'dis klinigi'], $suggestions->keys()->all(), 'most impressions first; a shorter n-gram in exactly the same queries adds nothing (kanal ⊂ kanal tedavisi)');
        $this->assertSame(7, $suggestions['dis']['count']);
        $this->assertSame(['label' => 'diş teli', 'count' => 5, 'impressions' => 310], array_intersect_key($suggestions['dis teli'], array_flip(['label', 'count', 'impressions'])));
        $this->assertSame(['diş teli fiyatları', 'şeffaf diş teli', 'diş teli ankara'], $suggestions['dis teli']['examples']);
        foreach (['fiyatlari', 'ankara', 'istanbul', 'kadikoy', 'implant', 'seffaf', 'yorumlari', 'nedir'] as $excluded) {
            $this->assertFalse($suggestions->has($excluded), $excluded.' is not suggested');
        }

        // Ekle → the "Kelime ekle" panel with the impact, then the keyword is saved as today.
        Queue::fake();
        $page = Livewire::test(QueriesPage::class)->set('sector', (string) $this->dental->id)->call('setTab', 'keywords')->call('setKeywordView', 'suggestions')
            ->assertSee('diş teli')->assertSee('310')->assertSee('şeffaf diş teli')
            ->set('suggestPick.dis_teli', (string) $this->ortodonti->id)->call('openSuggestion', 'dis teli')
            ->assertSet('draftOpen', true)->assertSet('draftKeyword', 'diş teli')->assertSet('impact.total', 5)->assertSet('impact.unassigned', 5)
            ->assertSee('Bu kelime 5 sorgu yakalayacak')
            ->call('saveDraft')->assertHasNoErrors()->assertSet('draftOpen', false)->assertSee('"diş teli" eklendi');
        Queue::assertPushed(RescanQueriesJob::class);
        $this->assertTrue($this->ortodonti->matchingKeywords()->where('normalized_key', 'dis teli')->exists());
        $this->assertFalse(collect($insights->openSuggestions($this->dental->id))->contains('ngram', 'dis teli'), 'already a keyword of the sector');

        // "Yok say" is remembered per sector.
        $page->call('dismissSuggestion', 'kanal tedavisi')->assertDontSee('kanal tedavisi');
        $this->assertFalse(collect($insights->openSuggestions($this->dental->id))->contains('ngram', 'kanal tedavisi'));
        $this->assertDatabaseHas('query_keyword_dismissals', ['sector_id' => $this->dental->id, 'ngram' => 'kanal tedavisi']);

        // Cached; a rescan (or "Yenile") counts again.
        $this->libraryQuery('gülüş tasarımı', null, 50);
        $this->libraryQuery('gülüş tasarımı nedir', null, 50);
        $this->assertFalse(collect($insights->openSuggestions($this->dental->id))->contains('ngram', 'gulus tasarimi'));
        app(QueryRescanner::class)->scan(null);
        $this->assertTrue(collect($insights->openSuggestions($this->dental->id))->contains('ngram', 'gulus tasarimi'));
    }

    public function test_conflicts_are_listed_and_resolved_by_hand_or_with_a_longer_keyword(): void
    {
        $ruleConflict = $this->libraryQuery('zirkonyum mu implant mı', $this->implant->id, 300, 'rule');
        $open = $this->libraryQuery('implant üstü zirkonyum', null, 200);
        $third = $this->libraryQuery('implant zirkonyum farkı', null, 100);
        $this->libraryQuery('zirkonyum implant yorum', $this->implant->id, 900, 'manual', locked: true);
        $this->libraryQuery('implant fiyatı', $this->implant->id, 50, 'rule');

        $this->assertSame([$ruleConflict->id, $open->id, $third->id, $this->queryId('zirkonyum implant yorum')], array_keys(app(KeywordInsights::class)->conflicts($this->dental->id)));

        // Rescan: only a rule assignment that becomes a conflict gets a line (unassigned conflicts stay as they are).
        app(QueryRescanner::class)->scan(null);
        $this->assertSame([$ruleConflict->id], QueryReviewItem::query()->pluck('query_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame('zirkonyum / implant', QueryReviewItem::query()->value('term'));

        $page = Livewire::test(QueriesPage::class)->set('sector', (string) $this->dental->id)->call('setTab', 'keywords')
            ->assertSeeHtml('data-keyword-view="conflicts"')->assertSee('Çakışmalar · 3')
            ->call('setKeywordView', 'conflicts')
            ->assertSee('zirkonyum mu implant mı')->assertSee('implant üstü zirkonyum')->assertDontSee('zirkonyum implant yorum')
            ->assertSee('zirkonyum → Zirkonyum')->assertSee('implant → Diş İmplantı');

        // One line by hand (locked).
        $page->set('conflictPick.'.$ruleConflict->id, (string) $this->zirkonyum->id)->call('resolveConflict', $ruleConflict->id)->assertSee('1 sorgu hizmete atandı (kilitli).');
        $this->assertSame(['service_id' => $this->zirkonyum->id, 'assignment' => 'manual', 'locked' => true],
            $ruleConflict->fresh()->only(['service_id', 'assignment', 'locked']));
        $page->assertDontSee('zirkonyum mu implant mı');

        // A longer keyword (inline, with its impact): "implant üstü zirkonyum" contains both → nested, it wins.
        $page->call('openConflictKeyword', $open->id)->assertSet('draftKeyword', 'implant üstü zirkonyum')
            ->set('draftService', (string) $this->zirkonyum->id)->assertSet('impact.total', 1)->assertSet('impact.unassigned', 1)
            ->call('saveDraft')->assertHasNoErrors();
        $this->assertArrayNotHasKey($open->id, app(KeywordInsights::class)->conflicts($this->dental->id));
        $this->assertSame([$third->id], $page->viewData('conflictRows')->pluck('id')->map(fn ($id): int => (int) $id)->all());

        // Bulk: ticked lines → the chosen service.
        $page->set('selected', [$third->id])->set('bulkService', (string) $this->implant->id)->call('assignConflicts')->assertSee('1 sorgu hizmete atandı (kilitli).');
        $this->assertSame($this->implant->id, $third->fresh()->service_id);
        $this->assertTrue($third->fresh()->locked);
        $page->assertSee('Çakışma yok.');
    }

    public function test_sector_mismatches_are_listed_flagged_in_the_rescan_and_moved_or_cleared(): void
    {
        $wrong = $this->libraryQuery('saç ekimi diş', $this->sacEkimi->id, 40, 'rule');
        $wrongManual = $this->libraryQuery('saç ekimi fiyat diş', $this->sacEkimi->id, 30, 'manual', locked: true);
        $this->libraryQuery('saç ekimi', $this->sacEkimi->id, 500, 'rule', sector: $this->hair);
        $this->libraryQuery('implant', $this->implant->id, 10, 'rule');

        $pairs = app(KeywordInsights::class)->sectorMismatches(null);
        $this->assertCount(1, $pairs);
        $this->assertSame(['sector_id' => $this->dental->id, 'sector' => 'Diş sağlığı', 'code' => 'hair', 'target_id' => $this->hair->id, 'target' => 'Saç ekimi', 'total' => 2,
            'examples' => ['saç ekimi diş', 'saç ekimi fiyat diş']], $pairs[0]);

        // The rescan proposes removing the rule assignment and says why.
        app(QueryRescanner::class)->scan(null);
        $line = QueryReviewItem::query()->where('query_id', $wrong->id)->sole();
        $this->assertSame([QueryReviewItem::REASON_SECTOR, null], [$line->reason, $line->to_service_id]);
        $this->assertFalse(QueryReviewItem::query()->where('query_id', $wrongManual->id)->exists(), 'manual stays');
        $page = Livewire::test(QueriesPage::class)->set('reviewKind', QueryReviewItem::SERVICE)->call('setTab', 'deletions')
            ->assertSee('atama kalkıyor · hiçbir eşleme kelimesi eşleşmiyor · sektör uyuşmuyor');

        $page->call('setTab', 'keywords')->assertSee('Sektör uyumu · 2')->call('setKeywordView', 'sectors')
            ->assertSee('saç ekimi fiyat diş')->assertSee('Hizmetin sektörüne taşı')
            ->call('moveMismatch', $this->dental->id, 'hair')->assertSee('2 sorgu hizmetin sektörüne taşındı.')->assertSee('Sektör uyumsuzluğu yok.');
        $this->assertSame($this->hair->id, $wrong->fresh()->sector_id);

        // Clear: no service, unlocked, out of its clusters.
        $wrong->fresh()->forceFill(['sector_id' => $this->dental->id])->save();
        $cluster = Cluster::query()->create(['sector_id' => $this->hair->id, 'service_id' => $this->sacEkimi->id, 'name' => 'Saç']);
        ClusterQuery::query()->create(['cluster_id' => $cluster->id, 'query_id' => $wrong->id]);
        $page->call('clearMismatch', $this->dental->id, 'hair')->assertSee('1 sorgunun hizmeti kaldırıldı');
        $this->assertSame(['service_id' => null, 'assignment' => 'none', 'locked' => false], $wrong->fresh()->only(['service_id', 'assignment', 'locked']));
        $this->assertFalse(ClusterQuery::query()->where('query_id', $wrong->id)->exists());
    }

    /** Library for the "kaplama" preview of Diş Kaplama (keywords: implant → İmplant, zirkonyum / zirkonyum kaplama → Zirkonyum). */
    private function kaplamaLibrary(): void
    {
        $this->libraryQuery('zirkonyum kaplama fiyatları', $this->zirkonyum->id, 500, 'rule');
        $this->libraryQuery('kaplama nedir', null, 300);
        $this->libraryQuery('diş kaplama', $this->zirkonyum->id, 200, 'manual', locked: true);
        $this->libraryQuery('implant kaplama', $this->implant->id, 100, 'rule');
        $this->libraryQuery('porselen kaplama', $this->kaplama->id, 50, 'rule');
        $this->libraryQuery('kaplama renkleri', $this->implant->id, 40, 'rule');
        $this->libraryQuery('implant fiyatı', $this->implant->id, 1000, 'rule');
    }

    private function libraryQuery(string $text, ?int $serviceId, int $impressions, string $assignment = 'none', bool $locked = false, ?ServiceCategory $sector = null): Query
    {
        return Query::query()->create(['text' => $text, 'text_hash' => hash('sha256', $text), 'sector_id' => ($sector ?? $this->dental)->id, 'service_id' => $serviceId,
            'assignment' => $assignment, 'locked' => $locked, 'impressions' => $impressions]);
    }

    private function queryId(string $text): int
    {
        return (int) Query::query()->where('text', $text)->value('id');
    }
}
