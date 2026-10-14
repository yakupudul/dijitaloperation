<?php

namespace Tests\Feature\Mcp;

use App\Jobs\Site\RunSiteOperationJob;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\CorrectResult;
use App\Mcp\Tools\TaskResult;
use App\Models\AiTask;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Services\AiTasks\ResultCorrections;
use App\Services\Site\SiteOperations;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Site\SiteTestCase;

/**
 * Düzeltme: a result Claude already submitted is fixed where it landed — in place while it has not been sent (and
 * checked again), as a new row for the operator's approval once it went to the site.
 */
final class ResultCorrectionsTest extends SiteTestCase
{
    private const array ARTICLE = [
        'title' => 'İmplant sonrası ilk hafta', 'slug' => 'implant-sonrasi-ilk-hafta', 'meta_title' => 'İmplant sonrası ilk hafta',
        'meta_description' => 'İlk hafta nelere dikkat edilir?', 'excerpt' => 'İlk hafta rehberi.', 'language' => 'tr', 'post_type' => 'post',
        'html' => '<h2>İlk 24 saat</h2><p>Soğuk uygulama aynı kkalabilir.</p>', 'reference' => 'suggestion-1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['moxdop-mcp.token' => 'test-mcp-token']);
    }

    public function test_unsent_article_is_corrected_in_place_and_read_again_by_the_content_check(): void
    {
        $suggestion = $this->content(['autopilot' => ['date' => '2026-10-15', 'review' => ['verdict' => 'ok']],
            'translations' => ['en' => ['title' => 'First week after an implant', 'html' => '<h2>Sık sorulan sorular</h2><p>Cold packs help.</p>', 'language' => 'en']]]);
        $task = $this->task(['suggestion_id' => $suggestion->id], ['title' => 'x', 'html' => '<p>x</p>']);

        MoxdopServer::tool(TaskResult::class, ['id' => $task->id])->assertOk()->assertSee('article.html')->assertSee('translations.en.html');
        MoxdopServer::tool(CorrectResult::class, ['target' => 'suggestion', 'id' => $suggestion->id, 'path' => 'article.html', 'task_id' => $task->id,
            'replacements' => [['find' => 'aynı kkalabilir', 'replace' => 'aynı kalabilir']], 'note' => 'Yazım hatası düzeltildi.'])->assertOk()->assertSee('corrected');
        MoxdopServer::tool(CorrectResult::class, ['target' => 'suggestion', 'id' => $suggestion->id, 'path' => 'translations.en.html',
            'replacements' => [['find' => 'Sık sorulan sorular', 'replace' => 'Frequently asked questions']], 'note' => 'SSS başlığı Türkçe kalmıştı.'])->assertOk();

        $action = $suggestion->fresh()->action;
        $this->assertSame('<h2>İlk 24 saat</h2><p>Soğuk uygulama aynı kalabilir.</p>', $action['article']['html']);
        $this->assertSame('<h2>Frequently asked questions</h2><p>Cold packs help.</p>', $action['translations']['en']['html']);
        $this->assertArrayNotHasKey('review', $action['autopilot'], 'the content check reads the corrected article again');
        $this->assertSame('2026-10-15', $action['autopilot']['date']);
        $this->assertCount(2, $action['corrections']);
        $this->assertSame($task->id, $action['corrections'][0]['task_id']);
        $this->assertSame(1, Suggestion::query()->count(), 'no new row while nothing was sent');
    }

    public function test_a_missing_find_or_a_forbidden_phrase_is_refused(): void
    {
        $suggestion = $this->content();

        MoxdopServer::tool(CorrectResult::class, ['target' => 'suggestion', 'id' => $suggestion->id, 'path' => 'article.html',
            'replacements' => [['find' => 'olmayan metin', 'replace' => 'x']], 'note' => 'Deneme düzeltmesi.'])->assertHasErrors(['bulunamadı']);
        MoxdopServer::tool(CorrectResult::class, ['target' => 'suggestion', 'id' => $suggestion->id, 'path' => 'article.nope',
            'value' => 'x', 'note' => 'Deneme düzeltmesi.'])->assertHasErrors(['yok']);

        $this->assertSame(self::ARTICLE['html'], $suggestion->fresh()->action['article']['html']);
    }

