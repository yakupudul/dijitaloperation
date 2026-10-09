<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\ImageAltsAgent;
use App\Jobs\ExecuteExternalWriteJob;
use App\Livewire\Operator\Website\V2\SuggestionsTab;
use App\Models\CoreConnection;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Services\Site\ChangeApplier;
use App\Services\Site\ImageAlts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Görsel alt metni: WordPress images without alt text on a stored page get AI-proposed alt texts (one suggestion per
 * page); Onayla writes them through the approved SEO-fix path. "AI ile yap" never repeats schema the SEO plugin prints.
 */
final class ImageAltsTest extends SiteTestCase
{
    public function test_images_without_alt_get_proposals_and_approval_queues_the_wordpress_write(): void
    {
        $this->enableAi();
        Queue::fake();
        CoreConnection::factory()->create(['digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://panorama.com.tr/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.5.0']]);
        $page = $this->page('/implant/', 'İmplant Tedavisi', ['wp_post_id' => 42]);
        $this->attachment(501, 42, 'implant-vida-modeli.jpg', '');
        $this->attachment(502, 42, 'IMG_2034.jpg', '');
        $this->attachment(503, 42, 'logo.png', 'Panorama logosu');
        $this->attachment(504, 999, 'baska-sayfa.jpg', '');
        ImageAltsAgent::fake([['images' => [['image_id' => 501, 'alt' => 'İmplant vidası modeli'], ['image_id' => 504, 'alt' => 'Uydurma']]]]);

        $this->assertSame(['status' => 'ready', 'images' => 2, 'proposed' => 1], app(ImageAlts::class)->propose($this->site));

        $suggestion = Suggestion::query()->where('action_type', ImageAlts::TYPE)->sole();
        $this->assertSame($page->id, $suggestion->page_id);
        $this->assertSame([['image_id' => 501, 'file' => 'implant-vida-modeli.jpg', 'alt' => 'İmplant vidası modeli']], $suggestion->action['images']);
        Livewire::test(SuggestionsTab::class, ['assetId' => $this->site->id])->assertSee('Görsel alt metni: 1 görsel')
            ->call('applyAlts', $suggestion->id)->assertHasNoErrors();

