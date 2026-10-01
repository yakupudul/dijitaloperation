<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\ApplyChangeAgent;
use App\Ai\Agents\Site\ClusterAiQueriesAgent;
use App\Ai\Agents\Site\ClusterGapsAgent;
use App\Ai\Agents\Site\ClusterMatchAgent;
use App\Ai\Agents\Site\WeeklyContentAgent;
use App\Livewire\Operator\Website\V2\ClustersPagesTab;
use App\Models\BrandClusterPage;
use App\Models\Suggestion;
use App\Services\Site\ClusterAudit;
use App\Services\Site\SiteOperations;
use Livewire\Livewire;

/**
 * "Kümeleri içerikle karşılaştır": AI questions per cluster, AI match of clusters to page content (candidates by word
 * overlap, validated ids, locked page kept), gaps per matched page (service areas only for local needs), and the row
 * actions "Eksikleri gider" (suggestion + AI ile yap with the cluster pack) and "Konu üret" (one content item).
 */
final class ClusterAuditTest extends SiteTestCase
{
    public function test_clusters_are_matched_to_page_content_gaps_are_listed_and_the_row_actions_prepare_the_fix_and_the_topic(): void
    {
        $this->enableAi();
        $implantPage = $this->page('/implant/', 'İmplant Tedavisi', ['category' => 'hizmet', 'h1' => 'İmplant tedavisi', 'headings' => ['İmplant nasıl yapılır'],
            'content_text' => 'İmplant tedavisi adım adım anlatılır. Kemik yapısı incelenir.']);
        $this->page('/hakkimizda/', 'Hakkımızda', ['category' => 'kurumsal', 'content_text' => 'İmplant ve kaplama yapıyoruz.']);
        $this->page('/zirkonyum/', 'Zirkonyum Kaplama', ['category' => 'hizmet', 'content_text' => 'Zirkonyum kaplama nedir.']);
        $treatment = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi', 'implant nasıl yapılır']);
        $aftercare = $this->cluster($this->implant, 'İmplant sonrası bakım', ['implant sonrası ne yenir'], [], 'informational');
        $treatment->forceFill(['approved' => true])->save();
        $aftercare->forceFill(['approved' => true])->save();

        $calls = ['questions' => [], 'match' => [], 'gaps' => []];
        ClusterAiQueriesAgent::fake(function (string $prompt) use (&$calls, $treatment, $aftercare): array {
            $calls['questions'][] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['clusters' => [
                ['cluster_id' => $treatment->id, 'questions' => ['{bölge} implant için hangi kliniği önerirsin?', 'İmplant mı köprü mü daha iyi?', 'x']],
                ['cluster_id' => $aftercare->id, 'questions' => ['İmplanttan sonra ne zaman yemek yiyebilirim?']],
            ]];
        });
        ClusterMatchAgent::fake(function (string $prompt) use (&$calls, $treatment, $aftercare, $implantPage): array {
            $calls['match'][] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['clusters' => [
                ['cluster_id' => $treatment->id, 'page_id' => $implantPage->id, 'coverage' => 'partial', 'reason' => 'Sayfa tedaviyi anlatıyor ama süre ve fiyat etkenleri yok.'],
                ['cluster_id' => $aftercare->id, 'page_id' => 99999, 'coverage' => 'full', 'reason' => 'Uydurma sayfa.'],
            ]];
        });
        ClusterGapsAgent::fake(function (string $prompt) use (&$calls, $treatment): array {
            $calls['gaps'][] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['clusters' => [['cluster_id' => $treatment->id, 'coverage' => 'partial', 'gaps' => [
                ['text' => 'Tedavinin ne kadar sürdüğü yanıtlanmamış', 'kind' => 'soru'],
                ['text' => 'Çankaya şubesinde hizmet verildiği belirtilmemiş', 'kind' => 'lokasyon'],
                ['text' => '-', 'kind' => 'soru'],
                ['text' => 'Geçersiz tür', 'kind' => 'baska'],
            ]]]];
        });

        $result = app(ClusterAudit::class)->run($this->site);

        $this->assertSame(['status' => 'ready', 'clusters' => 2, 'matched' => 1, 'gaps' => 2], $result);
        $this->assertCount(1, $calls['questions'], 'one AI-questions call per service');
        $this->assertSame(['{bölge} implant için hangi kliniği önerirsin?', 'İmplant mı köprü mü daha iyi?'], $treatment->fresh()->ai_queries, 'too-short questions dropped');
        $this->assertTrue($calls['questions'][0]['clusters'][0]['local']);
        $this->assertFalse($calls['questions'][0]['clusters'][1]['local']);
        $sentPages = array_column($calls['match'][0]['pages'], 'url');
        $this->assertContains('https://panorama.com.tr/implant/', $sentPages);
        $this->assertNotContains('https://panorama.com.tr/hakkimizda/', $sentPages, 'corporate pages are no candidates');

        $rows = BrandClusterPage::query()->get()->keyBy('cluster_id');
        $treatmentRow = $rows[$treatment->id];
        $this->assertSame([$implantPage->id, 'partial', 'thin_coverage', 'ai'], [$treatmentRow->page_id, $treatmentRow->coverage, $treatmentRow->state, $treatmentRow->decided_by]);
        $this->assertSame(['Tedavinin ne kadar sürdüğü yanıtlanmamış', 'Çankaya şubesinde hizmet verildiği belirtilmemiş'], array_column($treatmentRow->gaps, 'text'));
        $this->assertSame(['Çankaya'], $calls['gaps'][0]['clusters'][0]['service_areas'], 'a commercial cluster gets the service areas');
        $this->assertSame(['Çankaya implant için hangi kliniği önerirsin?', 'İmplant mı köprü mü daha iyi?'], $calls['gaps'][0]['clusters'][0]['ai_queries'], '{bölge} → the brand\'s area');
        $aftercareRow = $rows[$aftercare->id];
        $this->assertSame([null, 'none', 'no_page'], [$aftercareRow->page_id, $aftercareRow->coverage, $aftercareRow->state], 'an unknown page id is never stored');
        $this->assertSame([], ClusterAudit::serviceAreas($aftercare, $this->brand), 'informational needs get no areas');

        // Screen: coverage, gaps, the two row actions.
        $page = Livewire::test(ClustersPagesTab::class, ['assetId' => $this->site->id])
            ->assertSee('Eksikleri gör (2)')->assertSee('Çankaya implant için hangi kliniği önerirsin?')
            ->assertSeeHtml('data-fix-gaps')->assertSeeHtml('data-cluster-topic');

        $applied = [];
        ApplyChangeAgent::fake(function (string $prompt) use (&$applied): array {
            $applied[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['seo_title' => null, 'meta_description' => null, 'internal_links' => [], 'schema_json' => null, 'html' => null, 'note' => 'x'];
        });
        $page->call('fixGaps', $treatmentRow->id)->assertSee('Öneriler sekmesinde');
        $suggestion = Suggestion::query()->where('decision_key', 'site.cluster_gaps')->sole();
        $this->assertSame(['missing_topic', $implantPage->id, $treatment->id], [$suggestion->action_type, $suggestion->page_id, $suggestion->cluster_id]);
        $this->assertSame(['Tedavinin ne kadar sürdüğü yanıtlanmamış', 'Çankaya şubesinde hizmet verildiği belirtilmemiş'], $applied[0]['cluster']['gaps']);
        $this->assertSame(['Çankaya'], $applied[0]['cluster']['service_areas']);

        $planned = [];
        WeeklyContentAgent::fake(function (string $prompt) use (&$planned, $aftercare): array {
            $planned[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['items' => [['title' => 'İmplant sonrası beslenme rehberi', 'kind' => 'new', 'cluster_id' => $aftercare->id, 'page_type' => 'blog',
                'target_url' => null, 'outline' => ['İlk gün', 'İlk hafta'], 'questions' => ['İmplanttan sonra ne zaman yemek yiyebilirim?'], 'reason' => 'Sitede bu konu yok.']]];
        });
        $page->call('clusterTopic', $aftercareRow->id)->assertSee('İçerik sekmesinde');
        $this->assertSame(1, $planned[0]['capacity']);
        $this->assertSame([$aftercare->id], array_column($planned[0]['clusters'], 'cluster_id'));
        $this->assertSame(['İmplanttan sonra ne zaman yemek yiyebilirim?'], $planned[0]['clusters'][0]['ai_questions']);
        $this->assertTrue(Suggestion::query()->where('cluster_id', $aftercare->id)->where('title', 'İmplant sonrası beslenme rehberi')->exists());
        $this->assertNotNull(SiteOperations::status($this->site->id, SiteOperations::CLUSTER_TOPIC, ['row_id' => $aftercareRow->id]));
    }
}