    public function test_unsent_desk_proposal_is_edited_and_the_desk_check_sees_a_new_proposal(): void
    {
        $page = $this->page('/hurda-bakir/', 'İzmir De Hurda Bakır Alımı');
        $suggestion = $this->fields($page->id, ['seo_title' => 'İzmir De Hurda Bakır Alımı 2023']);

        app(ResultCorrections::class)->correct('suggestion', $suggestion->id, 'proposal.seo_title', [], 'İzmir\'de Hurda Bakır Alımı', 'Bozuk ve eskimiş başlık.');

        $this->assertSame('İzmir\'de Hurda Bakır Alımı', $suggestion->fresh()->action['proposal']['new']['seo_title']);
        $this->assertSame(1, Suggestion::query()->count());
    }

    public function test_a_sent_title_becomes_a_new_desk_row_and_the_site_is_not_touched(): void
    {
        $page = $this->page('/hurda-bakir/', 'İzmir De Hurda Bakır Alımı');
        $suggestion = $this->fields($page->id, ['seo_title' => 'İzmir De Hurda Bakır Alımı'], ['status' => Suggestion::APPLIED]);

        $result = app(ResultCorrections::class)->correct('suggestion', $suggestion->id, 'proposal.seo_title', [], 'İzmir\'de Hurda Bakır Alımı', 'Bozuk başlık.');

        $this->assertSame('follow_up', $result['outcome']);
        $followUp = Suggestion::query()->findOrFail($result['follow_up_id']);
        $this->assertSame(Suggestion::OPEN, $followUp->status);
        $this->assertSame('title_description', $followUp->action_type);
        $this->assertSame($page->id, $followUp->page_id);
        $this->assertSame(['seo_title' => 'İzmir\'de Hurda Bakır Alımı'], $followUp->action['proposal']['new']);
        $this->assertSame($suggestion->id, $followUp->action['correction_of']);
        $this->assertSame('İzmir De Hurda Bakır Alımı', $suggestion->fresh()->action['proposal']['new']['seo_title'], 'the sent row keeps what was sent');
        $this->assertSame($followUp->id, $suggestion->fresh()->action['corrections'][0]['follow_up_id']);
        $this->assertSame(0, ExternalWriteAction::query()->count());

        $again = app(ResultCorrections::class)->correct('suggestion', $suggestion->id, 'proposal.seo_title', [], 'İzmir\'de Hurda Bakır Alımı', 'Bozuk başlık.');
        $this->assertSame($followUp->id, $again['follow_up_id'], 'the same correction is one row');
    }

    public function test_a_sent_article_becomes_an_update_row_for_its_wordpress_post(): void
    {
        $write = ExternalWriteAction::query()->create(['channel' => ExternalWriteAction::CHANNEL_WORDPRESS, 'action' => ExternalWriteAction::ACTION_ARTICLE_DRAFTS,
            'digital_asset_id' => $this->site->id, 'brand_id' => $this->brand->id, 'status' => 'succeeded', 'requested_by' => $this->admin->id,
            'request_payload' => [], 'result' => ['posts' => [['ok' => true, 'language' => 'tr', 'post_id' => 812]]]]);
        $suggestion = $this->content(['article_write_id' => $write->id, 'sent_languages' => ['tr']], ['status' => Suggestion::APPROVED]);

        $result = app(ResultCorrections::class)->correct('suggestion', $suggestion->id, 'article.html',
            [['find' => 'aynı kkalabilir', 'replace' => 'aynı kalabilir']], null, 'Yazım hatası.');

        $followUp = Suggestion::query()->findOrFail($result['follow_up_id']);
        $this->assertSame('content', $followUp->action_type);
        $this->assertSame('update', $followUp->action['kind']);
        $this->assertSame(812, $followUp->action['wp_post_id']);
        $this->assertStringContainsString('aynı kalabilir', $followUp->action['article']['html']);
        $this->assertStringContainsString('kkalabilir', $suggestion->fresh()->action['article']['html']);
        $this->assertSame(1, ExternalWriteAction::query()->count(), 'nothing new is sent');
    }