        $write = ExternalWriteAction::query()->where('suggestion_id', $suggestion->id)->sole();
        $this->assertSame([['type' => 'alt_text', 'object_id' => 501, 'reference' => 'suggestion-'.$suggestion->id.'-alt-501', 'value' => 'İmplant vidası modeli']], $write->request_payload['changes']);
        $this->assertSame(Suggestion::APPLIED, $suggestion->fresh()->status);
        Queue::assertPushed(ExecuteExternalWriteJob::class);
        $this->assertSame([], app(ImageAlts::class)->missing($this->site)->pluck('object_id')->diff([502])->values()->all(), 'proposed images are not asked again');
    }

    public function test_svg_and_decorative_images_are_left_out(): void
    {
        $this->page('/implant/', 'İmplant Tedavisi', ['wp_post_id' => 42]);
        $this->attachment(601, 42, 'implant-sonrasi-gulus.jpg', '');
        $this->attachment(602, 42, 'dis-ikon.svg', '', 'image/svg+xml');
        $this->attachment(603, 42, 'icon-telefon.png', '');
        $this->attachment(604, 42, 'hero-bg-2.jpg', '');
        $this->attachment(605, 42, 'divider_wave.png', '');
        $this->attachment(606, 42, 'arrow-right.png', '');

        $this->assertSame([601], app(ImageAlts::class)->missing($this->site)->pluck('object_id')->all());
        $this->assertFalse(ImageAlts::isDecorative('2026/09/bgc-implant-klinigi.jpg'), 'only whole words: "bgc" is not "bg"');
    }

    public function test_rows_close_once_the_site_has_the_alt_text_and_approval_never_overwrites_one(): void
    {
        Queue::fake();
        CoreConnection::factory()->create(['digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://panorama.com.tr/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.5.0']]);
        $page = $this->page('/zirkonyum/', 'Zirkonyum Kaplama', ['wp_post_id' => 43]);
        $this->attachment(701, 43, 'zirkonyum-oncesi.jpg', '');
        $this->attachment(702, 43, 'zirkonyum-sonrasi.jpg', '');
        $this->attachment(703, 43, 'zirkonyum-kaplama-asamalari.jpg', '');
        $row = fn (array $ids): Suggestion => Suggestion::query()->create(['brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => ImageAlts::DECISION,
            'fingerprint' => hash('sha256', implode(',', $ids).random_int(1, PHP_INT_MAX)), 'material_hash' => hash('sha256', 'x'), 'title' => 'Görsel alt metni: '.count($ids).' görsel', 'reason' => 'Alt metin yok.',
            'priority' => 4, 'action_type' => ImageAlts::TYPE, 'target_type' => 'page', 'target_id' => $page->id, 'page_id' => $page->id, 'status' => Suggestion::OPEN, 'evidence' => [],
            'action' => ['site_id' => $this->site->id, 'images' => array_map(fn (int $id): array => ['image_id' => $id, 'file' => $id.'.jpg', 'alt' => 'Zirkonyum kaplama '.$id], $ids)]]);
        $done = $row([701]);
        $partly = $row([702, 703]);
        // The operator wrote the alt texts of 701 and 702 in WordPress meanwhile.
        $this->attachment(701, 43, 'zirkonyum-oncesi.jpg', 'Zirkonyum kaplama öncesi dişler', at: now()->addMinute());
        $this->attachment(702, 43, 'zirkonyum-sonrasi.jpg', 'Zirkonyum kaplama sonrası gülüş', at: now()->addMinute());

        $this->assertSame(['status' => 'ready', 'images' => 0, 'proposed' => 0], app(ImageAlts::class)->propose($this->site), '703 already has a row');

        $this->assertSame([Suggestion::APPLIED, Suggestion::VERIFY_AUTO], [$done->fresh()->status, $done->fresh()->verification]);
        $this->assertSame([703], array_column($partly->fresh()->action['images'], 'image_id'), 'only the image still without alt stays');
        $this->assertSame('Görsel alt metni: 1 görsel', $partly->fresh()->title);

        // Approval of a row prepared before the site changed: an image with alt text on the site is not overwritten.
        $stale = $row([702, 703]);
        app(ImageAlts::class)->approve($stale, $this->admin);
        $this->assertSame([703], array_column(ExternalWriteAction::query()->where('suggestion_id', $stale->id)->sole()->request_payload['changes'], 'object_id'));
    }

    public function test_schema_the_seo_plugin_already_prints_is_never_proposed_again(): void
    {
        $page = $this->page('/implant/', 'İmplant Tedavisi', ['wp_post_id' => 42]);
        DB::table('website_cms_seo_snapshot')->insert(['digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'object_type' => 'page', 'object_id' => '42',
            'seo_provider' => 'yoast', 'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', 'seo42'), 'created_at' => now(), 'updated_at' => now()]);
        $applier = app(ChangeApplier::class);
        $existing = ChangeApplier::existingSchema($page);
        $this->assertSame('yoast', $existing['seo_plugin']);
        $this->assertContains('BreadcrumbList', $existing['plugin_types']);

        $webPage = $applier->validated(['schema_json' => json_encode(['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => 'İmplant'])], $page, null, [], true);
        $this->assertNull($webPage, 'the plugin already prints WebPage');
        $faq = $applier->validated(['schema_json' => json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []])], $page, null, [], true);
        $this->assertNotNull($faq['new']['schema_json'] ?? null);
    }

    private function attachment(int $id, int $parent, string $file, string $alt, string $mime = 'image/jpeg', ?\DateTimeInterface $at = null): void
    {
        $at ??= now();
        DB::table('website_cms_object_snapshot')->insert(['digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'object_type' => 'attachment', 'object_id' => (string) $id,
            'status' => 'inherit', 'title' => pathinfo($file, PATHINFO_FILENAME), 'parent_id' => (string) $parent, 'observed_at' => $at, 'contract_version' => 1,
            'metadata' => json_encode(['mime_type' => $mime, 'alt_text' => $alt, 'file' => '2026/09/'.$file]),
            'first_collected_at' => $at, 'last_collected_at' => $at, 'record_fingerprint' => hash('sha256', 'a'.$id.$at->format('U')), 'created_at' => $at, 'updated_at' => $at]);
    }
}
