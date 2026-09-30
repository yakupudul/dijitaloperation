<?php

namespace Tests\Feature\Queries;

use App\Enums\NotificationKind;
use App\Jobs\Queries\RescanQueriesJob;
use App\Livewire\Demo\NotificationBell;
use App\Livewire\Operator\Library\QueriesPage;
use App\Livewire\Operator\Library\QueryReviewPage;
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
use Livewire\Livewire;
use Tests\TestCase;

/** Bekleyenler, "Filtreye ekle" → onaylı tarama → bildirim → onay ekranı, eşleme kelimesi değişikliği → onay. */
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

    public function test_filtreye_ekle_rescans_notifies_and_the_review_applies_only_checked_lines(): void
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
        $url = route('operator.library.queries.review', ['review' => $review->id], false);
        $this->assertSame('Filtre taraması hazır: 3 silinecek, 0 hizmet değişikliği', $notice->presentation['title']);
        $this->assertSame($url, $notice->presentation['url']);

        // Bell: unread badge; opening the notification marks it read and goes to the review.
        Livewire::test(NotificationBell::class)->assertSeeHtml('data-unread-count>1<')->assertSee('Filtre taraması hazır')
            ->call('open', (string) $notice->id)->assertRedirect($url);
        $this->assertNotNull($notice->fresh()->read_at);

        $this->get($url)->assertOk()->assertSee('Silinecek sorgular · 3')->assertSee('bedava implant');
        $keep = $review->items()->where('query_id', $id('implant forum yorumları'))->sole();
        Livewire::test(QueryReviewPage::class, ['review' => $review])
            ->call('toggle', $keep->id)->call('approve')->assertSee('2 sorgu silindi');

        $this->assertSame(['bedava implant', 'implant forum'], PendingQuery::query()->where('status', PendingQuery::DELETED)->orderBy('text')->pluck('text')->all());
        $this->assertSame(['implant fiyatları', 'implant forum yorumları', 'zirkonyum fiyatları'], Query::query()->orderBy('text')->pluck('text')->all(), 'only checked lines applied');
        $this->assertSame(0, $cluster->clusterQueries()->count(), 'deleted query leaves its cluster');
        $this->assertNull($cluster->fresh()->main_query_id);
        $this->assertSame(0, DB::table('query_sources')->where('raw_query', 'implant forum')->whereNotNull('query_id')->count(), 'sources stay, unlinked');
        $this->assertSame(1, DB::table('query_sources')->where('raw_query', 'implant forum')->count());
        $this->assertSame(QueryReview::APPLIED, $review->fresh()->status);

        // A deleted query never comes back through Bekleyenler.
        $this->source('implant forum', '2026-09-01', 5);
        app(QueryPipeline::class)->run();
        $this->assertSame(0, PendingQuery::query()->where('status', PendingQuery::PENDING)->count());
    }

    public function test_keyword_change_goes_through_the_review_and_unchecked_changes_stay(): void
    {
        $zirkonyum = Query::query()->where('text', 'zirkonyum fiyatları')->sole();
        $this->assertNull($zirkonyum->service_id);

        Livewire::test(QueriesPage::class)->call('setTab', 'keywords')->set('sector', (string) $this->dental->id)
            ->set('newKeyword.'.$this->zirkonyum->id, 'zirkonyum')->call('addKeyword', $this->zirkonyum->id)->assertSee('tarama başladı');

        $this->assertNull($zirkonyum->fresh()->service_id, 'no silent reassignment');
        $review = QueryReview::query()->sole();
        $item = $review->items()->where('kind', QueryReviewItem::SERVICE)->sole();
        $this->assertSame([$zirkonyum->id, null, $this->zirkonyum->id], [$item->query_id, $item->from_service_id, $item->to_service_id]);

        Livewire::test(QueryReviewPage::class, ['review' => $review])->assertSee('yeni atama')
            ->call('setAll', 'service', false)->call('approve')->assertSee('0 sorgu silindi · 0 sorgunun hizmeti değişti');
        $this->assertNull($zirkonyum->fresh()->service_id);

        $review = app(QueryRescanner::class)->scan($this->admin->id);
        Livewire::test(QueryReviewPage::class, ['review' => $review])->call('approve')->assertSee('1 sorgunun hizmeti değişti');
        $this->assertSame($this->zirkonyum->id, $zirkonyum->fresh()->service_id);
        $this->assertSame('rule', $zirkonyum->fresh()->assignment);
    }

    public function test_important_notification_is_toasted_once_with_its_target(): void
    {
        FilterTerm::query()->create(['sector_id' => null, 'term' => 'zirkonyum']);
        RescanQueriesJob::dispatch($this->admin->id);
        $review = QueryReview::query()->sole();

        $toast = NotificationToast::next($this->admin->id);
        $this->assertSame([
            'title' => 'Filtre taraması hazır: 1 silinecek, 0 hizmet değişikliği',
            'url' => route('operator.library.queries.review', ['review' => $review->id], false),
        ], array_intersect_key($toast, ['title' => true, 'url' => true]));
        $this->assertNull(NotificationToast::next($this->admin->id), 'shown once');
        $this->assertNull(NotificationToast::next(User::factory()->create()->id), 'only its recipient');

        Livewire::test(NotificationToast::class)->assertDontSee('Filtre taraması hazır');
        $this->get(route('operator.library.queries'))->assertOk()->assertSeeHtml('data-notification-toast');
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
