<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\ClusterAiQueriesAgent;
use App\Ai\Agents\Site\ClusterGapsAgent;
use App\Ai\Agents\Site\ClusterMatchAgent;
use App\Models\Cluster;
use App\Services\Site\ClusterAudit;
use App\Services\Site\SiteOperations;

/**
 * Eşleştir reads only what changed: a pass with the same clusters and page texts makes no AI call; a new cluster asks
 * again only for its own service; a changed page text re-reads that page; the operator's «Eşleştir» reads everything.
 */
final class ClusterAuditIncrementalTest extends SiteTestCase
{
    /** @var array{match: list<array<string, mixed>>, gaps: list<array<string, mixed>>} */
    private array $calls = ['match' => [], 'gaps' => []];

    public function test_a_pass_asks_the_ai_only_for_the_service_groups_and_pages_whose_inputs_changed(): void
    {
        $this->enableAi();
        $implantPage = $this->page('/implant/', 'İmplant Tedavisi', ['category' => 'hizmet', 'content_text' => 'İmplant tedavisi adım adım anlatılır.']);
        $zirkonPage = $this->page('/zirkonyum/', 'Zirkonyum Kaplama', ['category' => 'hizmet', 'content_text' => 'Zirkonyum kaplama nedir.']);
        $implant = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi']);
        $zirkon = $this->cluster($this->zirkonyum, 'Zirkonyum kaplama', ['zirkonyum kaplama']);
        $this->fakeAi([$implant->id => $implantPage->id, $zirkon->id => $zirkonPage->id]);
        $audit = app(ClusterAudit::class);

        $first = $audit->run($this->site);
        $this->assertSame('ready', $first['status']);
        $this->assertArrayNotHasKey('skipped', $first);
        $this->assertCount(2, $this->calls['match'], 'one match call per service');
        $this->assertCount(2, $this->calls['gaps'], 'one gaps call per matched page');

        // Nothing changed: no AI call at all.
        $this->resetCalls();
        $second = $audit->run($this->site);
        $this->assertSame(['ready', 4], [$second['status'], $second['skipped']]);
        $this->assertSame([[], []], [$this->calls['match'], $this->calls['gaps']]);
        $this->assertSame('tamam · değişmeyen 4 bölüm yeniden okunmadı', SiteOperations::line($second));

        // A new approved implant cluster: only the implant group is matched again; the zirkonyum group is not.
        $this->resetCalls();
        $aftercare = $this->cluster($this->implant, 'İmplant bakımı', ['implant bakımı']);
        $this->fakeAi([$implant->id => $implantPage->id, $aftercare->id => $implantPage->id]);
        $audit->run($this->site);
        $this->assertCount(1, $this->calls['match']);
        $this->assertEqualsCanonicalizing([$implant->id, $aftercare->id], array_column($this->calls['match'][0]['clusters'], 'cluster_id'));
        $this->assertSame([$implantPage->url], array_column(array_column($this->calls['gaps'], 'page'), 'url'), 'the page that got a new cluster only');

        // The zirkonyum page text changed: its group and its page are read again, nothing else.
        $this->resetCalls();
        $zirkonPage->forceFill(['content_text' => 'Zirkonyum kaplama fiyatları ve süreci.', 'content_hash' => hash('sha256', 'yeni')])->save();
        $this->fakeAi([$zirkon->id => $zirkonPage->id]);
        $audit->run($this->site);
        $this->assertSame([[$zirkon->id]], array_map(fn (array $call): array => array_column($call['clusters'], 'cluster_id'), $this->calls['match']));
        $this->assertSame([$zirkonPage->url], array_column(array_column($this->calls['gaps'], 'page'), 'url'));

        // The operator's «Eşleştir»: everything is read again.
        $this->resetCalls();
        $this->fakeAi([$implant->id => $implantPage->id, $aftercare->id => $implantPage->id, $zirkon->id => $zirkonPage->id]);
        ClusterAudit::forceNext((int) $this->site->id);
        $forced = $audit->run($this->site);
        $this->assertArrayNotHasKey('skipped', $forced);
        $this->assertCount(2, $this->calls['match']);
        $this->assertCount(2, $this->calls['gaps']);
    }

    /** @param  array<int, int>  $pages  cluster id → page id the AI names */
    private function fakeAi(array $pages): void
    {
        ClusterAiQueriesAgent::fake(fn (): array => ['clusters' => array_map(fn (int $id): array => ['cluster_id' => $id, 'questions' => ['Bu tedavi ne kadar sürer?']],
            Cluster::query()->pluck('id')->map(fn ($id): int => (int) $id)->all())]);
        ClusterMatchAgent::fake(function (string $prompt) use ($pages): array {
            $data = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);
            $this->calls['match'][] = $data;

            return ['clusters' => array_map(fn (array $c): array => ['cluster_id' => $c['cluster_id'], 'page_id' => $pages[$c['cluster_id']] ?? null,
                'coverage' => 'partial', 'reason' => 'Sayfa konuyu anlatıyor.'], $data['clusters'])];
        });
        ClusterGapsAgent::fake(function (string $prompt): array {
            $data = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);
            $this->calls['gaps'][] = $data;

            return ['clusters' => array_map(fn (array $c): array => ['cluster_id' => $c['cluster_id'], 'coverage' => 'partial',
                'gaps' => [['text' => 'Tedavi süresi yanıtlanmamış', 'kind' => 'soru']]], $data['clusters'])];
        });
    }

    private function resetCalls(): void
    {
        $this->calls = ['match' => [], 'gaps' => []];
    }
}