    public function test_task_result_finds_the_rows_a_batch_answer_wrote(): void
    {
        $page = $this->page('/a/', 'A');
        $first = $this->fields($page->id, ['meta_description' => 'Şablon açıklama.']);
        $task = $this->task(['suggestion_ids' => [$first->id, 99999]], ['pages' => [['id' => $first->id, 'meta_description' => 'Şablon açıklama.']]]);

        $targets = app(ResultCorrections::class)->targetsOf($task->fresh());

        $this->assertSame([$first->id], array_column($targets, 'id'));
        $this->assertArrayHasKey('proposal.meta_description', $targets[0]['fields']);
        $this->assertFalse($targets[0]['fields']['proposal.meta_description']['sent']);
    }

    public function test_corrections_never_add_link_targets(): void
    {
        $page = $this->page('/b/', 'B');
        $suggestion = $this->fields($page->id, ['internal_links' => [['anchor' => 'implant', 'url' => 'https://panorama.com.tr/implant/'], ['anchor' => 'kaplama', 'url' => 'https://panorama.com.tr/kaplama/']]]);

        $this->expectException(ValidationException::class);
        app(ResultCorrections::class)->correct('suggestion', $suggestion->id, 'proposal.internal_links', [], [['anchor' => 'yeni', 'url' => 'https://panorama.com.tr/yeni/']], 'Fazla link.');
    }

    public function test_extra_internal_links_can_be_dropped(): void
    {
        $page = $this->page('/b/', 'B');
        $suggestion = $this->fields($page->id, ['internal_links' => [['anchor' => 'implant', 'url' => 'https://panorama.com.tr/implant/'], ['anchor' => 'kaplama', 'url' => 'https://panorama.com.tr/kaplama/']]]);

        app(ResultCorrections::class)->correct('suggestion', $suggestion->id, 'proposal.internal_links', [], [['url' => 'https://panorama.com.tr/implant/']], 'Fazla link.');

        $this->assertSame([['anchor' => 'implant', 'url' => 'https://panorama.com.tr/implant/']], $suggestion->fresh()->action['proposal']['new']['internal_links']);
    }

    /** @param  array<string, mixed>  $action @param  array<string, mixed>  $extra */
    private function content(array $action = [], array $extra = []): Suggestion
    {
        return Suggestion::query()->create(array_merge([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => hash('sha256', 'content'.microtime()),
            'material_hash' => hash('sha256', 'x'), 'title' => 'İmplant sonrası ilk hafta', 'reason' => 'Kümenin sayfası yok.', 'priority' => 2,
            'action_type' => 'content', 'status' => Suggestion::OPEN, 'evidence' => [],
            'action' => array_merge(['site_id' => $this->site->id, 'kind' => 'new', 'page_type' => 'blog', 'article' => self::ARTICLE], $action),
        ], $extra));
    }

    /** @param  array<string, mixed>  $new @param  array<string, mixed>  $extra */
    private function fields(int $pageId, array $new, array $extra = []): Suggestion
    {
        return Suggestion::query()->create(array_merge([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.title_description', 'fingerprint' => hash('sha256', 'fields'.$pageId.json_encode($new)),
            'material_hash' => hash('sha256', 'x'), 'title' => 'Başlık', 'reason' => 'Başlık zayıf.', 'priority' => 2, 'page_id' => $pageId,
            'action_type' => 'title_description', 'status' => Suggestion::OPEN, 'evidence' => [],
            'action' => ['site_id' => $this->site->id, 'proposal' => ['kind' => 'fields', 'current' => [], 'new' => $new]],
        ], $extra));
    }

    /** @param  array<string, mixed>  $params @param  array<string, mixed>  $output */
    private function task(array $params, array $output): AiTask
    {
        return AiTask::query()->create(['operation' => 'site.seo_fields_batch', 'brand_id' => $this->brand->id, 'resume_key' => hash('sha256', 'run'.json_encode($params)),
            'sequence' => 1, 'input_hash' => 'h', 'status' => AiTask::CONSUMED, 'instructions' => 'x', 'input' => 'DATA_JSON {}', 'output_schema' => [],
            'output' => $output, 'completed_at' => now(), 'resume' => serialize(new RunSiteOperationJob($this->site->id, SiteOperations::SEO_FIELDS_BATCH, $params))]);
    }
}
