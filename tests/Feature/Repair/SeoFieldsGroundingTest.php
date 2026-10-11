<?php

namespace Tests\Feature\Repair;

use App\Ai\Agents\Site\SeoFieldsBatchAgent;
use App\Jobs\Site\RunSiteOperationJob;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Repair\RepairPreparer;
use App\Services\Repair\SeoFieldsBatch;
use App\Services\Repair\SiteAudit;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Site\SiteTestCase;

/**
 * yakup, 2026-10-11 (OPC: a dermal-filler Q&A page got a "dental fillings" description): title / description batches
 * hold up to 5 pages of one language and category, Q&A / blog pages get no brand services, a value must share the
 * page's own subject words, and a row that keeps failing stops after 3 attempts instead of being re-sent every hour.
 */
final class SeoFieldsGroundingTest extends SiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->enableAi();
    }

    public function test_a_pack_holds_one_language_and_category_and_a_qa_pack_gets_no_brand_services(): void
    {
        $qa = [];
        foreach (range(1, 6) as $i) {
            $qa[] = $this->fix($this->page('/sorular/soru-'.$i.'/', 'Dermal dolgu sorusu '.$i, ['wp_post_id' => 10 + $i, 'category' => 'sss', 'meta_description' => null]));
        }
        $service = $this->fix($this->page('/implant/', 'Ankara İmplant Tedavisi', ['wp_post_id' => 30, 'category' => 'hizmet', 'meta_description' => null]));
        $sent = [];
        SeoFieldsBatchAgent::fake(function (string $prompt) use (&$sent): array {
            $sent[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['pages' => []];
        });

        app(SeoFieldsBatch::class)->prepare($this->site, [...array_map(fn (Suggestion $s): int => $s->id, $qa), $service->id]);

        $this->assertCount(1, $sent);
        $this->assertCount(SeoFieldsBatch::PAGES_PER_CALL, $sent[0]['pages'], 'at most 5 pages per call');
        $this->assertSame(['sss'], array_values(array_unique(array_column($sent[0]['pages'], 'category'))), 'one category per call');
        $this->assertSame([], $sent[0]['brand']['services'] ?? [], 'a Q&A page is about its own question, not the brand services');
    }

    public function test_the_hourly_run_queues_jobs_per_site_language_and_category_in_fives(): void
    {
        foreach (range(1, 6) as $i) {
            $this->fix($this->page('/blog/yazi-'.$i.'/', 'Blog yazısı '.$i, ['wp_post_id' => 10 + $i, 'category' => 'blog']));
        }
        $this->fix($this->page('/en/blog/post/', 'Blog post', ['wp_post_id' => 40, 'category' => 'blog', 'language' => 'en']));
        $this->fix($this->page('/implant/', 'İmplant', ['wp_post_id' => 41, 'category' => 'hizmet']));

        $this->assertSame(8, app(RepairPreparer::class)->queue(batchOnly: true)['batch']);

        $sizes = [];
        Queue::assertPushed(RunSiteOperationJob::class, function (RunSiteOperationJob $job) use (&$sizes): bool {
            $sizes[] = count((array) ($job->params['suggestion_ids'] ?? []));

            return true;
        });
        sort($sizes);
        $this->assertSame([1, 1, 1, 5], $sizes);
    }

    public function test_an_off_topic_value_is_refused_and_after_three_failures_the_row_stops(): void
    {
        $page = $this->page('/sorular/dermal-dolgu-nedir/', 'Dermal dolgu nedir?', ['wp_post_id' => 50, 'category' => 'sss', 'meta_description' => null,
            'content_text' => 'Dermal dolgu, yüzdeki hacim kaybını gidermek için cilt altına uygulanan bir işlemdir. Hyalüronik asit içerir.']);
        $suggestion = $this->fix($page, ['meta_description:missing']);
        $answer = 'Diş dolgusu tedavisinde kompozit ve porselen seçenekleri; çürük dişleriniz için muayene randevusu alabilirsiniz.';
        SeoFieldsBatchAgent::fake(fn (): array => ['pages' => [['id' => $suggestion->id, 'seo_title' => null, 'meta_description' => $answer]]]);

        $this->assertSame(['status' => 'ready', 'prepared' => 0, 'skipped' => 1], app(SeoFieldsBatch::class)->prepare($this->site, [$suggestion->id]));
        $action = $suggestion->fresh()->action;
        $this->assertArrayNotHasKey('proposal', $action);
        $this->assertSame(1, $action['proposal_attempts']);
        $this->assertStringContainsString('açıklama: sayfanın konusuyla örtüşmüyor', $action['proposal_error']);
        $this->assertSame(1, SeoFieldsBatch::waitingQuery()->count(), 'tried again on the next run');

        app(SeoFieldsBatch::class)->prepare($this->site, [$suggestion->id]);
        app(SeoFieldsBatch::class)->prepare($this->site, [$suggestion->id]);

        $action = $suggestion->fresh()->action;
        $this->assertSame(3, $action['proposal_attempts']);
        $this->assertStringStartsWith('Otomatik hazırlık 3 denemede kurallardan geçmedi', $action['proposal_blocked']);
        $this->assertSame(0, SeoFieldsBatch::waitingQuery()->count(), 'no more hourly AI calls');
    }

    public function test_a_value_about_the_page_own_subject_passes(): void
    {
        $page = $this->page('/sorular/dermal-dolgu-nedir/', 'Dermal dolgu nedir?', ['wp_post_id' => 50, 'category' => 'sss', 'meta_description' => null,
            'content_text' => 'Dermal dolgu, yüzdeki hacim kaybını gidermek için cilt altına uygulanan bir işlemdir.']);
        $suggestion = $this->fix($page, ['meta_description:missing']);
        $answer = 'Dermal dolgu nedir, yüzdeki hacim kaybında nasıl uygulanır ve kimlere uygundur; bu sayfada sade bir dille anlatılıyor.';
        SeoFieldsBatchAgent::fake(fn (): array => ['pages' => [['id' => $suggestion->id, 'seo_title' => null, 'meta_description' => $answer]]]);

        $this->assertSame(1, app(SeoFieldsBatch::class)->prepare($this->site, [$suggestion->id])['prepared']);
        $this->assertSame($answer, $suggestion->fresh()->action['proposal']['new']['meta_description']);
    }

    public function test_the_migration_clears_waiting_off_topic_proposals_only(): void
    {
        $page = $this->page('/sorular/dermal-dolgu-nedir/', 'Dermal dolgu nedir?', ['wp_post_id' => 50, 'category' => 'sss']);
        $offTopic = ['seo_title' => null, 'meta_description' => 'Diş dolgusu tedavisinde kompozit ve porselen seçenekleri hakkında bilgi alın.'];
        $onTopic = ['meta_description' => 'Dermal dolgu nedir ve yüzde nasıl uygulanır, bu sayfada anlatılıyor.'];
        $open = $this->fix($page, ['meta_description:missing'], ['proposal' => ['kind' => 'fields', 'new' => $offTopic]]);
        $approved = $this->fix($page, ['meta_description:missing'], ['proposal' => ['kind' => 'fields', 'new' => $offTopic]], Suggestion::APPROVED);
        $fine = $this->fix($page, ['meta_description:missing'], ['proposal' => ['kind' => 'fields', 'new' => $onTopic]]);

        $this->assertSame(1, SeoFieldsBatch::clearUngrounded());

        $this->assertArrayNotHasKey('proposal', $open->fresh()->action);
        $this->assertArrayHasKey('proposal', $approved->fresh()->action);
        $this->assertArrayHasKey('proposal', $fine->fresh()->action);
    }

    /**
     * @param  list<string>  $problems
     * @param  array<string, mixed>  $extra
     */
    private function fix(Page $page, array $problems = ['meta_description:missing'], array $extra = [], string $status = Suggestion::OPEN): Suggestion
    {
        return Suggestion::query()->create(['brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'repair.seo_fields',
            'fingerprint' => Str::random(40), 'material_hash' => 'x', 'title' => 'Başlık ve açıklamayı düzelt: '.$page->path, 'reason' => 'r',
            'priority' => 3, 'action_type' => SiteAudit::TYPE, 'target_type' => 'page', 'target_id' => $page->id, 'page_id' => $page->id,
            'status' => $status, 'action' => ['site_id' => $this->site->id, 'problems' => $problems] + $extra, 'evidence' => []]);
    }
}
