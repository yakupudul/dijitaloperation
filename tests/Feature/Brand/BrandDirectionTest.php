<?php

namespace Tests\Feature\Brand;

use App\Ai\Agents\Site\BrandDirectionFitAgent;
use App\Jobs\Brand\ApplyBrandDirectionJob;
use App\Models\BrandClusterPage;
use App\Models\BrandDirectionChange;
use App\Models\Suggestion;
use App\Services\Brand\BrandDirection;
use App\Services\Site\SiteSuggestionTypes;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Site\SiteTestCase;

/**
 * Marka rotası (yakup, 2026-11-09: "markanın rotası değiştiğinde diğer çalışan şeyler değişmiyor"): a changed direction
 * realigns the brand's clusters and unsent content ideas; the old ones are set aside, never deleted.
 */
final class BrandDirectionTest extends SiteTestCase
{
    public function test_first_look_is_a_baseline_and_an_unchanged_brand_writes_nothing(): void
    {
        Queue::fake();
        $direction = app(BrandDirection::class);

        $first = $direction->detect($this->brand);
        $this->assertSame(BrandDirectionChange::BASELINE, $first->magnitude);
        $this->assertNotNull($first->applied_at);
        $this->assertNull($direction->detect($this->brand));
        Queue::assertNothingPushed();
    }

    public function test_half_of_the_services_changing_is_a_new_direction(): void
    {
        Queue::fake();
        $direction = app(BrandDirection::class);
        $direction->detect($this->brand);

        $this->zirkonyumOffering->forceFill(['status' => 'archived'])->save();
        $minor = $direction->detect($this->brand);
        $this->assertSame(BrandDirectionChange::MINOR, $minor->magnitude, 'one of two services left: 1/2 overlap is not below the line');
        $this->assertSame([], $minor->added);
        $this->assertCount(1, $minor->removed);

        $this->assertSame(BrandDirectionChange::MINOR, BrandDirection::magnitude(['services' => ['A', 'B']], ['services' => ['A', 'B', 'C']]));
        $this->assertSame(BrandDirectionChange::MAJOR, BrandDirection::magnitude(['services' => ['A', 'B', 'C']], ['services' => ['A', 'D', 'E', 'F']]));
        Queue::assertPushed(ApplyBrandDirectionJob::class);
    }

    public function test_a_major_change_sets_aside_what_no_longer_fits_and_keeps_the_rest(): void
    {
        $this->enableAi();
        Queue::fake();
        $fits = $this->cluster($this->implant, 'Ankara implant', ['ankara implant']);
        $old = $this->cluster($this->implant, 'Implant toptan satış', ['implant toptan']);
        foreach ([$fits, $old] as $cluster) {
            BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id, 'state' => 'no_page']);
        }
        $idea = fn (string $title, array $action = [], string $status = Suggestion::OPEN): Suggestion => Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => hash('sha256', $title), 'material_hash' => 'x',
            'title' => $title, 'reason' => 'r', 'priority' => 4, 'action_type' => SiteSuggestionTypes::CONTENT, 'status' => $status, 'cluster_id' => $fits->id,
            'action' => ['site_id' => $this->site->id, 'kind' => 'new', 'service' => 'İmplant'] + $action, 'evidence' => []]);
        $keep = $idea('İmplant tedavisi kaç seansta biter?');
        $drop = $idea('Diş deposu nasıl açılır?');
        $drafted = $idea('İmplant fiyatları neye göre değişir?', ['article' => ['html' => '<p>eski</p>']], Suggestion::APPROVED);
        $sent = $idea('İmplant sonrası beslenme', ['article_write_id' => 9], Suggestion::APPROVED);
        BrandDirectionFitAgent::fake(function (string $prompt) use ($old, $drop): array {
            $data = json_decode(substr($prompt, strpos($prompt, '{')), true);

            return ['items' => array_map(fn (array $i): array => ['kind' => $i['kind'], 'id' => $i['id'], 'reason' => 'Eski rota.',
                'verdict' => ($i['kind'] === 'cluster' && $i['id'] === $old->id) || ($i['kind'] === 'idea' && $i['id'] === $drop->id) ? 'off_direction' : 'fits'], $data['items'])];
        });

        $change = app(BrandDirection::class)->detect($this->brand, force: true, trigger: 'operator', reason: 'Yalnız kliniklere');
        $this->assertSame(BrandDirectionChange::MAJOR, $change->magnitude);
        $this->travel(1)->seconds();
        $result = app(BrandDirection::class)->apply($change->fresh());

        $this->assertNotNull($change->fresh()->applied_at);
        $this->assertSame(1, $result['clusters_set_aside']);
        $this->assertSame(1, $result['ideas_closed']);
        $this->assertSame(1, $result['drafts_closed']);
        $row = BrandClusterPage::query()->where('cluster_id', $old->id)->sole();
        $this->assertTrue($row->excluded);
        $this->assertSame('rota', $row->decided_by);
        $this->assertStringStartsWith('Marka rotası dışında', $row->reason);
        $this->assertFalse(BrandClusterPage::query()->where('cluster_id', $fits->id)->sole()->excluded);
        $this->assertSame(Suggestion::OPEN, $keep->fresh()->status);
        $this->assertSame(Suggestion::DISMISSED, $drop->fresh()->status);
        $this->assertSame(Suggestion::DISMISSED, $drafted->fresh()->status, 'a draft written on the old direction is not sent');
        $this->assertSame(Suggestion::APPROVED, $sent->fresh()->status, 'what already went to the site is left alone');

        // The mapper keeps the set-aside row as it is.
        app(\App\Services\Site\ClusterPageMapper::class)->refresh($this->site, judge: false);
        $this->assertTrue($row->fresh()->excluded);
    }
}
