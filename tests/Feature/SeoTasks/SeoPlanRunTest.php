<?php

namespace Tests\Feature\SeoTasks;

use App\Ai\Agents\SeoSiteUnderstandingAgent;
use App\Ai\Agents\SeoTaskContentPlannerAgent;
use App\Enums\SeoTaskStatus;
use App\Jobs\RunSeoPlanJob;
use App\Livewire\Demo\Website\OverviewPage;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Livewire\Operator\Seo\SeoTasksIndex;
use App\Livewire\Operator\Seo\SeoTasksPanel;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\Collection\CollectionResourceRun;
use App\Models\CoreIntegration;
use App\Models\DataPool\RawIngestionObject;
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
use Illuminate\Support\Facades\Storage;
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
        Storage::fake('seo-tasks-test');
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
        Livewire::test(SeoTasksPanel::class, ['websiteId' => $website->id])
            ->assertSee('Planı yenile')
            ->assertSee('Son plan')
            ->assertSee('Search Console: ')
            ->assertSee('Bu haftanın içerik önerileri')
            ->assertSee('Tahmini ek tıklama');

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
        $this->enableAnthropic();
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

    public function test_stored_html_drives_duplicate_h1_missing_alt_and_same_as_rules(): void
    {
        [$website, , , $profiles] = $this->fixture();
        $this->htmlSnapshot($website, $profiles['/'], '<html><head><title>Örnek Klinik</title><script type="application/ld+json">{"@context":"https://schema.org","@type":"Dentist","name":"Örnek"}</script></head><body><h1>Örnek Klinik</h1><p>Diş kliniği</p></body></html>');
        $this->htmlSnapshot($website, $profiles['/implant/'], '<html><head><title>İmplant</title></head><body><h1>Logo</h1><h1>İmplant Tedavisi</h1><img src="/a.jpg"><img src="/b.jpg" alt="implant"></body></html>');

        $runner = app(SeoPlanRunner::class);
        $plan = $runner->run($runner->queue($website, $this->admin)->id);

        $this->assertSame(2, data_get($plan->input_summary, 'html.read'));
        $tasks = SeoTask::query()->where('digital_asset_id', $website->id)->get()->keyBy('rule_id');
        $this->assertArrayHasKey('duplicate-h1', $tasks->all());
        $this->assertStringContainsString('/implant/ (2 H1)', implode(' ', $tasks['duplicate-h1']->evidence['urls']));
        $this->assertArrayHasKey('alt-missing', $tasks->all());
        $this->assertStringContainsString('1/2 görsel', implode(' ', $tasks['alt-missing']->evidence['urls']));
        $this->assertArrayHasKey('org-same-as', $tasks->all(), 'Dentist schema found in stored HTML but sameAs empty');
        $this->assertArrayNotHasKey('org-schema', $tasks->all());
    }

    public function test_brand_without_services_uses_ai_site_understanding_and_operator_can_adopt(): void
    {
        $this->enableAnthropic();
        [$website, , $brand, $profiles] = $this->fixture(withServices: false);
        $this->htmlSnapshot($website, $profiles['/'], '<html><head><title>Örnek Klinik</title></head><body><h1>Örnek Klinik</h1><p>Kadıköy diş kliniği: implant ve ortodonti tedavileri.</p></body></html>');

        SeoSiteUnderstandingAgent::fake([[
            'brand_summary' => 'Kadıköy\'de implant ve ortodonti yapan diş kliniği.',
            'audience' => 'Diş tedavisi arayan yetişkinler',
            'locations' => ['Kadıköy'],
            'services' => [
                ['name' => 'İmplant', 'aliases' => ['Diş İmplantı'], 'page_url' => 'https://example.test/implant/', 'queries' => ['implant diş', 'diş implantı fiyatları', 'uydurma sorgu'], 'is_core' => true],
                ['name' => 'Ortodonti', 'aliases' => [], 'page_url' => 'https://evil.test/ortodonti/', 'queries' => ['ortodonti tedavisi'], 'is_core' => false],
            ],
            'prompt_version' => SeoSiteUnderstandingAgent::PROMPT_VERSION,
        ]])->preventStrayPrompts();
        SeoTaskContentPlannerAgent::fake([['items' => [], 'prompt_version' => SeoTaskContentPlannerAgent::PROMPT_VERSION]]);

        $runner = app(SeoPlanRunner::class);
        $plan = $runner->run($runner->queue($website, $this->admin)->id);

        $understanding = data_get($plan->input_summary, 'site_understanding');
        $this->assertSame('ai', $understanding['source']);
        $this->assertStringContainsString('implant', mb_strtolower($understanding['brand_summary']));
        $this->assertSame(['implant diş', 'diş implantı fiyatları'], $understanding['services'][0]['queries'], 'queries outside Search Console data are dropped');
        $this->assertNull($understanding['services'][1]['page_url'], 'URLs outside the page inventory are dropped');

        $tasks = SeoTask::query()->where('digital_asset_id', $website->id)->get();
        $create = $tasks->filter(fn (SeoTask $t): bool => $t->type->value === 'create');
        $this->assertGreaterThanOrEqual(4, $create->count());
        $this->assertTrue($tasks->contains(fn (SeoTask $t): bool => $t->type->value === 'strengthen' && ($t->evidence['service'] ?? null) === 'İmplant'));
        $this->assertTrue($tasks->every(fn (SeoTask $t): bool => $t->brand_offering_id === null));
        $this->assertSame(0, ServicePageAssignment::query()->count(), 'inferred topics are not persisted as assignments');

        Livewire::test(SeoTasksPanel::class, ['websiteId' => $website->id])
            ->assertSee('siteden çıkarıldı')
            ->assertSee('İmplant · çıkarım');

        // Second run inside the cache window reuses the inference without another AI call.
        $second = $runner->run($runner->queue($website, $this->admin)->id);
        $this->assertSame('cache', data_get($second->input_summary, 'site_understanding.source'));
        $this->assertSame('ai', data_get($second->input_summary, 'site_understanding.source_detail'));

        // Operator adopts the core service → real Brand offering, star and page assignment.
        Livewire::test(SeoTasksPanel::class, ['websiteId' => $website->id])->call('adoptService', 0)->assertSee('markaya hizmet olarak eklendi');
        $offering = BrandOffering::query()->where('brand_id', $brand->id)->with('primaryName')->firstOrFail();
        $this->assertSame('İmplant', $offering->primaryName->raw_label);
        $this->assertTrue((bool) $offering->is_priority);
        $this->assertSame('https://example.test/implant/', ServicePageAssignment::query()->where('brand_offering_id', $offering->id)->value('page_url'));

        $third = $runner->run($runner->queue($website, $this->admin)->id);
        $this->assertNull(data_get($third->input_summary, 'site_understanding'), 'once the Brand has services, inference stops');
    }

    public function test_brand_without_services_falls_back_to_page_topics_when_ai_is_unavailable(): void
    {
        [$website] = $this->fixture(withServices: false);

        $runner = app(SeoPlanRunner::class);
        $plan = $runner->run($runner->queue($website, $this->admin)->id);

        $understanding = data_get($plan->input_summary, 'site_understanding');
        $this->assertSame('rules', $understanding['source']);
        $names = array_column($understanding['services'], 'name');
        $this->assertContains('İmplant Tedavisi', $names);
        $this->assertContains('Ortodonti', $names);
        $this->assertNotContains('İmplant fiyatları', $names, 'blog pages are not services');
        $this->assertGreaterThanOrEqual(4, SeoTask::query()->where('type', 'create')->count());
    }

    public function test_non_html_urls_and_uncollected_heads_never_become_missing_title_tasks(): void
    {
        [$website] = $this->fixture();
        // Feeds / sitemaps / robots collected as "pages" and a page whose head was never collected.
        foreach (['/feed/', '/sitemap.xml', '/robots.txt', '/crm/yazi/feed/'] as $path) {
            $this->page($website, $path, ['http' => ['status_code' => 200, 'content_type' => 'application/xml']]);
        }
        $this->page($website, '/hakkimizda/'); // no document_head at all → unknown, not missing
        $this->page($website, '/iletisim/', ['document_head' => ['title_present' => false, 'title' => null, 'robots' => 'index']]);

        $runner = app(SeoPlanRunner::class);
        $plan = $runner->run($runner->queue($website, $this->admin)->id);

        $this->assertSame(4, data_get($plan->input_summary, 'html.excluded_non_documents'));
        $title = SeoTask::query()->where('rule_id', 'title-missing')->firstOrFail();
        $this->assertSame(['https://example.test/iletisim/'], $title->evidence['urls'], 'only the page with an observed empty head counts');
        $this->assertStringNotContainsString('feed', json_encode(SeoTask::query()->pluck('evidence')));
    }

    public function test_starred_services_without_a_clear_page_get_one_grouped_mapping_card(): void
    {
        [$website, $implant, $brand] = $this->fixture();
        $offerings = app(BrandOfferingService::class);
        $beyazlatma = $offerings->create($brand, 'Diş Beyazlatma');
        $beyazlatma->forceFill(['is_priority' => true])->save();
        $this->page($website, '/blog/dis-beyazlatma-zararli-mi/', ['document_head' => ['title' => 'Diş beyazlatma zararlı mı?', 'title_present' => true, 'robots' => 'index'], 'headings' => ['h1' => 'Diş beyazlatma zararlı mı?', 'h1_present' => true]]);
        $this->page($website, '/estetik/beyaz-gulus/', ['document_head' => ['title' => 'Beyaz gülüş ve diş beyazlatma', 'title_present' => true, 'robots' => 'index'], 'headings' => ['h1' => 'Beyaz gülüş', 'h1_present' => true]]);

        $runner = app(SeoPlanRunner::class);
        $runner->run($runner->queue($website, $this->admin)->id);

        $cards = SeoTask::query()->where('type', 'question')->get();
        $this->assertCount(1, $cards, 'one card per site, not one per service');
        $card = $cards->first();
        $this->assertSame('service-page-mapping', $card->rule_id);
        $ids = array_column($card->evidence['services'], 'offering_id');
        $this->assertContains($beyazlatma->id, $ids);
        $this->assertNotContains($implant->id, $ids, 'implant page is clear enough to auto-assign');

        // Global list hides the card from the task list but shows it as setup work.
        Livewire::test(SeoTasksPanel::class)->assertSee('öncelikli hizmetin sayfasını eşleştir')->assertSee('Eşleştir →');

        $component = Livewire::test(SeoTasksPanel::class, ['websiteId' => $website->id])
            ->set('mapping.'.$beyazlatma->id, 'https://example.test/estetik/beyaz-gulus/')
            ->call('answerMapping', $card->id, $beyazlatma->id);
        $component->assertSee('kaydedildi');
        $this->assertSame('https://example.test/estetik/beyaz-gulus/', ServicePageAssignment::query()->where('brand_offering_id', $beyazlatma->id)->value('page_url'));
        $card->refresh();
        $this->assertNotContains($beyazlatma->id, array_column($card->evidence['services'] ?? [], 'offering_id'));
        if (($card->evidence['services'] ?? []) === []) {
            $this->assertSame(SeoTaskStatus::Done, $card->status);
        }

        // No "open a new service page" task for a service whose candidates await an answer.
        $this->assertFalse(SeoTask::query()->where('type', 'create')->where('rule_id', 'create-service')->where('brand_offering_id', $beyazlatma->id)->where('evidence->source', 'inventory')->exists());
    }

    public function test_brand_services_that_do_not_fit_the_site_are_replaced_by_site_understanding(): void
    {
        [$website, , $brand] = $this->fixture(withServices: false);
        // The Brand sells something unrelated to this site (e.g. an agency site under a clinic Brand).
        app(BrandOfferingService::class)->create($brand, 'Dijital Pazarlama Danışmanlığı');

        $runner = app(SeoPlanRunner::class);
        $plan = $runner->run($runner->queue($website, $this->admin)->id);

        $this->assertSame('brand_services_not_on_site', data_get($plan->input_summary, 'site_understanding.reason'));
        $this->assertSame(0, SeoTask::query()->where('type', 'question')->count());
        Livewire::test(SeoTasksPanel::class, ['websiteId' => $website->id])->assertSee('Markanın hizmetleri bu siteyle örtüşmüyor');
    }

    /** @return array{0: DigitalAsset, 1: BrandOffering|null, 2: Brand, 3: array<string, WebsitePageProfile>} */
    private function fixture(bool $withServices = true): array
    {
        $brand = Brand::factory()->create(['name' => 'Örnek Klinik']);
        $website = DigitalAsset::factory()->create([
            'brand_id' => $brand->id, 'type' => 'website', 'status' => 'active', 'domain' => 'example.test',
            'primary_url' => 'https://example.test/', 'seo_market_language_code' => 'tr',
        ]);
        $implant = null;
        if ($withServices) {
            $offerings = app(BrandOfferingService::class);
            $implant = $offerings->create($brand, 'İmplant Diş');
            $offerings->addAlias($implant, 'Diş İmplantı');
            $implant->forceFill(['is_priority' => true, 'priority_rank' => 1])->save();
            $offerings->create($brand, 'Ortodonti');
            $zirkonyum = $offerings->create($brand, 'Zirkonyum Kaplama');
            $zirkonyum->forceFill(['is_priority' => true, 'priority_rank' => 2])->save();
        }

        $profiles = [];
        $profiles['/'] = $this->page($website, '/', ['document_head' => ['title' => 'Örnek Klinik', 'title_present' => true, 'robots' => 'index'], 'headings' => ['h1' => 'Örnek Klinik', 'h1_present' => true], 'structured_data' => ['types' => ['WebSite']]]);
        $profiles['/implant/'] = $this->page($website, '/implant/', ['document_head' => ['title' => 'İmplant Tedavisi', 'title_present' => true, 'meta_description' => 'İmplant', 'robots' => 'index'], 'headings' => ['h1' => 'İmplant Tedavisi', 'h1_present' => true], 'content' => ['word_count' => 500, 'language' => 'tr']]);
        $profiles['/blog/implant-fiyat/'] = $this->page($website, '/blog/implant-fiyat/', ['document_head' => ['title' => 'İmplant fiyatları', 'title_present' => true, 'meta_description' => 'x', 'robots' => 'index'], 'headings' => ['h1' => 'İmplant fiyatları', 'h1_present' => true], 'content' => ['word_count' => 900, 'language' => 'tr']]);
        $profiles['/ortodonti/'] = $this->page($website, '/ortodonti/', ['document_head' => ['title' => 'Ortodonti', 'title_present' => true, 'robots' => 'index'], 'headings' => ['h1' => 'Ortodonti', 'h1_present' => true], 'content' => ['word_count' => 60, 'language' => 'tr']]);

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

        return [$website->fresh(), $implant, $brand, $profiles];
    }

    private function htmlSnapshot(DigitalAsset $website, WebsitePageProfile $profile, string $html): void
    {
        $resource = CollectionResourceRun::factory()->create(['digital_asset_id' => $website->id, 'provider_or_source' => 'website']);
        $key = (string) Str::uuid().'.html';
        Storage::disk('seo-tasks-test')->put($key, $html);
        $object = RawIngestionObject::query()->create(['uuid' => (string) Str::uuid(),
            'resource_run_id' => $resource->id, 'collection_run_id' => $resource->collection_run_id, 'dataset_id' => 'website_html_snapshot',
            'batch_key' => $key, 'provider_or_source' => 'website', 'storage_disk' => 'seo-tasks-test', 'object_key' => $key,
            'byte_size' => strlen($html), 'sha256' => hash('sha256', $html), 'captured_at' => now()]);
        DB::table('website_html_snapshot')->insert(['digital_asset_id' => $website->id,
            'url' => $profile->preferred_url, 'raw_ingestion_object_id' => $object->id, 'html_hash' => hash('sha256', $html),
            'html_bytes' => strlen($html), 'change_state' => 'new', 'observed_at' => now(), 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $key)]);
    }

    private function enableAnthropic(): void
    {
        config(['moxdop-seo-tasks.llm.enabled' => true, 'moxdop.anthropic.api_key' => 'sk-ant-test']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
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
