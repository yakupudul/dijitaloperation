<?php

namespace Tests\Feature\Repair;

use App\Ai\Agents\Site\SeoFieldsBatchAgent;
use App\Jobs\ApproveRepairRowsJob;
use App\Models\CoreConnection;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Repair\RepairDesk;
use App\Services\Repair\SeoFieldsBatch;
use App\Services\Repair\SiteAudit;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Site\SiteTestCase;

/**
 * Özet (yakup, 2026-11-02): the WordPress Excerpt is checked where the site shows it (blog posts; a post type whose
 * pages mostly carry one), written by the title / description batch and sent with the same one-click approval.
 */
final class ExcerptRepairTest extends SiteTestCase
{
    private int $post = 100;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake()->except([ApproveRepairRowsJob::class]);
        $this->enableAi();
    }

    public function test_the_audit_flags_missing_and_duplicate_excerpts_only_where_the_site_shows_them(): void
    {
        $empty = $this->wpPage('/blog/implant-sonrasi/', 'Implant sonrası beslenme önerileri', 'post', '');
        $unknown = $this->wpPage('/blog/dis-tasi/', 'Diş taşı temizliği ne sıklıkla yapılır', 'post', null);
        $twinA = $this->wpPage('/blog/beyazlatma/', 'Diş beyazlatma kimlere uygulanır', 'post', 'Diş beyazlatmanın kimlere uygulandığı ve sonrasında dikkat edilmesi gerekenler bu yazıda anlatılıyor.');
        $twinB = $this->wpPage('/blog/beyazlatma-2/', 'Diş beyazlatma sonrası bakım rehberi', 'post', 'Diş beyazlatmanın kimlere uygulandığı ve sonrasında dikkat edilmesi gerekenler bu yazıda anlatılıyor.');
        $plainPage = $this->wpPage('/hakkimizda/', 'Hakkımızda ve klinik ekibimiz Ankara', 'page', '');

        app(SiteAudit::class)->audit($this->site);

        $rows = Suggestion::query()->where('action_type', SiteAudit::TYPE)->get()->keyBy('page_id');
        $this->assertSame(['excerpt:missing'], $rows[$empty->id]->action['problems']);
        $this->assertSame(4, $rows[$empty->id]->priority, 'never above a title or description fix');
        $this->assertStringStartsWith('Özeti yaz: ', $rows[$empty->id]->title);
        $this->assertSame(['excerpt:duplicate'], $rows[$twinA->id]->action['problems']);
        $this->assertTrue($rows->has($twinB->id));
        $this->assertFalse($rows->has($unknown->id), 'connector < 1.36.0: the field is not known');
        $this->assertFalse($rows->has($plainPage->id), 'this site shows no page excerpts');
    }

    public function test_a_post_type_whose_pages_mostly_carry_an_excerpt_is_checked_too(): void
    {
        foreach (['implant', 'zirkonyum', 'ortodonti'] as $i => $slug) {
            $this->wpPage('/'.$slug.'/', ucfirst($slug).' tedavisi Ankara Panorama Diş', 'page', ucfirst($slug).' tedavisinin adımları, süresi ve kimlere uygulandığı bu sayfada kısaca anlatılıyor, sayfa '.$i.'.');
        }
        $empty = $this->wpPage('/kopru/', 'Diş köprüsü tedavisi Ankara Panorama', 'page', '');

        app(SiteAudit::class)->audit($this->site);

        $this->assertSame(['excerpt:missing'], Suggestion::query()->where('page_id', $empty->id)->sole()->action['problems']);
        $this->assertSame(1, Suggestion::query()->where('action_type', SiteAudit::TYPE)->count());
    }

    public function test_the_batch_writes_the_excerpt_by_the_rules_and_approval_sends_it(): void
    {
        CoreConnection::factory()->create([
            'digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://panorama.com.tr/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.36.0'],
        ]);
        $page = $this->wpPage('/blog/implant-sonrasi/', 'Implant sonrası beslenme önerileri', 'post', '', [
            'h1' => 'Implant sonrası beslenme', 'content_text' => 'Implant sonrası ilk günlerde yumuşak ve ılık besinler tercih edilir. Beslenme düzeni iyileşmeyi destekler.']);
        $short = $this->wpPage('/blog/dolgu/', 'Diş dolgusu sonrası nelere dikkat edilir', 'post', '', ['h1' => 'Diş dolgusu sonrası']);
        $rows = [$this->fix($page), $this->fix($short)];
        $excerpt = 'Implant sonrası beslenme: ilk günlerde hangi yumuşak besinlerin tercih edildiği ve beslenme düzeninin iyileşmeye etkisi.';
        $sent = [];
        SeoFieldsBatchAgent::fake(function (string $prompt) use (&$sent, $rows, $excerpt): array {
            $sent[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['pages' => [
                ['id' => $rows[0]->id, 'seo_title' => null, 'meta_description' => null, 'excerpt' => $excerpt],
                ['id' => $rows[1]->id, 'seo_title' => null, 'meta_description' => null, 'excerpt' => 'Diş dolgusu sonrası.'],
            ]];
        });

        $this->assertSame(['status' => 'ready', 'prepared' => 1, 'skipped' => 1], app(SeoFieldsBatch::class)->prepare($this->site, array_map(fn (Suggestion $s): int => $s->id, $rows)));

        $this->assertSame(['excerpt' => 'missing'], $sent[0]['pages'][0]['fix']);
        $this->assertSame(['excerpt' => $excerpt], $rows[0]->fresh()->action['proposal']['new']);
        $this->assertStringContainsString('özet: uzunluk', (string) $rows[1]->fresh()->action['proposal_error'], 'too short: refused');

        $desk = app(RepairDesk::class);
        $row = $desk->rows()->keyBy('id')[$rows[0]->id];
        $this->assertSame(['Özet: —'], $row['before']);
        $this->assertSame(['Özet: '.$excerpt], $row['after']);
        $this->assertSame(['excerpt'], $row['editable']);

        $desk->approve([$rows[0]->id], $this->admin);

        $change = ExternalWriteAction::query()->where('suggestion_id', $rows[0]->id)->sole()->request_payload['changes'][0];
        $this->assertSame(['type' => 'excerpt', 'object_id' => $page->wp_post_id, 'value' => $excerpt], array_intersect_key($change, array_flip(['type', 'object_id', 'value'])));
    }

    public function test_the_connector_reports_and_writes_the_excerpt(): void
    {
        $root = base_path('connectors/wordpress/moxdop-connector/includes/');
        $this->assertStringContainsString("'excerpt' => (string) \$post->post_excerpt", (string) file_get_contents($root.'class-moxdop-connector-rest-controller.php'));
        $fixes = (string) file_get_contents($root.'class-moxdop-connector-fixes.php');
        $this->assertSame(4, substr_count($fixes, "case 'excerpt':"), 'target, clean, read and write');
        $this->assertStringContainsString("'post_excerpt' => wp_slash((string) \$value)", $fixes);
    }

    /** @param  array<string, mixed>  $extra */
    private function wpPage(string $path, string $title, string $type, ?string $excerpt, array $extra = []): Page
    {
        $this->post++;

        return $this->page($path, $title, array_merge(['wp_post_id' => $this->post, 'wp_post_type' => $type, 'title_source' => 'seo', 'category' => 'blog', 'excerpt' => $excerpt,
            'meta_description' => $title.' hakkında merak edilenler, uygulama adımları ve randevu bilgisi bu sayfada anlatılıyor.'], $extra));
    }

    private function fix(Page $page): Suggestion
    {
        return Suggestion::query()->create(['brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'repair.seo_fields',
            'fingerprint' => Str::random(40), 'material_hash' => 'x', 'title' => 'Özeti yaz: '.$page->path, 'reason' => 'r',
            'priority' => 4, 'action_type' => SiteAudit::TYPE, 'target_type' => 'page', 'target_id' => $page->id, 'page_id' => $page->id,
            'status' => Suggestion::OPEN, 'action' => ['site_id' => $this->site->id, 'problems' => ['excerpt:missing']], 'evidence' => []]);
    }
}
