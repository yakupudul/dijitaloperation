<?php

namespace Tests\Feature\SeoTasks;

use App\Ai\Agents\SeoTaskContentPlannerAgent;
use App\Enums\SeoTaskStatus;
use App\Jobs\RunSeoPlanJob;
use App\Livewire\Demo\Website\OverviewPage;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Livewire\Operator\Seo\SeoTasksIndex;
use App\Livewire\Operator\Seo\SeoTasksPanel;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\IntelligenceCore\IntelligencePageIdentity;
use App\Models\IntelligenceProjection\WebsiteIntelligenceProjectionRun;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\ModuleRegistry;
use App\Models\SeoPlan;
use App\Models\SeoTask;
use App\Models\ServicePageAssignment;
use App\Models\User;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\SeoTasks\SeoPlanInputCollector;
use App\Services\SeoTasks\SeoPlanRunner;
use App\Services\SeoTasks\SeoTaskRuleEngine;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * End-to-end: stored GSC rows + page inventory + findings + offerings → seo_plans / seo_tasks / assignments,
 * then the operator surfaces (global list, website tab, brand star) and the optional LLM merge.
 */
final class SeoPlanRunTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        config(['moxdop-seo-tasks.llm.enabled' => false]);
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        ModuleRegistry::query()->updateOrCreate(['module_id' => 'website'], ['enabled' => true]);
        Http::preventStrayRequests();
    }

    public function test_queue_dispatches_job_and_run_writes_plan_tasks_and_assignments(): void
    {
        Bus::fake();
        [$website, $implant] = $this->fixture();

        $plan = app(SeoPlanRunner::class)->queue($website, $this->admin);
        Bus::assertDispatched(RunSeoPlanJob::class, fn (RunSeoPlanJob $job): bool => $job->planId === $plan->id);
        $this->assertSame(SeoPlan::STATUS_QUEUED, $plan->status);
        $this->assertSame($plan->id, app(SeoPlanRunner::class)->queue($website, $this->admin)->id, 'pending plan is reused');

        $done = app(SeoPlanRunner::class)->run($plan->id);
        $this->assertSame(SeoPlan::STATUS_COMPLETED, $done->status);
        $this->assertStringContainsString('görev', (string) $done->summary_text);
        $this->assertTrue((bool) data_get($done->input_summary, 'gsc.available'));
        $this->assertSame('disabled', data_get($done->llm_summary, 'skipped_reason'));

        $tasks = SeoTask::query()->where('digital_asset_id', $website->id)->get();
        $this->assertGreaterThanOrEqual(4, $tasks->where('type->value', 'create')->count() ?: $tasks->filter(fn (SeoTask $t): bool => $t->type->value === 'create')->count());
        $this->assertTrue($tasks->contains(fn (SeoTask $t): bool => $t->type->value === 'fix' && str_contains($t->rule_id, 'title-missing')));
        $this->assertTrue($tasks->contains(fn (SeoTask $t): bool => $t->type->value === 'strengthen' && $t->target_url === 'https://example.test/implant/'));
        $this->assertTrue($tasks->every(fn (SeoTask $t): bool => $t->customer_id === $website->brand->customer_id && $t->first_seen_plan_id === $plan->id));

        $assignment = ServicePageAssignment::query()->where('brand_offering_id', $implant->id)->first();
        $this->assertNotNull($assignment);
        $this->assertSame(ServicePageAssignment::STATUS_ASSIGNED, $assignment->status);
        $this->assertSame('https://example.test/implant/', $assignment->page_url);
    }

    public function test_second_run_updates_keys_keeps_resolved_and_marks_stale(): void
    {
        [$website] = $this->fixture();
        $runner = app(SeoPlanRunner::class);
        $first = $runner->run($runner->queue($website, $this->admin)->id);

        $strengthen = SeoTask::query()->where('type', 'strengthen')->firstOrFail();
        $strengthen->forceFill(['status' => SeoTaskStatus::Done->value, 'resolved_at' => now()])->save();
        $fix = SeoTask::query()->where('rule_id', 'like', 'finding:%')->firstOrFail();
        Finding::query()->whereKey($fix->evidence['finding_id'])->update(['status' => Finding::STATUS_RESOLVED]);

        $second = $runner->run($runner->queue($website, $this->admin)->id);
        $this->assertSame($first->version + 1, $second->version);

        $this->assertSame(SeoTaskStatus::Done, $strengthen->fresh()->status, 'done tasks are never reopened by a run');
        $this->assertSame(SeoTaskStatus::Stale, $fix->fresh()->status, 'task not produced any more → stale');
        $open = SeoTask::query()->where('digital_asset_id', $website->id)->where('status', 'open')->get();
        $this->assertTrue($open->every(fn (SeoTask $t): bool => $t->last_seen_plan_id === $second->id));
        $this->assertGreaterThan(0, data_get($second->result_summary, 'updated'));
    }

    public function test_operator_surfaces_list_act_and_answer_questions(): void
    {
        [$website, $implant, $brand] = $this->fixture();
        $runner = app(SeoPlanRunner::class);
        $runner->run($runner->queue($website, $this->admin)->id);
        $create = SeoTask::query()->where('type', 'create')->orderByDesc('priority_score')->firstOrFail();

        $this->get(route('operator.seo_tasks'))->assertOk()->assertSee('SEO Görevleri');
        Livewire::test(SeoTasksIndex::class)->assertOk();

        Livewire::test(SeoTasksPanel::class)
            ->assertSee($create->title)
            ->call('toggle', $create->id)
            ->assertSee('İçerik briefi')
            ->call('markDone', $create->id)
            ->assertSee('Yapıldı');
        $this->assertSame(SeoTaskStatus::Done, $create->fresh()->status);
        $this->assertSame($this->admin->id, $create->fresh()->resolved_by);

        // Website tab renders the same list filtered to this site with the refresh button.
        Livewire::test(OverviewPage::class, ['assetId' => (string) $website->id])->set('tab', 'seo')->assertSee('SEO Görevleri');
        Livewire::test(SeoTasksPanel::class, ['websiteId' => $website->id])->assertSee('Planı yenile')->assertSee('Son plan #1');

        // Question answer writes an operator assignment that later runs never overwrite.
        $question = SeoTask::query()->create([
            'customer_id' => $brand->customer_id, 'brand_id' => $brand->id, 'digital_asset_id' => $website->id,
            'brand_offering_id' => $implant->id, 'task_key' => hash('sha256', 'q'), 'type' => 'question', 'rule_id' => 'service-page-question',
            'severity' => 'medium', 'priority_score' => 500, 'title' => 'Soru', 'reason' => 'r',
            'evidence' => ['candidates' => [['url' => 'https://example.test/blog/implant-fiyat/', 'title' => 'Blog', 'score' => 0.4]]],
            'checklist' => [], 'status' => 'open', 'first_seen_plan_id' => SeoPlan::query()->value('id'), 'last_seen_plan_id' => SeoPlan::query()->value('id'),
        ]);
        Livewire::test(SeoTasksPanel::class, ['websiteId' => $website->id])
            ->call('answerQuestion', $question->id, 'https://example.test/blog/implant-fiyat/');
        $assignment = ServicePageAssignment::query()->where('brand_offering_id', $implant->id)->firstOrFail();
        $this->assertSame(ServicePageAssignment::SOURCE_OPERATOR, $assignment->decision_source);
        $this->assertSame('https://example.test/blog/implant-fiyat/', $assignment->page_url);
        $runner->run($runner->queue($website, $this->admin)->id);
        $this->assertSame('https://example.test/blog/implant-fiyat/', $assignment->fresh()->page_url, 'operator decision survives the next run');

        // Brand page star toggles the SEO priority flag.
        Livewire::test(BrandShow::class, ['brand' => (string) $brand->id])
            ->call('setTab', 'business')
            ->assertSee('★')
            ->call('toggleOfferingPriority', $implant->id);
        $this->assertFalse((bool) $implant->fresh()->is_priority);
    }

    public function test_llm_enrichment_merges_valid_output_and_rejects_foreign_queries(): void
    {
        config(['moxdop-seo-tasks.llm.enabled' => true]);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        config(['moxdop.anthropic.api_key' => 'sk-ant-test']);
        [$website] = $this->fixture();
        $runner = app(SeoPlanRunner::class);

        // Compute the deterministic keys the agent must echo back (the sync queue runs the job on dispatch).
        $input = app(SeoPlanInputCollector::class)->collect($website);
        $rules = app(SeoTaskRuleEngine::class)->evaluate($input);
        $create = collect($rules['tasks'])->firstWhere('type', 'create');
        $ownQuery = $create['evidence']['queries'][0]['query'];

        SeoTaskContentPlannerAgent::fake([[
            'items' => [[
                'candidate_id' => $create['task_key'],
                'title' => 'LLM başlık',
                'reason' => 'LLM neden',
                'checklist' => ['Adım 1', 'Adım 2', 'Adım 3'],
                'decision' => 'new_page',
                'brief' => [
                    'page_title' => 'LLM sayfa başlığı', 'page_type' => 'guide', 'h2_outline' => ['A', 'B', 'C'],
                    'queries' => [$ownQuery, 'uydurma sorgu'], 'target_words' => 1400,
                    'target_url_suggestion' => 'https://example.test/blog/llm/', 'internal_links' => ['https://evil.test/x', 'https://example.test/implant/'],
                ],
            ], [
                'candidate_id' => 'unknown-id', 'title' => 'x', 'reason' => 'y', 'checklist' => [], 'decision' => 'keep', 'brief' => null,
            ]],
            'prompt_version' => SeoTaskContentPlannerAgent::PROMPT_VERSION,
        ]])->preventStrayPrompts();

        $done = $runner->queue($website, $this->admin)->fresh();
        $this->assertSame(SeoPlan::STATUS_COMPLETED, $done->status);
        $this->assertSame(1, data_get($done->llm_summary, 'applied'), json_encode($done->llm_summary));
        $this->assertSame('anthropic', data_get($done->llm_summary, 'provider'));

        $task = SeoTask::query()->where('task_key', $create['task_key'])->firstOrFail();
        $this->assertSame('LLM başlık', $task->title);
        $this->assertSame(['Adım 1', 'Adım 2', 'Adım 3'], $task->checklist);
        $this->assertSame([$ownQuery], $task->content_brief['queries'], 'queries not in evidence are dropped');
        $this->assertSame('https://example.test/blog/llm/', $task->target_url);
        $this->assertSame(['https://example.test/implant/'], $task->content_brief['internal_links'], 'foreign hosts are dropped');
        $this->assertSame('llm', $task->content_brief['source']);
        $this->assertSame($create['priority_score'], (float) $task->priority_score, 'LLM never changes the score');
    }

    /** @return array{0: DigitalAsset, 1: BrandOffering, 2: Brand} */
    private function fixture(): array
    {
        $brand = Brand::factory()->create(['name' => 'Örnek Klinik']);
        $website = DigitalAsset::factory()->create([
            'brand_id' => $brand->id, 'type' => 'website', 'status' => 'active', 'domain' => 'example.test',
            'primary_url' => 'https://example.test/', 'seo_market_language_code' => 'tr',
        ]);
        $offerings = app(BrandOfferingService::class);
        $implant = $offerings->create($brand, 'İmplant Diş');
        $offerings->addAlias($implant, 'Diş İmplantı');
        $implant->forceFill(['is_priority' => true, 'priority_rank' => 1])->save();
        $offerings->create($brand, 'Ortodonti');
        $zirkonyum = $offerings->create($brand, 'Zirkonyum Kaplama');
        $zirkonyum->forceFill(['is_priority' => true, 'priority_rank' => 2])->save();

        $this->page($website, '/', ['document_head' => ['title' => 'Örnek Klinik', 'title_present' => true, 'robots' => 'index'], 'headings' => ['h1' => 'Örnek Klinik', 'h1_present' => true], 'structured_data' => ['types' => ['WebSite']]]);
        $this->page($website, '/implant/', ['document_head' => ['title' => 'İmplant Tedavisi', 'title_present' => true, 'meta_description' => 'İmplant', 'robots' => 'index'], 'headings' => ['h1' => 'İmplant Tedavisi', 'h1_present' => true], 'content' => ['word_count' => 500, 'language' => 'tr']]);
        $this->page($website, '/blog/implant-fiyat/', ['document_head' => ['title' => 'İmplant fiyatları', 'title_present' => true, 'meta_description' => 'x', 'robots' => 'index'], 'headings' => ['h1' => 'İmplant fiyatları', 'h1_present' => true], 'content' => ['word_count' => 900, 'language' => 'tr']]);
        $this->page($website, '/ortodonti/', ['document_head' => ['title' => 'Ortodonti', 'title_present' => true, 'robots' => 'index'], 'headings' => ['h1' => 'Ortodonti', 'h1_present' => true], 'content' => ['word_count' => 60, 'language' => 'tr']]);

        $rows = [
            ['implant diş', '/implant/', 1200, 30, 7.2], ['implant diş', '/blog/implant-fiyat/', 500, 10, 12.0],
            ['diş implantı fiyatları', '/implant/', 800, 15, 8.5], ['implant tedavisi', '/implant/', 300, 20, 3.1],
            ['implant nasıl yapılır', '/blog/implant-fiyat/', 400, 2, 28.0], ['implant ne kadar sürer', '/blog/implant-fiyat/', 150, 0, 35.0],
            ['ortodonti tedavisi', '/ortodonti/', 250, 12, 6.0], ['zirkonyum kaplama', '/blog/implant-fiyat/', 90, 1, 24.0],
        ];
        foreach ($rows as $i => [$query, $path, $impressions, $clicks, $position]) {
            foreach ([10, 40] as $daysAgo) {
                DB::table('gsc_query_page_daily')->insert([
                    'digital_asset_id' => $website->id, 'external_resource_id' => null, 'site_url' => 'https://example.test/',
                    'reporting_date' => now()->subDays($daysAgo)->toDateString(), 'query' => $query, 'page' => 'https://example.test'.$path,
                    'clicks' => intdiv($clicks, 2), 'impressions' => intdiv($impressions, 2), 'contract_version' => 1,
                    'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $i.$daysAgo),
                    'metadata' => json_encode(['provider_average_position' => $position, 'provider_ctr' => 0.01]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        Finding::factory()->create([
            'digital_asset_id' => $website->id, 'source_module' => 'website', 'rule_id' => 'website:head:title-missing',
            'fingerprint' => 'website:head:title-missing:iletisim', 'severity' => 'high', 'status' => 'open',
            'title' => 'Başlık etiketi eksik: /iletisim', 'summary' => 'Sayfada title yok.', 'category' => 'seo',
        ]);

        return [$website->fresh(), $implant, $brand];
    }

    private function page(DigitalAsset $website, string $path, array $facts = []): WebsitePageProfile
    {
        $url = 'https://example.test'.$path;
        $identity = IntelligencePageIdentity::query()->create([
            'uuid' => (string) Str::uuid(), 'website_asset_id' => $website->id,
            'identity_hash' => hash('sha256', $website->id.':'.$url), 'preferred_url' => $url,
            'preferred_url_hash' => hash('sha256', $url), 'scheme' => 'https', 'host' => 'example.test', 'path' => $path,
            'resolution_status' => 'resolved', 'normalization_version' => 'v1', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $projection = WebsiteIntelligenceProjectionRun::query()->create([
            'uuid' => (string) Str::uuid(), 'website_asset_id' => $website->id, 'trigger' => 'test', 'status' => 'completed',
            'schema_version' => 1, 'intelligence_registry_version' => 1, 'period_start' => now()->subDays(90), 'period_end' => now()->subDay(),
        ]);

        return WebsitePageProfile::query()->create([
            'website_asset_id' => $website->id, 'page_identity_id' => $identity->id, 'projection_run_id' => $projection->id,
            'preferred_url' => $url, 'profile_version' => 1, 'projected_at' => now(), 'last_observed_at' => now(),
            'source_states' => ['website' => array_replace_recursive(['url' => $url, 'http' => ['status_code' => 200], 'content' => ['word_count' => 400, 'language' => 'tr']], $facts)],
        ]);
    }
}
