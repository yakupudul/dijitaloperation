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

    private function attachment(int $id, int $parent, string $file, string $alt): void
    {
        DB::table('website_cms_object_snapshot')->insert(['digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'object_type' => 'attachment', 'object_id' => (string) $id,
            'status' => 'inherit', 'title' => pathinfo($file, PATHINFO_FILENAME), 'parent_id' => (string) $parent, 'observed_at' => now(), 'contract_version' => 1,
            'metadata' => json_encode(['mime_type' => 'image/jpeg', 'alt_text' => $alt, 'file' => '2026/09/'.$file]),
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', 'a'.$id), 'created_at' => now(), 'updated_at' => now()]);
    }
}
