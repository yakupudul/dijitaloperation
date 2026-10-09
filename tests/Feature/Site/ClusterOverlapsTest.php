<?php

namespace Tests\Feature\Site;

use App\Livewire\Operator\Repair\RepairDeskPage;
use App\Livewire\Operator\Work\WorkPage;
use App\Models\BrandClusterPage;
use App\Models\Cluster;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\ExternalWriteAction;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\Repair\RepairDesk;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\ClusterOverlaps;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use MoxDop\Website\Discovery\PublicUrlSafety;

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

    /** @var list<list<array<string, mixed>>> */
    private array $sent = [];

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
        $tr = $this->row('tr', $this->main, [$this->copy->id, $this->enCopy->id]);
        $this->row(null, $this->main, [$this->copy->id, $this->enCopy->id]);
        $this->row('en', $this->enMain, [$this->copy->id, $this->enCopy->id]);

        $this->assertSame(2, app(ClusterOverlaps::class)->sync($this->site, $this->brand));

        $open = Suggestion::query()->where('decision_key', ClusterOverlaps::DECISION)->actionable()->get();
        $this->assertSame([$this->copy->id => $this->main->id, $this->enCopy->id => $this->enMain->id],
            $open->mapWithKeys(fn (Suggestion $s): array => [(int) $s->page_id => (int) data_get($s->action, 'main_page_id')])->sortKeys()->all(),
            'TR row ↔ EN page, EN row ↔ TR page and a language-less row with a TR main page ↔ EN page are translations; the TR and language-less rows find the same pair once');
        $this->assertSame($tr->id, (int) data_get($open->firstWhere('page_id', $this->copy->id)->action, 'row_id'), 'the pair stays under its language row');
    }

    public function test_an_old_per_language_duplicate_keeps_its_decision_and_the_extra_copy_closes(): void
    {
        $tr = $this->row('tr', $this->main, [$this->copy->id]);
        $plain = $this->row(null, $this->main, [$this->copy->id]);
        $kept = $this->legacy($tr, Suggestion::DISMISSED);
        $extra = $this->legacy($plain, Suggestion::OPEN);
        $wrongLanguage = $this->legacy($this->row('en', $this->enMain, [$this->copy->id]), Suggestion::SNOOZED, $this->enMain);

        $this->artisan('moxdop:clusters:sync-overlaps')->expectsOutputToContain('0 açık küme çakışması')->assertSuccessful();

        $this->assertSame(Suggestion::DISMISSED, $kept->fresh()->status, '"Ayrı kalsın" on either language row is not asked again');
        $this->assertSame(hash('sha256', implode('|', [$this->brand->id, 'cluster-overlap', $this->treatment->id, $this->main->id, $this->copy->id])), $kept->fresh()->fingerprint);
        $this->assertSame([Suggestion::APPLIED, 'Çakışma kalktı.'], [$extra->fresh()->status, $extra->fresh()->operator_note]);
        $this->assertSame(Suggestion::APPLIED, $wrongLanguage->fresh()->status, 'the cross-language 301 proposal is gone, snoozed or not');
        $this->assertSame([], Suggestion::query()->where('decision_key', ClusterOverlaps::DECISION)->actionable()->pluck('id')->all());
    }

    public function test_an_overlap_the_system_closed_comes_back_when_it_is_seen_again(): void
    {
        $row = $this->row('tr', $this->main, [$this->copy->id]);
        $other = $this->page('/implant-fiyatlari/', 'İmplant fiyatları', ['category' => 'hizmet']);
        $overlaps = app(ClusterOverlaps::class);
        $overlaps->sync($this->site, $this->brand);
        $first = Suggestion::query()->where('decision_key', ClusterOverlaps::DECISION)->sole();

        $row->forceFill(['page_id' => $other->id])->save();
        $overlaps->sync($this->site, $this->brand);
        $this->assertSame([Suggestion::APPLIED, ClusterOverlaps::GONE], [$first->fresh()->status, $first->fresh()->operator_note]);

        $row->forceFill(['page_id' => $this->main->id])->save();
        $this->assertSame(1, $overlaps->sync($this->site, $this->brand));
        $this->assertSame([Suggestion::OPEN, null], [$first->fresh()->status, $first->fresh()->operator_note], 'the main page went back: the same item is open again');
        $this->assertSame([$first->id], Suggestion::query()->where('decision_key', ClusterOverlaps::DECISION)->actionable()->pluck('id')->all());
    }

    public function test_genel_isler_shows_a_sites_overlaps_in_one_card_under_their_cluster(): void
    {
        $second = $this->page('/tek-seansta-implant/', 'Tek seansta implant', ['category' => 'blog']);
        $this->row('tr', $this->main, [$this->copy->id, $second->id]);
        app(ClusterOverlaps::class)->sync($this->site, $this->brand);

        // 2026-10-09: a ready 301 waits on the Onarım masası (one place for every yes / no); Genel işler points there.
        Livewire::withQueryParams(['sekme' => 'cakisma'])->test(WorkPage::class)->assertSeeHtml('data-on-desk')->assertSee('2 düzeltme Onarım masasında')
            ->assertDontSee('/tek-seansta-implant/');
        Livewire::test(RepairDeskPage::class)->set('lane', RepairDesk::LANE_REVIEW)->assertSee('2 · 301 birleştirme')->assertSee('/tek-seansta-implant/');
    }

    public function test_a_service_page_is_never_301d_into_a_blog_page_and_a_page_google_prefers_is_not_301d(): void
    {
        $blogMain = $this->page('/sinus-lifting-nedir/', 'Sinüs lifting nedir', ['category' => 'blog']);
        $service = $this->page('/tedavilerimiz/ankara-sinus-lifting/', 'Ankara sinüs lifting', ['category' => 'hizmet']);
        $popular = $this->page('/soru-ve-cevap/sinus-lifting-agrili-mi/', 'Sinüs lifting ağrılı mı', ['category' => 'sss']);
        $quiet = $this->page('/sinus-lifting-sonrasi/', 'Sinüs lifting sonrası', ['category' => 'blog']);
        $this->row('tr', $blogMain, [$service->id, $popular->id, $quiet->id]);

        app(ClusterOverlaps::class)->sync($this->site, $this->brand, [$this->treatment->id => [$this->share($popular, 0.99), $this->share($blogMain, 0.01)]]);

        $this->assertSame([$service->id => ClusterOverlaps::REVIEW, $popular->id => ClusterOverlaps::REVIEW, $quiet->id => ClusterOverlaps::REDIRECT], $this->recommendations());
        $this->assertSame('service', data_get(Suggestion::query()->where('page_id', $service->id)->sole()->action, 'basis'));
        $this->assertStringContainsString('%99', ClusterOverlaps::why((array) Suggestion::query()->where('page_id', $popular->id)->sole()->action));
    }

    public function test_home_pages_other_languages_and_pages_of_other_services_are_never_overlaps(): void
    {
        $home = $this->page('/', 'Panorama', ['category' => 'diger']);
        $enHome = $this->page('/en/', 'Panorama EN', ['category' => 'diger', 'language' => null]);
        $enNoLanguage = $this->page('/en/treatments/implant/', 'Implant', ['category' => 'hizmet', 'language' => null]);
        $zirkonyumPage = $this->page('/zirkonyum-kaplama/', 'Zirkonyum', ['category' => 'blog']);
        OfferingPage::query()->create(['brand_offering_id' => $this->zirkonyumOffering->id, 'page_id' => $zirkonyumPage->id]);
        $this->row('tr', $this->main, [$home->id, $enHome->id, $enNoLanguage->id, $zirkonyumPage->id, $this->copy->id]);

        app(ClusterOverlaps::class)->sync($this->site, $this->brand, [$this->treatment->id => [$this->share($home, 0.3), $this->share($this->main, 0.7)]]);

        $this->assertSame([$this->copy->id => ClusterOverlaps::REDIRECT], $this->recommendations());
    }

    public function test_one_page_is_proposed_for_a_301_to_one_main_page_only(): void
    {
        $second = $this->cluster($this->implant, 'İmplant fiyatları', ['implant fiyatları']);
        $blogMain = $this->page('/implant-fiyatlari-rehberi/', 'İmplant fiyatları rehberi', ['category' => 'blog']);
        $this->row('tr', $this->main, [$this->copy->id]);
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $second->id, 'website_asset_id' => $this->site->id,
            'language' => 'tr', 'state' => 'possible_conflict', 'page_id' => $blogMain->id, 'overlap_page_ids' => [$this->copy->id]]);

        app(ClusterOverlaps::class)->sync($this->site, $this->brand);

        $open = Suggestion::query()->where('decision_key', ClusterOverlaps::DECISION)->actionable()->get();
        $this->assertCount(1, $open);
        $this->assertSame($this->main->id, (int) data_get($open->sole()->action, 'main_page_id'), 'the service page wins over the blog page');
    }

    public function test_301_merge_is_applied_only_when_the_site_confirms_and_reopens_with_the_sites_error(): void
    {
        $other = $this->page('/tek-seansta-implant/', 'Tek seansta implant', ['category' => 'blog', 'wp_post_id' => 77]);
        $this->copy->forceFill(['wp_post_id' => 55])->save();
        $this->row('tr', $this->main, [$this->copy->id, $other->id]);
        app(ClusterOverlaps::class)->sync($this->site, $this->brand);
        $this->connector('1.12.0', fn (array $change): array => $change['object_id'] === 55
            ? ['ok' => true, 'change_id' => 'c-1', 'provider' => 'rank_math', 'drafted' => true]
            : ['ok' => false, 'error' => 'SEO fixes are disabled on this site.']);
        $ids = Suggestion::query()->where('decision_key', ClusterOverlaps::DECISION)->orderBy('page_id')->pluck('id', 'page_id');

        Livewire::test(RepairDeskPage::class)->set('lane', RepairDesk::LANE_REVIEW)
            ->set('selected', [$ids[$this->copy->id], $ids[$other->id]])->call('approveSelected')->assertSet('selected', []);

        $this->assertCount(1, $this->sent, 'one write for the site');
        $this->assertSame(['merge_redirect', 55, '/implant-tedavisi-nedir/', 'https://panorama.com.tr/implant/'],
            [$this->sent[0][0]['type'], $this->sent[0][0]['object_id'], $this->sent[0][0]['from'], $this->sent[0][0]['value']]);
        $merged = Suggestion::query()->find($ids[$this->copy->id]);
        $this->assertSame([Suggestion::APPLIED, 'rank_math'], [$merged->status, data_get($merged->action, 'merge_provider')]);
        $failed = Suggestion::query()->find($ids[$other->id]);
        $this->assertSame([Suggestion::OPEN, 'SEO fixes are disabled on this site.'], [$failed->status, data_get($failed->action, 'merge_error')]);
        $this->assertSame(['partial', 'SEO fixes are disabled on this site.'], [ExternalWriteAction::query()->sole()->status, ExternalWriteAction::query()->sole()->error]);
        $this->assertStringContainsString('SEO fixes are disabled on this site.', (string) app(RepairDesk::class)->rows()->firstWhere('id', $ids[$other->id])['reason']);
    }

    public function test_an_old_connector_is_refused_before_anything_changes(): void
    {
        $this->row('tr', $this->main, [$this->copy->id]);
        app(ClusterOverlaps::class)->sync($this->site, $this->brand);
        $this->connector('1.8.0', fn (): array => ['ok' => true]);
        $suggestion = Suggestion::query()->where('decision_key', ClusterOverlaps::DECISION)->sole();

        try {
            app(ClusterOverlaps::class)->redirect($suggestion, $this->admin);
            $this->fail('1.8.0 cannot write to the SEO plugin');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('en az 1.12.0', (string) collect($exception->errors())->flatten()->first());
        }
        $this->assertSame([], $this->sent);
        $this->assertSame(Suggestion::OPEN, $suggestion->fresh()->status);
    }

    public function test_ana_sayfa_bu_olsun_makes_the_page_the_clusters_main_page(): void
    {
        $row = $this->row('tr', $this->copy, [$this->main->id]);
        app(ClusterOverlaps::class)->sync($this->site, $this->brand);
        $suggestion = Suggestion::query()->where('decision_key', ClusterOverlaps::DECISION)->sole();
        $this->assertSame(ClusterOverlaps::REVIEW, data_get($suggestion->action, 'recommendation'), 'service page ↔ blog main page');

        Livewire::withQueryParams(['sekme' => 'cakisma'])->test(WorkPage::class)->call('run', $suggestion->id, 'make_main');

        $this->assertSame([$this->main->id, true], [(int) $row->fresh()->page_id, (bool) $row->fresh()->locked]);
        $this->assertSame(Suggestion::APPLIED, $suggestion->fresh()->status);
    }

    /** @return array<int, string> page id → recommendation of the open overlaps */
    private function recommendations(): array
    {
        return Suggestion::query()->where('decision_key', ClusterOverlaps::DECISION)->actionable()->get()
            ->mapWithKeys(fn (Suggestion $s): array => [(int) $s->page_id => (string) data_get($s->action, 'recommendation')])->sortKeys()->all();
    }

    /** @return array{url: string, url_key: string, impressions: int, share: float} */
    private function share(Page $page, float $share): array
    {
        return ['url' => (string) $page->url, 'url_key' => SeoText::urlKey((string) $page->url), 'impressions' => (int) round($share * 1000), 'share' => $share];
    }

    /**
     * A paired connector whose /fixes answers each change with $answer (signed like the plugin).
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $answer
     */
    private function connector(string $version, callable $answer): void
    {
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://panorama.com.tr/wp-json/moxdop/v1/snapshot', 'plugin_version' => $version],
        ]);
        $secret = str_repeat('s', 43);
        CoreConnectionCredential::factory()->create(['connection_id' => $connection->id, 'encrypted_payload' => ['client_id' => 'client-1', 'shared_secret' => $secret]]);
        $this->app->instance(WordPressConnectorClient::class, new WordPressConnectorClient(new WordPressConnectorCanonicalJson, new PublicUrlSafety(fn (string $host): array => ['93.184.216.34'])));
        $this->sent = [];
        Http::fake(function (Request $request) use ($answer, $secret) {
            $changes = (array) (json_decode($request->body(), true)['changes'] ?? []);
            $this->sent[] = $changes;
            $data = ['schema_version' => 1, 'results' => array_map($answer, $changes)];
            $nonce = $request->header(WordPressConnectorClient::HEADER_NONCE)[0] ?? '';
            $time = now()->timestamp;
            $signature = hash_hmac('sha256', implode("\n", [(string) $time, $nonce, hash('sha256', (new WordPressConnectorCanonicalJson)->encode($data))]), $secret);

            return Http::response(['data' => $data, 'meta' => ['server_time' => $time, 'request_nonce' => $nonce, 'signature' => $signature]]);
        });
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
