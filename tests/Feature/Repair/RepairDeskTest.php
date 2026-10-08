<?php

namespace Tests\Feature\Repair;

use App\Jobs\ExecuteExternalWriteJob;
use App\Jobs\Site\RunSiteOperationJob;
use App\Livewire\Operator\Repair\RepairDeskPage;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Repair\RepairDesk;
use App\Services\Repair\RepairPreparer;
use App\Services\Repair\RepairVerifier;
use App\Services\Repair\SiteAudit;
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
        Queue::fake();
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

        $this->assertSame(['applied' => 2, 'skipped_high' => 1, 'failed' => []], $result);
        $write = ExternalWriteAction::query()->where('suggestion_id', $fields->id)->sole();
        $this->assertSame('İmplant Ankara | Panorama', $write->request_payload['changes'][0]['value'], 'the operator\'s edit is written');
        $this->assertSame(Suggestion::APPLIED, $alts->fresh()->status);
        $this->assertSame(Suggestion::OPEN, $content->fresh()->status, 'high risk waits for a single approval');
        Queue::assertPushed(ExecuteExternalWriteJob::class);

        $this->assertSame(['applied' => 1, 'skipped_high' => 0, 'failed' => []], $desk->approve([$content->id], $this->admin));
        $this->assertSame(1, $desk->reject([$description->id], $this->admin, 'Marka kendi yazacak'));
        $this->assertSame(Suggestion::DISMISSED, $description->fresh()->status);
        $this->assertSame(0, $desk->counts()['total']);
    }

    public function test_page_filters_approves_and_lists_undoable_writes(): void
    {
        $fields = $this->suggestion('Başlığı güçlendir', 'title_description', ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => ['seo_title' => 'Yeni başlık']]]);
        $this->suggestion('Alt metin', 'image_alt', ['site_id' => $this->site->id, 'images' => [['image_id' => 7, 'file' => 'a.jpg', 'alt' => 'Alt']]]);

        Livewire::test(RepairDeskPage::class)->assertSee('Onarım masası')->assertSee('Onay bekleyen 2')->assertSee('Yeni başlık')
            ->set('kind', RepairDesk::SITE_FIELDS)->assertDontSee('a.jpg')
            ->call('approve', $fields->id)->assertSee('1 iş uygulamaya gönderildi')
            ->set('kind', '')->call('approveAllLow')->assertSee('1 iş uygulamaya gönderildi')
            ->assertSee('Son 7 günde uygulananlar (2)');
        $this->get(route('operator.repair'))->assertOk();
    }

    public function test_nightly_preparation_queues_unprepared_site_fixes_and_the_digest_counts_ready_ones(): void
    {
        $unprepared = $this->suggestion('Hazır değil', 'title_description', []);
        $this->suggestion('Hazır', 'title_description', ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => ['seo_title' => 'Yeni']]]);
        $this->suggestion('Engelli', 'title_description', ['proposal_blocked' => 'garanti']);

        $this->assertSame(['fields' => 1, 'content' => 0], app(RepairPreparer::class)->queue());
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
        $this->assertSame(1, $rows[$this->implantPage->id]->priority);
        $this->assertStringContainsString('Başlık 2 sayfada aynı', $rows[$long->id]->reason);
        $this->assertFalse($rows->has($fine->id));
        $this->assertSame(['fields' => 3, 'content' => 0], app(RepairPreparer::class)->queue(), 'prepared overnight like the other field fixes');

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
