<?php

namespace Tests\Feature\Repair;

use App\Ai\Agents\Site\SeoFieldsBatchAgent;
use App\Jobs\ApproveRepairRowsJob;
use App\Jobs\ExecuteExternalWriteJob;
use App\Jobs\Site\RunSiteOperationJob;
use App\Livewire\Operator\Repair\RepairDeskPage;
use App\Livewire\Operator\Work\WorkPage;
use App\Models\Brand;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Repair\RepairDesk;
use App\Services\Repair\RepairPreparer;
use App\Services\Repair\RepairVerifier;
use App\Services\Repair\SeoFieldsBatch;
use App\Services\Repair\SiteAudit;
use App\Services\Site\SiteOperations;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Site\SiteTestCase;

/**
 * Onarım masası (Onarım Faz 2): prepared fixes of every brand with eski → yeni and risk; single / bulk approval through
 * the existing undoable writes (high risk one by one), edit before approval, reject, nightly preparation, morning digest.
 */
final class RepairDeskTest extends SiteTestCase
{
    private Page $implantPage;

    protected function setUp(): void
    {
        parent::setUp();
        // The desk approval itself runs (sync); the writes it requests stay queued.
        Queue::fake()->except([ApproveRepairRowsJob::class]);
        $this->implantPage = $this->page('/implant/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet', 'wp_post_id' => 42]);
        CoreConnection::factory()->create([
            'digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://panorama.com.tr/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.5.0'],
        ]);
    }

    public function test_desk_lists_prepared_fixes_and_bulk_approval_writes_all_but_high_risk(): void
    {
        $fields = $this->suggestion('Başlığı güçlendir', 'title_description', ['proposal' => ['kind' => 'fields',
            'current' => ['seo_title' => 'Eski başlık'], 'new' => ['seo_title' => 'Ankara İmplant Tedavisi | Panorama', 'meta_description' => 'Yeni açıklama.']]]);
        $content = $this->suggestion('Eksik konu', 'missing_topic', ['proposal' => ['kind' => 'content', 'current' => [], 'new' => ['html' => '<p>Yeni bölüm</p>']]]);
        $alts = $this->suggestion('Alt metin', 'image_alt', ['site_id' => $this->site->id, 'images' => [['image_id' => 7, 'file' => 'implant.jpg', 'alt' => 'İmplant tedavisi']]]);
        $unprepared = $this->suggestion('Hazır değil', 'title_description', []);
        $location = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'name' => 'Panorama Çankaya']);
        $description = $this->suggestion('Açıklama', 'gbp_description', ['current' => 'Eski', 'proposed' => 'Yeni açıklama metni.'], ['channel' => 'maps', 'target_type' => 'gbp', 'target_id' => $location->id]);

        $desk = app(RepairDesk::class);
        $rows = $desk->rows()->keyBy('id');
        $this->assertSame([RepairDesk::SITE_FIELDS, RepairDesk::LOW], [$rows[$fields->id]['kind'], $rows[$fields->id]['risk']]);
        $this->assertSame(['Başlık: Eski başlık', 'Açıklama: —'], $rows[$fields->id]['before']);
        $this->assertSame(RepairDesk::HIGH, $rows[$content->id]['risk']);
        $this->assertSame(RepairDesk::IMAGE_ALT, $rows[$alts->id]['kind']);
        $this->assertSame(['Yeni açıklama metni.'], $rows[$description->id]['after']);
        $this->assertFalse($rows->has($unprepared->id), 'no value yet: prepared at night first');

        $desk->edit($fields->id, 'seo_title', 'İmplant Ankara | Panorama');
        $result = $desk->approve([$fields->id, $content->id, $alts->id], $this->admin);

        $this->assertSame(['applied' => 2, 'skipped_high' => 1, 'failed' => [], 'failed_ids' => []], $result);
        $write = ExternalWriteAction::query()->where('suggestion_id', $fields->id)->sole();
        $this->assertSame('İmplant Ankara | Panorama', $write->request_payload['changes'][0]['value'], 'the operator\'s edit is written');
        $this->assertSame(Suggestion::APPLIED, $alts->fresh()->status);
        $this->assertSame(Suggestion::OPEN, $content->fresh()->status, 'high risk waits for a single approval');
        Queue::assertPushed(ExecuteExternalWriteJob::class);

        $this->assertSame(['applied' => 1, 'skipped_high' => 0, 'failed' => [], 'failed_ids' => []], $desk->approve([$content->id], $this->admin));
        $this->assertSame(1, $desk->reject([$description->id], $this->admin, 'Marka kendi yazacak'));
        $this->assertSame(Suggestion::DISMISSED, $description->fresh()->status);
        $this->assertSame(0, $desk->counts()['total']);
    }

    public function test_page_filters_approves_and_lists_undoable_writes(): void
    {
        $fields = $this->suggestion('Başlığı güçlendir', 'title_description', ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => ['seo_title' => 'Yeni başlık']]]);
        $this->suggestion('Alt metin', 'image_alt', ['site_id' => $this->site->id, 'images' => [['image_id' => 7, 'file' => 'a.jpg', 'alt' => 'Alt']]]);

        Livewire::test(RepairDeskPage::class)->assertSee('Onarım masası')->assertSee('Onay bekleyen 2')->assertSee('Yeni başlık')
            ->set('brandId', $this->brand->id)->assertSee($this->brand->name.': onay bekleyen 2 paket · 2 iş')->assertSee('tüm markalarda 2 iş')
            ->set('brandId', null)
            ->set('kind', RepairDesk::SITE_FIELDS)->assertDontSee('a.jpg')
            ->call('approve', $fields->id)->assertSee('1 iş uygulamaya gönderildi')
            ->set('kind', '')->call('approveAllLow')->assertSee('1 iş uygulamaya gönderildi')
            ->assertSee('Yapılanlar')->assertSee('iş sırada');
        $this->get(route('operator.repair'))->assertOk();
    }

    public function test_rows_are_grouped_into_packages_and_lanes_approved_in_one_decision(): void
    {
        $first = $this->suggestion('Başlık 1', 'title_description', ['proposal' => ['kind' => 'fields', 'current' => ['seo_title' => 'Eski implant başlığı'], 'new' => ['seo_title' => 'Ankara implant başlığı']]]);
        $second = $this->suggestion('Başlık 2', 'title_description', ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => ['seo_title' => 'İkinci başlık']]]);
        $content = $this->suggestion('Metin', 'missing_topic', ['proposal' => ['kind' => 'content', 'current' => [], 'new' => ['html' => '<p>Yeni bölüm</p>']]]);

        $desk = app(RepairDesk::class);
        $packages = $desk->packages($desk->rows())->keyBy('key');
        $fields = $this->brand->id.'.'.RepairDesk::SITE_FIELDS.'.'.RepairDesk::LANE_READY;
        $this->assertSame(2, $packages[$fields]['count']);
        $this->assertSame(1, $packages[$this->brand->id.'.'.RepairDesk::SITE_CONTENT.'.'.RepairDesk::LANE_REVIEW]['high']);

        $page = Livewire::test(RepairDeskPage::class)->assertSet('lane', RepairDesk::LANE_READY)->assertSee('2 · Başlık, açıklama')->assertDontSee('Yeni bölüm')
            ->assertSee('Onayla, bitsin')->assertSee('Karar bekleyenler')
            ->call('togglePackage', $fields)->assertSee('Ankara implant başlığı')->assertSee('Klavye: J / K')
            ->call('selectPackage', $fields)->assertSet('selected', [$first->id, $second->id])->assertSee('2 iş seçili')
            ->set('lane', RepairDesk::LANE_REVIEW)->assertSet('selected', [])->assertSet('open', '')->assertSee('paketi açıp tek tek onaylanır');

        $page->set('lane', RepairDesk::LANE_READY)->call('approvePackage', $fields)->assertSee('2 iş uygulamaya gönderildi')
            ->assertSee('Onaylandı, sırada');
        $this->assertSame(Suggestion::OPEN, $content->fresh()->status);
        $this->assertSame(['queued' => 2, 'written' => 0, 'verified' => 0, 'returned' => 0], $desk->pipeline());
        ExternalWriteAction::query()->where('suggestion_id', $first->id)->update(['status' => 'failed']);
        $this->assertSame(1, $desk->pipeline()['returned']);

        Livewire::test(RepairDeskPage::class)->assertSet('lane', RepairDesk::LANE_REVIEW)->call('rejectPackage', $this->brand->id.'.'.RepairDesk::SITE_CONTENT.'.'.RepairDesk::LANE_REVIEW)
            ->assertSee('1 öneri reddedildi');
        $this->assertSame(Suggestion::DISMISSED, $content->fresh()->status);
    }

    /**
     * yakup 2026-10-11 (panel tasarım önerileri §2): one switch on top, brands sorted by expected gain (one drop-down
     * since 2026-10-19, with the waiting work instead of a percent), rows as "page name → new value" with the URL below, and old ?serit= / ?bolum= links still land.
     */
    public function test_one_switch_brand_list_by_gain_and_readable_rows(): void
    {
        $this->implantPage->forceFill(['h1' => 'İmplant Tedavisi Ankara'])->save();
        $this->suggestion('Başlık ve açıklamayı düzelt: /implant/', 'title_description', ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => ['seo_title' => 'Ankara implant | Panorama']]], ['priority' => 1]);
        $other = Brand::factory()->create(['customer_id' => $this->brand->customer_id, 'name' => 'Arka Marka']);
        foreach (range(1, 3) as $n) {
            $this->suggestion('Alt '.$n, 'image_alt', ['site_id' => $this->site->id, 'images' => [['image_id' => $n, 'file' => 'x'.$n.'.jpg', 'alt' => 'Alt']]], ['brand_id' => $other->id, 'priority' => 2]);
        }

        $health = app(RepairDesk::class)->health(app(RepairDesk::class)->rows());
        $this->assertSame([$other->id, $this->brand->id], $health->pluck('brand_id')->all(), 'three important rows (2 points each) gain more than one critical row (3 points)');
        $this->assertSame([[6.0, 0], [3.0, 1]], [[$health[0]['gain'], $health[0]['critical']], [$health[1]['gain'], $health[1]['critical']]]);

        $page = Livewire::test(RepairDeskPage::class)
            ->assertSee('Arka Marka · 3 iş bekliyor')->assertSee('· 1 kritik')->assertDontSee('kusursuz')->assertSeeHtml('data-health-help')->assertSeeHtml('data-repair-brand-select')->assertSee('Tüm markalar')
            ->set('brandId', $this->brand->id)->call('togglePackage', $this->brand->id.'.'.RepairDesk::SITE_FIELDS.'.'.RepairDesk::LANE_READY)
            ->assertSee('İmplant Tedavisi Ankara')->assertSee('Başlık: Ankara implant | Panorama')->assertSee('https://panorama.com.tr/implant/')
            ->assertSeeHtml('data-row-target');
        $page->call('showOther')->assertSet('section', RepairDeskPage::SECTION_OTHER)->assertSet('open', '')->assertSeeLivewire(WorkPage::class)
            ->call('showLane', RepairDesk::LANE_REVIEW)->assertSet('section', RepairDeskPage::SECTION_DESK)->assertSet('lane', RepairDesk::LANE_REVIEW);

        Livewire::withQueryParams(['bolum' => 'diger'])->test(RepairDeskPage::class)->assertSet('section', RepairDeskPage::SECTION_OTHER);
        Livewire::withQueryParams(['serit' => 'bogus'])->test(RepairDeskPage::class)->assertSet('lane', RepairDesk::LANE_READY);
    }

    public function test_bulk_approval_of_more_than_ten_opens_a_confirm_window_and_reject_is_secondary(): void
    {
        foreach (range(1, 11) as $n) {
            $this->suggestion('Başlık '.$n, 'title_description', ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => ['seo_title' => 'Yeni başlık '.$n]]]);
        }
        $key = $this->brand->id.'.'.RepairDesk::SITE_FIELDS.'.'.RepairDesk::LANE_READY;

        $html = Livewire::test(RepairDeskPage::class)->assertSee('Hepsini onayla (11)')->assertSeeHtml('data-bulk-confirm')
            ->assertSee('Geri alınabilir')->assertSee('Son 7 gün: 0 iş yapıldı')->html();
        preg_match('/<button[^>]*data-approve-package[^>]*>/', $html, $approve);
        preg_match('/<button[^>]*data-reject-package[^>]*>/', $html, $reject);
        $this->assertStringContainsString('ask =', $approve[0], 'more than ten: the window asks first');
        $this->assertStringNotContainsString('wire:click', $approve[0]);
        $this->assertStringContainsString('approvePackage', html_entity_decode($approve[0]));
        $this->assertStringNotContainsString('bg-brand-500', $reject[0], 'reject is an outline, not the main action');

        Livewire::test(RepairDeskPage::class)->call('approvePackage', $key)->assertSee('11 iş uygulamaya gönderildi');
    }

    public function test_the_done_summary_says_what_was_written_and_why_some_could_not_be(): void
    {
        $fields = $this->suggestion('Başlık', 'title_description', ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => ['seo_title' => 'Yeni başlık']]]);
        $desk = app(RepairDesk::class);
        $desk->approve([$fields->id], $this->admin);
        $write = ExternalWriteAction::query()->sole();
        $write->forceFill(['status' => 'partial', 'request_payload' => ['changes' => [
            ['type' => 'seo_title', 'reference' => 'a', 'value' => 'Yeni başlık'],
            ['type' => 'internal_link', 'reference' => 'b', 'value' => []],
            ['type' => 'internal_link', 'reference' => 'c', 'value' => []],
        ]], 'result' => ['status' => 'partial', 'changes' => [
            ['reference' => 'a', 'ok' => true], ['reference' => 'b', 'ok' => true],
            ['reference' => 'c', 'ok' => false, 'error' => 'anchor text not found as plain text in the page'],
        ]]])->save();

        $group = $desk->done()->sole();

        $this->assertSame('1 başlık, 1 iç link yazıldı', $group['sentence']);
        $this->assertSame(['1 iç link: bağlantı metni sayfada düz yazı olarak yok (başlıkta, butonda ya da zaten linkli), link eklenmedi'], $group['problems']);
        $this->assertSame([0, 1], [$group['ok'], $group['partial']]);
        $this->assertSame([['text' => 'başlık: Yeni başlık', 'ok' => true], ['text' => 'iç link: "" → ', 'ok' => true],
            ['text' => 'iç link: "" →  — yapılmadı: bağlantı metni sayfada düz yazı olarak yok (başlıkta, butonda ya da zaten linkli), link eklenmedi', 'ok' => false]],
            $group['items'][0]['lines']);
        Livewire::test(RepairDeskPage::class)->assertSee('1 başlık, 1 iç link yazıldı')->assertSee('Hepsini geri al')->assertSee('Son 7 gün: 1 iş yapıldı')
            ->call('undoGroup', $group['key'])->assertSee('1 iş için geri alma sıraya alındı');
        $this->assertSame('undoing', $write->fresh()->status);
    }

    /**
     * yakup 2026-10-19 (Onarım masası sadeleştirme): a failed write whose suggestion is back on the desk says so, the badge
     * shows the failures, a group's single writes are rendered only when opened, and an empty Sonuçlar says when the
     * first result comes.
     */
    public function test_failed_writes_say_they_went_back_to_the_desk_and_details_load_on_open(): void
    {
        $fields = $this->suggestion('Başlık ve açıklamayı düzelt: /implant/', 'title_description', ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => ['seo_title' => 'Yeni başlık']]]);
        $desk = app(RepairDesk::class);
        $desk->approve([$fields->id], $this->admin);
        ExternalWriteAction::query()->sole()->forceFill(['status' => 'failed', 'error' => 'Gönderilmedi: site son 30 dakikada 3 yazmaya yanıt vermedi.'])->save();
        // RepairVerifier puts a suggestion whose writes all failed back on the desk.
        Suggestion::query()->whereKey($fields->id)->update(['status' => Suggestion::OPEN]);

        $this->assertSame(Suggestion::OPEN, $fields->fresh()->status);
        $group = $desk->done()->sole();
        $this->assertSame([1, 1], [$group['failed'], $group['returned']]);

        Livewire::test(RepairDeskPage::class)->assertSee('Son 7 gün: 0 iş yapıldı')->assertSee('· 1 yazılamadı')
            ->assertSee('Yazılamayan 1 iş kaybolmadı')->assertSee('1 iş masaya geri döndü; sonraki gönderimde gider.')
            ->assertDontSeeHtml('data-done-items')->assertSee('Ayrıntı (1)')
            ->call('toggleDone', $group['key'])->assertSeeHtml('data-done-items')->assertSee('Kapat')
            ->call('toggleDone', $group['key'])->assertDontSeeHtml('data-done-items');
    }

    public function test_empty_results_say_when_the_first_measurement_comes(): void
    {
        $this->suggestion('Başlık', 'title_description', ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => ['seo_title' => 'Yeni']]],
            ['status' => Suggestion::APPLIED, 'applied_at' => now()->subDays(5)]);

        $results = app(RepairDesk::class)->results();

        $this->assertSame([], $results['items']);
        $this->assertSame(now()->subDays(5)->addDays(30)->timezone('Europe/Istanbul')->format('d.m.Y'), $results['next']);
        Livewire::test(RepairDeskPage::class)->assertSee('İlk sonuç '.$results['next'].' civarında gelir.')->assertDontSee('0 işe yaradı');
    }

    public function test_word_difference_marks_only_the_changed_words(): void
    {
        $diff = RepairDesk::diff('Başlık: Eski implant başlığı', 'Başlık: Ankara implant başlığı');

        $this->assertSame([['Eski', true]], array_values(array_filter($diff['before'], fn (array $w): bool => $w[1])));
        $this->assertSame([['Ankara', true]], array_values(array_filter($diff['after'], fn (array $w): bool => $w[1])));
        $this->assertSame('Başlık: Ankara implant başlığı', implode('', array_column($diff['after'], 0)));
    }

    public function test_nightly_preparation_queues_unprepared_site_fixes_and_the_digest_counts_ready_ones(): void
    {
        $unprepared = $this->suggestion('Hazır değil', 'title_description', []);
        $this->suggestion('Hazır', 'title_description', ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => ['seo_title' => 'Yeni']]]);
        $this->suggestion('Engelli', 'title_description', ['proposal_blocked' => 'garanti']);

        $this->assertSame(['batch' => 1, 'fields' => 0, 'content' => 0], app(RepairPreparer::class)->queue());
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::SEO_FIELDS_BATCH && $job->params === ['suggestion_ids' => [$unprepared->id]]);
        Queue::assertPushed(RunSiteOperationJob::class, 1);

        $this->artisan('moxdop:repair:prepare', ['--fields' => 0])->expectsOutputToContain('0 alan düzeltmesi')->assertSuccessful();
        $this->artisan('moxdop:repair:digest')->expectsOutputToContain('1 iş onay bekliyor')->assertSuccessful();
        $this->assertNotNull($unprepared->fresh());
    }

    public function test_a_day_after_the_write_the_fix_is_checked_on_the_page_and_failed_writes_return_to_the_desk(): void
    {
        $fields = $this->suggestion('Başlığı güçlendir', 'title_description', ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => ['seo_title' => 'Yeni Başlık']]]);
        $other = $this->suggestion('Açıklama', 'title_description', ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => ['meta_description' => 'Yeni açıklama']]]);
        app(RepairDesk::class)->approve([$fields->id, $other->id], $this->admin);
        ExternalWriteAction::query()->where('suggestion_id', $other->id)->update(['status' => 'failed', 'error' => 'Eklenti yanıt vermedi']);

        $this->travel(2)->hours();
        $this->assertSame(['confirmed' => 0, 'still_seen' => 0, 'reopened' => 0], app(RepairVerifier::class)->run(), 'settling');
        $this->travel(1)->day();
        $this->implantPage->forceFill(['title' => 'Yeni  Başlık'])->save();
        $this->artisan('moxdop:repair:verify')->expectsOutputToContain('1 düzeltme doğrulandı')->assertSuccessful();

        $this->assertSame(Suggestion::VERIFY_CONFIRMED, $fields->fresh()->verification);
        $this->assertSame(Suggestion::OPEN, $other->fresh()->status);
        $row = app(RepairDesk::class)->rows()->firstWhere('id', $other->id);
        $this->assertStringContainsString('Eklenti yanıt vermedi', $row['reason'], 'back on the desk with the error');
    }

    public function test_site_audit_opens_one_fix_per_page_with_bad_title_or_description_and_closes_it_when_fixed(): void
    {
        $this->implantPage->forceFill(['title' => 'Ankara İmplant Tedavisi | Panorama Diş', 'meta_description' => null])->save();
        $long = $this->page('/zirkonyum/', str_repeat('Zirkonyum kaplama Ankara ', 4), ['wp_post_id' => 43,
            'meta_description' => str_repeat('Zirkonyum kaplama hakkında bilgi. ', 3)]);
        $twin = $this->page('/zirkonyum-2/', str_repeat('Zirkonyum kaplama Ankara ', 4), ['wp_post_id' => 44, 'meta_description' => str_repeat('Başka bir açıklama metni burada. ', 3)]);
        $fine = $this->page('/hakkimizda/', 'Hakkımızda | Panorama Ağız ve Diş Sağlığı', ['wp_post_id' => 45, 'meta_description' => str_repeat('Panorama Ankara hakkında bilgi. ', 3)]);
        $this->page('/noindex/', 'Kısa', ['wp_post_id' => 46, 'is_indexable' => false]);

        $this->artisan('moxdop:repair:audit')->expectsOutputToContain('3 sayfa için düzeltme açıldı')->assertSuccessful();

        $rows = Suggestion::query()->where('action_type', SiteAudit::TYPE)->get()->keyBy('page_id');
        $this->assertSame([$this->implantPage->id, $long->id, $twin->id], $rows->keys()->sort()->values()->all());
        $this->assertSame('Meta açıklama yok.', $rows[$this->implantPage->id]->reason);
        $this->assertSame(3, $rows[$this->implantPage->id]->priority, 'missing description is not critical');
        $this->assertStringContainsString('Başlık 2 sayfada aynı', $rows[$long->id]->reason);
        $this->assertFalse($rows->has($fine->id));
        $this->assertSame(['batch' => 3, 'fields' => 0, 'content' => 0], app(RepairPreparer::class)->queue(), 'prepared in batches of 15 pages per AI call');

        $this->implantPage->forceFill(['meta_description' => str_repeat('Ankara implant tedavisi süreci ve fiyatları. ', 2)])->save();
        $this->assertSame(['opened' => 0, 'closed' => 1], app(SiteAudit::class)->audit($this->site));
        $this->assertSame(Suggestion::VERIFY_AUTO, $rows[$this->implantPage->id]->fresh()->verification);
        $this->assertSame(Suggestion::OPEN, $rows[$long->id]->fresh()->status);
    }

    public function test_a_very_long_page_address_still_fits_the_fix_title(): void
    {
        $path = '/sorular-ve-cevap/'.str_repeat('yapay-koklerin-cene-kemigiyle-kaynasma-suresi-', 4);
        $page = $this->page($path, str_repeat('Yapay köklerin kaynaşma süresi ', 4), ['wp_post_id' => 47]);

        $this->artisan('moxdop:repair:audit')->assertSuccessful();

        $title = (string) Suggestion::query()->where('action_type', SiteAudit::TYPE)->where('page_id', $page->id)->value('title');
        $this->assertStringStartsWith('Başlık ve açıklamayı düzelt: /sorular-ve-cevap/', $title);
        $this->assertLessThanOrEqual(160, mb_strlen($title));
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $extra
     */
    /**
     * yakup, 2026-10-09: thousands of pages without a description are prepared 15 pages per AI call; each value passes
     * the rules (length, not another page's title, no invented number, sector phrases) before it reaches the desk.
     */
    public function test_title_and_description_fixes_are_prepared_in_batches_and_checked_by_rules(): void
    {
        $this->enableAi();
        $this->implantPage->forceFill(['title' => 'İmplant', 'meta_description' => null, 'content_text' => 'Ankara Çankaya şubemizde implant tedavisi yapılır. Tedavi 2 aşamalıdır.'])->save();
        $zirkonyum = $this->page('/zirkonyum/', 'Zirkonyum', ['wp_post_id' => 43, 'meta_description' => null, 'category' => 'hizmet']);
        $this->page('/hakkimizda/', 'Hakkımızda | Panorama Ankara Ağız ve Diş Sağlığı', ['wp_post_id' => 45]);
        $make = fn (Page $page, array $problems): Suggestion => $this->suggestion('Başlık ve açıklamayı düzelt: '.$page->path, SiteAudit::TYPE, ['site_id' => $this->site->id, 'problems' => $problems], ['page_id' => $page->id]);
        $implant = $make($this->implantPage, ['seo_title:short', 'meta_description:missing']);
        $other = $make($zirkonyum, ['meta_description:missing']);
        $prompts = [];
        SeoFieldsBatchAgent::fake(function (string $prompt) use (&$prompts, $implant, $other): array {
            $prompts[] = $prompt;

            return ['pages' => [
                ['id' => $implant->id, 'seo_title' => 'Ankara Çankaya implant tedavisi | Panorama', 'meta_description' => 'Çankaya şubemizde implant tedavisi 2 aşamada yapılır; muayene ve tedavi planı için randevu alabilirsiniz.'],
                ['id' => $other->id, 'seo_title' => 'Hakkımızda | Panorama Ankara Ağız ve Diş Sağlığı', 'meta_description' => 'Zirkonyum kaplamada 15 yıl garanti veriyoruz, hemen arayın ve fiyat bilgisi alın lütfen.'],
            ]];
        });

        $this->assertSame(['status' => 'ready', 'prepared' => 1, 'skipped' => 1], app(SeoFieldsBatch::class)->prepare($this->site, [$implant->id, $other->id]));

        $this->assertCount(1, $prompts, 'one call for both pages');
        $this->assertStringContainsString('"fix":{"seo_title":"short","meta_description":"missing"}', $prompts[0]);
        $this->assertSame(['seo_title' => 'Ankara Çankaya implant tedavisi | Panorama', 'meta_description' => 'Çankaya şubemizde implant tedavisi 2 aşamada yapılır; muayene ve tedavi planı için randevu alabilirsiniz.'],
            $implant->fresh()->action['proposal']['new']);
        $this->assertArrayNotHasKey('proposal', $other->fresh()->action, 'invented number and claim: nothing prepared, the row waits');
        $this->assertSame('Başlık: Ankara Çankaya implant tedavisi | Panorama', app(RepairDesk::class)->rows()->firstWhere('id', $implant->id)['after'][0], 'ready on the desk');
        $this->assertSame(1, SeoFieldsBatch::waitingQuery()->count());

        // Genel işler lists neither: the prepared one waits on the desk, the other is counted as being prepared.
        Livewire::test(WorkPage::class)->assertDontSee('Başlık ve açıklamayı düzelt: /zirkonyum/')
            ->assertSee('Hazırlanmış 1 düzeltme Onarım masasında onayını bekliyor; 1 sayfa düzeltmesini sistem hazırlıyor');
    }

    /**
     * yakup 2026-10-13: approving on the desk answered nginx 504. The click only marks the rows queued and dispatches
     * ApproveRepairRowsJob on the writes queue; the rows leave the list at once and the page says they are being applied.
     */
    public function test_approval_returns_at_once_and_runs_on_the_writes_queue(): void
    {
        Queue::fake();
        config(['queue.writes_queue' => 'writes']);
        $first = $this->suggestion('Başlık 1', 'title_description', ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => ['seo_title' => 'Birinci başlık']]]);
        $second = $this->suggestion('Başlık 2', 'title_description', ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => ['seo_title' => 'İkinci başlık']]]);
        $content = $this->suggestion('Metin', 'missing_topic', ['proposal' => ['kind' => 'content', 'current' => [], 'new' => ['html' => '<p>Yeni bölüm</p>']]]);
        $desk = app(RepairDesk::class);

        Livewire::test(RepairDeskPage::class)->set('selected', [$first->id, $second->id, $content->id])->call('approveSelected')
            ->assertSee('2 iş uygulamaya gönderildi')->assertSee('1 yüksek riskli iş tek tek onaylanmalı')
            ->assertSee('Onaylanan 2 iş arka planda uygulanıyor');

        Queue::assertPushedOn('writes', ApproveRepairRowsJob::class, fn (ApproveRepairRowsJob $job): bool => $job->ids === [$first->id, $second->id] && $job->userId === $this->admin->id);
        Queue::assertNotPushed(ExecuteExternalWriteJob::class);
        $this->assertSame(0, ExternalWriteAction::query()->count(), 'nothing is written inside the click');
        $this->assertSame([$content->id], $desk->rows()->pluck('id')->all(), 'queued rows leave the list');
        $this->assertSame(2, $desk->queuedCount());
        $this->assertSame(Suggestion::OPEN, $first->fresh()->status);

        (new ApproveRepairRowsJob([$first->id, $second->id], $this->admin->id))->handle($desk);

        $this->assertSame(0, $desk->queuedCount());
        $this->assertSame(2, ExternalWriteAction::query()->whereIn('suggestion_id', [$first->id, $second->id])->count());
        $this->assertArrayNotHasKey(RepairDesk::QUEUED_AT, (array) $first->fresh()->action);
        Queue::assertPushed(ExecuteExternalWriteJob::class, 2);
    }

    public function test_a_row_the_approval_job_could_not_send_returns_with_its_reason(): void
    {
        Queue::fake();
        $fields = $this->suggestion('Başlık', 'title_description', ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => ['seo_title' => 'Yeni başlık']]]);
        $desk = app(RepairDesk::class);
        $desk->queueApproval([$fields->id], $this->admin);
        $this->assertSame([], $desk->rows()->pluck('id')->all());
        $this->implantPage->forceFill(['wp_post_id' => null])->save();

        (new ApproveRepairRowsJob([$fields->id], $this->admin->id))->handle($desk);

        $row = $desk->rows()->firstWhere('id', $fields->id);
        $this->assertNotNull($row, 'back on the desk');
        $this->assertStringContainsString('Önceki gönderim başarısız: Sayfa WordPress’ten gelmiyor', $row['reason']);
        $this->assertSame(0, $desk->queuedCount());

        // A job that died (timeout) puts the row back with a plain reason; a later successful approval clears it.
        $desk->queueApproval([$fields->id], $this->admin);
        (new ApproveRepairRowsJob([$fields->id], $this->admin->id))->failed(new \RuntimeException('Job timed out'));
        $this->assertStringContainsString('Onay uygulanamadı (Job timed out); tekrar onaylayın.', $desk->rows()->firstWhere('id', $fields->id)['reason']);
        $this->implantPage->forceFill(['wp_post_id' => 42])->save();
        (new ApproveRepairRowsJob([$fields->id], $this->admin->id))->handle($desk);
        $this->assertArrayNotHasKey(RepairDesk::DESK_ERROR, (array) $fields->fresh()->action);
        $this->assertSame(Suggestion::APPLIED, $fields->fresh()->status);
    }

    public function test_a_stale_queued_mark_no_longer_hides_the_row(): void
    {
        $fields = $this->suggestion('Başlık', 'title_description', ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => ['seo_title' => 'Yeni başlık']],
            RepairDesk::QUEUED_AT => now()->subMinutes(RepairDesk::QUEUED_MINUTES + 5)->toIso8601String()]);

        $this->assertSame([$fields->id], app(RepairDesk::class)->rows()->pluck('id')->all());
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $extra
     */
    private function suggestion(string $title, string $type, array $action, array $extra = []): Suggestion
    {
        return Suggestion::query()->create(array_merge([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'repair.test', 'fingerprint' => hash('sha256', $title.random_int(1, PHP_INT_MAX)),
            'material_hash' => hash('sha256', 'x'), 'title' => $title, 'reason' => 'Gerekçe.', 'priority' => 2, 'action_type' => $type,
            'status' => Suggestion::OPEN, 'action' => $action, 'evidence' => [],
            'page_id' => in_array($type, ['title_description', 'missing_topic'], true) ? $this->implantPage->id : null,
        ], $extra));
    }
}
