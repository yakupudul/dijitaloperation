<?php

namespace Tests\Feature\Queries;

use App\Enums\NotificationKind;
use App\Jobs\Queries\RescanQueriesJob;
use App\Livewire\Demo\NotificationBell;
use App\Livewire\Operator\Library\QueriesPage;
use App\Livewire\Operator\NotificationToast;
use App\Models\Brand;
use App\Models\Cluster;
use App\Models\ClusterQuery;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\FilterTerm;
use App\Models\PendingQuery;
use App\Models\Query;
use App\Models\QueryReview;
use App\Models\QueryReviewItem;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Queries\QueryNormalizer;
use App\Services\Queries\QueryPipeline;
use App\Services\Queries\QueryRescanner;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Bekleyenler, "Filtreye ekle" → onaylı tarama → bildirim → Silinecekler sekmesi, eşleme kelimesi değişikliği → onay / tut. */
final class QueryReviewFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceCategory $dental;

    private ServiceCatalogItem $implant;

    private ServiceCatalogItem $zirkonyum;

    private Brand $brand;

    private DigitalAsset $site;

    private CoreExternalResource $gsc;

    private CoreExternalResource $ads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $catalog = app(ServiceCatalogService::class);
        $this->implant = $catalog->resolveOrCreate('Diş İmplantı', 'dental', actor: $this->admin)['service'];
        $this->zirkonyum = $catalog->resolveOrCreate('Zirkonyum Kaplama', 'dental', actor: $this->admin)['service'];
        app(ServiceKeywordService::class)->replace($this->implant, 'implant');
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Panorama Ankara', 'sector_id' => $this->dental->id]);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'panorama.com.tr']);
        $this->gsc = CoreExternalResource::factory()->searchConsole()->create();
        $this->ads = CoreExternalResource::factory()->create(['resource_type' => 'google_ads', 'external_id' => '1234567890']);
        foreach ([$this->gsc, $this->ads] as $resource) {
            CoreAssetBinding::factory()->create(['digital_asset_id' => $this->site->id, 'external_resource_id' => $resource->id, 'capability' => $resource->resource_type]);
        }
        $this->source('implant fiyatları', '2026-07-01', 100);
        $this->source('zirkonyum fiyatları', '2026-07-01', 50);
        app(QueryPipeline::class)->run(import: true);
    }

    public function test_pending_queue_holds_only_new_unfiltered_queries_once_keeps_metrics_of_library_queries_and_imports_or_dismisses(): void
    {
        $forum = FilterTerm::query()->create(['sector_id' => null, 'term' => 'forum']);
        $this->source('implant fiyatları', '2026-08-01', 40);
        $this->source('İmplant Fiyatı Yeni', '2026-08-01', 30);
        $this->source('implant fiyatı yeni?', '2026-08-01', 20, $this->ads);
        $this->source('implant forum', '2026-08-01', 10);
        $this->source('implant kaplama', '2026-08-01', 8);

        app(QueryPipeline::class)->run();

        $this->assertSame(2, Query::query()->count(), 'new queries never enter the library on their own');
        $this->assertSame(140, Query::query()->where('text', 'implant fiyatları')->value('impressions'), 'library metrics keep updating');
        $pending = PendingQuery::query()->orderBy('text')->get()->keyBy('text');
        $this->assertSame(['implant fiyatı yeni', 'implant kaplama'], $pending->keys()->all(), 'one row per normalized text; library texts and filtered texts excluded');
        $this->assertSame(50, $pending['implant fiyatı yeni']->impressions);
        $this->assertSame($this->implant->id, $pending['implant fiyatı yeni']->service_id, 'suggested service from matching keywords');
        $this->assertSame($this->brand->id, $pending['implant kaplama']->brand_id);

        // Filter basket changes are followed: a removed term lets the text in, a new term takes it out (rescan).
        $forum->delete();
        app(QueryPipeline::class)->run();
        $this->assertTrue(PendingQuery::query()->where('text', 'implant forum')->where('status', PendingQuery::PENDING)->exists());
        FilterTerm::query()->create(['sector_id' => null, 'term' => 'kaplama']);
        FilterTerm::query()->create(['sector_id' => null, 'term' => 'forum']);
        RescanQueriesJob::dispatch($this->admin->id);
        $this->assertSame(['implant fiyatı yeni'], PendingQuery::query()->where('status', PendingQuery::PENDING)->pluck('text')->all());

        // A pending text that reached the library some other way leaves the queue (also before the next prune).
        DB::table('pending_queries')->insert(['text' => 'zirkonyum fiyatları', 'text_hash' => QueryNormalizer::hash('zirkonyum fiyatları'), 'status' => PendingQuery::PENDING, 'impressions' => 1, 'clicks' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $page = Livewire::test(QueriesPage::class)->call('setTab', 'pending')
            ->assertSee('panorama.com.tr')->assertDontSee('silinecek')->assertDontSee('zirkonyum fiyatları')->assertSeeHtml('data-pending-count>1<');
        app(QueryPipeline::class)->run();
        $this->assertFalse(PendingQuery::query()->where('text', 'zirkonyum fiyatları')->exists(), 'pruned by the pipeline');

        $page->call('importPending')->assertSee('1 sorgu içe aktarıldı');
        $imported = Query::query()->where('text', 'implant fiyatı yeni')->sole();
        $this->assertSame($this->implant->id, $imported->service_id);
        $this->assertSame(50, $imported->impressions, 'sources linked by the pipeline');
        $this->assertSame(2, DB::table('query_sources')->where('query_id', $imported->id)->count());
        $this->assertSame(0, PendingQuery::query()->where('status', PendingQuery::PENDING)->count());

        // Dismissed texts never come back.
        FilterTerm::query()->where('term', 'kaplama')->delete();
        app(QueryPipeline::class)->run();
        $kaplama = PendingQuery::query()->where('text', 'implant kaplama')->sole();
        Livewire::test(QueriesPage::class)->call('setTab', 'pending')->call('dismissPending')->assertSee('1 sorgu yoksayıldı');
        app(QueryPipeline::class)->run();
        $this->assertSame(0, PendingQuery::query()->where('status', PendingQuery::PENDING)->count(), 'a dismissed query does not come back');
        $this->assertSame(PendingQuery::DISMISSED, $kaplama->fresh()->status);
    }

    public function test_filtreye_ekle_rescans_notifies_and_the_silinecekler_tab_applies_only_selected_lines(): void
    {
        foreach (['implant forum' => 30, 'bedava implant' => 20, 'implant forum yorumları' => 10] as $raw => $impressions) {
            $this->source($raw, '2026-08-01', $impressions);
        }
        app(QueryPipeline::class)->run(import: true);
        $id = fn (string $text): int => (int) Query::query()->where('text', $text)->value('id');
        $cluster = Cluster::query()->create(['sector_id' => $this->dental->id, 'service_id' => $this->implant->id, 'name' => 'Forum', 'main_query_id' => $id('implant forum')]);
        ClusterQuery::query()->create(['cluster_id' => $cluster->id, 'query_id' => $id('implant forum')]);

        $page = Livewire::test(QueriesPage::class)->set('selected', [$id('implant forum'), $id('bedava implant')])->call('openNegatives')
            ->assertSet('negOpen', true)->assertSet('negSector', (string) $this->dental->id)
            ->assertSee('Filtreye ekle');
        $this->assertSame(['implant forum', 'bedava implant'], explode("\n", $page->get('negText')));

        $page->set('negText', "Forum\nbedava\n")
            ->assertSeeHtml('başka 1 sorgu: implant forum yorumları')
            ->call('saveNegatives')->assertSee('2 terim filtreye eklendi');

        $this->assertSame(5, Query::query()->count(), 'nothing deleted before approval');
        $review = QueryReview::query()->sole();
        $this->assertSame([QueryReview::READY, 3, 0], [$review->status, $review->deletions, $review->changes]);
        $notice = UserNotification::query()->where('recipient_user_id', $this->admin->id)->where('notification_kind', NotificationKind::QueriesNotice->value)->sole();
        $url = QueriesPage::deletionsUrl();
        $this->assertSame('/library/queries?tab=deletions', $url);
        $this->assertSame('Filtre taraması hazır: 3 silinecek, 0 hizmet değişikliği', $notice->presentation['title']);
        $this->assertSame($url, $notice->presentation['url']);

        // Bell: unread badge; opening the notification marks it read and goes to the Silinecekler tab.
        Livewire::test(NotificationBell::class)->assertSeeHtml('data-unread-count>1<')->assertSee('Filtre taraması hazır')
            ->call('open', (string) $notice->id)->assertRedirect($url);
        $this->assertNotNull($notice->fresh()->read_at);
        // Old per-rescan links (also of reviews that no longer exist) open the tab.
        $this->get('/library/queries/review/'.$review->id)->assertRedirect($url);
        $this->get('/library/queries/review/999999')->assertRedirect($url);

        $this->get($url)->assertOk()->assertSee('Silinecek sorgular · 3')->assertSee('bedava implant')->assertSeeHtml('data-deletions-count>3<');
        $keep = QueryReviewItem::query()->where('query_id', $id('implant forum yorumları'))->sole();
        $selected = QueryReviewItem::query()->whereKeyNot($keep->id)->pluck('id')->map(fn ($v): int => (int) $v)->all();
        Livewire::test(QueriesPage::class)->call('setTab', 'deletions')->assertSee('Onayla ve sil')
            ->set('selected', $selected)->call('approveReview')->assertSee('2 sorgu silindi');

        $this->assertSame(['bedava implant', 'implant forum'], PendingQuery::query()->where('status', PendingQuery::DELETED)->orderBy('text')->pluck('text')->all());
        $this->assertSame(['implant fiyatları', 'implant forum yorumları', 'zirkonyum fiyatları'], Query::query()->orderBy('text')->pluck('text')->all(), 'only selected lines applied');
        $this->assertSame([$keep->id], QueryReviewItem::query()->pluck('id')->map(fn ($v): int => (int) $v)->all(), 'the unselected line stays in the tab');
        $this->assertSame(0, $cluster->clusterQueries()->count(), 'deleted query leaves its cluster');
        $this->assertNull($cluster->fresh()->main_query_id);
        $this->assertSame(0, DB::table('query_sources')->where('raw_query', 'implant forum')->whereNotNull('query_id')->count(), 'sources stay, unlinked');
        $this->assertSame(1, DB::table('query_sources')->where('raw_query', 'implant forum')->count());

        // A deleted query never comes back through Bekleyenler.
        $this->source('implant forum', '2026-09-01', 5);
        app(QueryPipeline::class)->run();
        $this->assertSame(0, PendingQuery::query()->where('status', PendingQuery::PENDING)->count());
    }

    public function test_several_rescans_accumulate_in_one_deduplicated_tab_with_term_filter_bulk_select_and_one_live_notice(): void
    {
        foreach (['implant forum' => 30, 'implant forum yorumları' => 25, 'bedava implant' => 20, 'bedava implant forum' => 15, 'ücretsiz implant' => 5] as $raw => $impressions) {
            $this->source($raw, '2026-08-01', $impressions);
        }
        app(QueryPipeline::class)->run(import: true);
        $id = fn (string $text): int => (int) Query::query()->where('text', $text)->value('id');

        // Three filter words added one after another → three rescans → still ONE list, one line per query.
        $page = Livewire::test(QueriesPage::class)->call('setTab', 'filters');
        foreach (['forum', 'bedava', 'Ücretsiz'] as $term) {
            $page->set('termText', $term)->call('addTerm')->assertSee('tarama başladı');
        }
        $items = QueryReviewItem::query()->orderBy('query_id')->get();
        $this->assertSame(5, $items->count());
        $this->assertSame($items->count(), $items->pluck('query_id')->unique()->count(), 'one line per query');
        $this->assertSame('bedava', $items->firstWhere('query_id', $id('bedava implant forum'))->term, 'the latest proposal wins');
        $this->assertSame('ücretsiz', $items->firstWhere('query_id', $id('ücretsiz implant'))->term);
        $this->assertSame(1, QueryReview::query()->count(), 'emptied older scans are dropped');

        $notices = UserNotification::query()->where('recipient_user_id', $this->admin->id)->where('notification_kind', NotificationKind::QueriesNotice->value)->orderBy('id')->get();
        $this->assertCount(3, $notices);
        $this->assertSame(['Filtre taraması hazır: 3 silinecek, 0 hizmet değişikliği', 'Filtre taraması hazır: 4 silinecek, 0 hizmet değişikliği', 'Filtre taraması hazır: 5 silinecek, 0 hizmet değişikliği'],
            $notices->map(fn (UserNotification $n): string => $n->presentation['title'])->all(), 'the count is the whole open list');
        $this->assertSame([true, true, false], $notices->map(fn (UserNotification $n): bool => $n->read_at !== null)->all(), 'older notices are closed: one live link');

        // Term filter + "filtreye uyan tümü" minus one unticked line.
        $tab = Livewire::test(QueriesPage::class)->call('setTab', 'deletions')
            ->assertSeeHtml('data-deletions-count>5<')->assertSee('forum · 2')->assertSee('bedava · 2')
            ->set('reviewTerm', 'bedava')->assertSee('bedava implant forum')->assertDontSee('implant forum yorumları')
            ->call('selectAllMatching')->assertSee('Filtreye uyan 2 satır seçili')
            ->call('toggleExcluded', (int) QueryReviewItem::query()->where('query_id', $id('bedava implant'))->value('id'))
            ->call('approveReview')->assertSee('1 sorgu silindi');
        $this->assertFalse(Query::query()->whereKey($id('bedava implant forum'))->exists());
        $this->assertTrue(Query::query()->where('text', 'bedava implant')->exists());

        // Page selection + "Tut": kept lines leave the list and the same proposal does not come back on the next scan.
        $tab->set('reviewTerm', 'forum')->call('selectPage')->call('keepReview')->assertSee('2 sorgu tutuldu');
        app(QueryRescanner::class)->scan($this->admin->id);
        $this->assertSame(['delete' => 2, 'service' => 0], QueryRescanner::openCounts());
        $this->assertSame(2, QueryReviewItem::query()->whereNotNull('kept_at')->count());
        $tab->set('reviewTerm', '')->set('reviewKept', true)->assertSee('implant forum yorumları')->assertSee('Geri al')
            ->call('selectPage')->call('keepReview')->assertSee('2 satır geri alındı');
        $this->assertSame(['delete' => 4, 'service' => 0], QueryRescanner::openCounts());

        // A line whose filter term was removed is not applied (and leaves on the next scan).
        FilterTerm::query()->where('term', 'ücretsiz')->delete();
        $line = QueryReviewItem::query()->where('query_id', $id('ücretsiz implant'))->sole();
        $this->assertSame(['deleted' => 0, 'changed' => 0], app(QueryRescanner::class)->apply([(int) $line->id]));
        $this->assertTrue(Query::query()->where('text', 'ücretsiz implant')->exists());
    }

    public function test_keyword_change_goes_through_the_tab_and_kept_changes_stay(): void
    {
        $zirkonyum = Query::query()->where('text', 'zirkonyum fiyatları')->sole();
        $this->assertNull($zirkonyum->service_id);

        Livewire::test(QueriesPage::class)->call('setTab', 'keywords')->set('sector', (string) $this->dental->id)
            ->set('newKeyword.'.$this->zirkonyum->id, 'zirkonyum')->call('addKeyword', $this->zirkonyum->id)->assertSee('tarama başladı');

        $this->assertNull($zirkonyum->fresh()->service_id, 'no silent reassignment');
        $item = QueryReviewItem::query()->where('kind', QueryReviewItem::SERVICE)->sole();
        $this->assertSame([$zirkonyum->id, null, $this->zirkonyum->id], [$item->query_id, $item->from_service_id, $item->to_service_id]);

        $tab = Livewire::test(QueriesPage::class)->call('setTab', 'deletions')->assertSee('Hizmet değişikliği · 1')
            ->set('reviewKind', QueryReviewItem::SERVICE)->assertSee('yeni atama')->assertSee('eşleşen kelime: zirkonyum')->assertSee('Onayla ve uygula')
            ->call('approveReview')->assertSee('Önce satır seçin')
            ->set('selected', [$item->id])->call('keepReview')->assertSee('1 sorgu tutuldu');
        $this->assertNull($zirkonyum->fresh()->service_id);
        app(QueryRescanner::class)->scan($this->admin->id);
        $this->assertSame(['delete' => 0, 'service' => 0], QueryRescanner::openCounts(), 'a kept change is not offered again');

        $tab->set('reviewKept', true)->call('selectAllMatching')->call('keepReview')->assertSee('1 satır geri alındı')
            ->set('reviewKept', false)->call('selectAllMatching')->call('approveReview')->assertSee('1 sorgunun hizmeti değişti');
        $this->assertSame($this->zirkonyum->id, $zirkonyum->fresh()->service_id);
        $this->assertSame('rule', $zirkonyum->fresh()->assignment);
        $this->assertSame(0, QueryReviewItem::query()->count());
    }

    public function test_rescan_never_changes_a_service_set_by_the_operator_or_ai(): void
    {
        $manual = Query::query()->where('text', 'zirkonyum fiyatları')->sole();
        $manual->forceFill(['service_id' => $this->implant->id, 'assignment' => 'manual', 'locked' => false])->save();
        $ai = Query::query()->where('text', 'implant fiyatları')->sole();
        $ai->forceFill(['service_id' => $this->zirkonyum->id, 'assignment' => 'ai', 'locked' => false])->save();

        app(QueryRescanner::class)->scan($this->admin->id);

        $this->assertFalse(QueryReviewItem::query()->whereIn('query_id', [$manual->id, $ai->id])->exists(), 'no "atama kalkıyor" for chosen services');
    }

    public function test_important_notification_is_toasted_once_with_its_target(): void
    {
        FilterTerm::query()->create(['sector_id' => null, 'term' => 'zirkonyum']);
        RescanQueriesJob::dispatch($this->admin->id);

        $toast = NotificationToast::next($this->admin->id);
        $this->assertSame([
            'title' => 'Filtre taraması hazır: 1 silinecek, 0 hizmet değişikliği',
            'url' => QueriesPage::deletionsUrl(),
        ], array_intersect_key($toast, ['title' => true, 'url' => true]));
        $this->assertNull(NotificationToast::next($this->admin->id), 'shown once');
        $this->assertNull(NotificationToast::next(User::factory()->create()->id), 'only its recipient');

        Livewire::test(NotificationToast::class)->assertDontSee('Filtre taraması hazır');
        $this->get(route('operator.library.queries'))->assertOk()->assertSeeHtml('data-notification-toast');
    }

    public function test_bekleyenler_is_already_clean_for_existing_rows_with_mixed_case_turkish_characters_and_spacing(): void
    {
        Queue::fake();
        Query::query()->create(['text' => 'diş implantı fiyatları', 'text_hash' => QueryNormalizer::hash('diş implantı fiyatları'), 'sector_id' => $this->dental->id]);
        FilterTerm::query()->create(['sector_id' => $this->dental->id, 'term' => 'ücretsiz']);
        FilterTerm::query()->create(['sector_id' => null, 'term' => 'ankara']);
        // Rows as production holds them: written before the current terms / by an older normalization (raw hashes).
        $rows = [
            'Diş  İmplantı Fiyatları' => 90,   // in the library (case, Turkish İ, double space)
            'DİŞ İMPLANTI FİYATLARI!' => 80,   // in the library (upper case, punctuation)
            'ÜCRETSİZ implant muayenesi' => 70, // filter term, upper-case Turkish Ü / İ
            "Ankara'da İmplant" => 60,          // filter term with a Turkish suffix
            'implant   kaplama  fiyatı' => 50,  // clean
            'Zirkonyum Kaplama' => 40,          // clean
        ];
        foreach ($rows as $text => $impressions) {
            DB::table('pending_queries')->insert(['text' => $text, 'text_hash' => hash('sha256', $text), 'status' => PendingQuery::PENDING, 'sector_id' => $this->dental->id,
                'impressions' => $impressions, 'clicks' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        // Opening Sorgular cleans the queue (no pipeline run, no job).
        $page = Livewire::test(QueriesPage::class)->assertSeeHtml('data-pending-count>2<')->call('setTab', 'pending')
            ->assertSee('implant   kaplama  fiyatı')->assertSee('Zirkonyum Kaplama')
            ->assertDontSee('Diş  İmplantı Fiyatları')->assertDontSee('ÜCRETSİZ implant muayenesi')->assertDontSee("Ankara'da İmplant", false);
        $this->assertSame(['Zirkonyum Kaplama', 'implant   kaplama  fiyatı'], PendingQuery::query()->where('status', PendingQuery::PENDING)->orderBy('text')->pluck('text')->all());

        // A new filter term takes effect at once, before the queued rescan.
        $page->call('setTab', 'filters')->set('termText', 'KAPLAMA')->call('addTerm')->call('setTab', 'pending')
            ->assertSee('Bekleyen sorgu yok')->assertDontSeeHtml('data-pending-count>');
        Queue::assertPushed(RescanQueriesJob::class);
        $this->assertSame(0, PendingQuery::query()->where('status', PendingQuery::PENDING)->count());

        // The visible page is checked on every render (a row written between two prunes is never listed).
        FilterTerm::query()->where('term', 'kaplama')->delete();
        foreach (['Ücretsiz Diş Muayenesi' => 30, 'İmplant  Çeşitleri' => 20] as $text => $impressions) {
            DB::table('pending_queries')->insert(['text' => $text, 'text_hash' => hash('sha256', $text), 'status' => PendingQuery::PENDING, 'sector_id' => $this->dental->id,
                'impressions' => $impressions, 'clicks' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        $page->call('setTab', 'queries')->call('setTab', 'pending')->assertSee('İmplant  Çeşitleri')->assertDontSee('Ücretsiz Diş Muayenesi');

        // Import stores the library's normalized text; the hourly command keeps the queue clean too.
        $page->call('importPending')->assertSee('1 sorgu içe aktarıldı');
        $this->assertTrue(Query::query()->where('text_hash', QueryNormalizer::hash('implant çeşitleri'))->where('text', 'implant çeşitleri')->exists());
        DB::table('pending_queries')->insert(['text' => 'implant çeşitleri', 'text_hash' => hash('sha256', 'x'), 'status' => PendingQuery::PENDING,
            'impressions' => 1, 'clicks' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('moxdop:queries:prune-pending')->expectsOutputToContain('1 bekleyen sorgu temizlendi')->assertSuccessful();
    }

    private function source(string $raw, string $month, int $impressions, ?CoreExternalResource $resource = null): void
    {
        $resource ??= $this->gsc;
        DB::table('query_sources')->insert([
            'external_resource_id' => $resource->id, 'source' => $resource->resource_type === 'google_ads' ? 'google_ads' : 'gsc', 'raw_query' => $raw,
            'month' => $month, 'impressions' => $impressions, 'clicks' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertNotSame('', app(QueryNormalizer::class)->normalize($raw));
    }
}
