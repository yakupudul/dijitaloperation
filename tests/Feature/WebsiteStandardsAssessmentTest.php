<?php

namespace Tests\Feature;

use App\Ai\Agents\SearchDemandCompetitiveIntelligenceAgent;
use App\Ai\Agents\SearchDemandWebsiteImprovementAgent;
use App\Jobs\Async\WebsiteStandardsAssessmentJob;
use App\Livewire\Operator\Library\SearchDemandCompetitiveIntelligencePage;
use App\Livewire\Operator\Library\SearchDemandImprovementPage;
use App\Livewire\Operator\Library\SearchDemandPageOwnershipPage;
use App\Livewire\Operator\Library\WebsiteStandardsPage;
use App\Livewire\Operator\Website\WebsiteAssessmentPanel;
use App\Models\BrandQueryPortfolioItem;
use App\Models\Collection\CollectionResourceRun;
use App\Models\CoreIntegration;
use App\Models\DataPool\RawIngestionObject;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\IntelligenceCore\IntelligencePageIdentity;
use App\Models\IntelligenceProjection\WebsiteIntelligenceProjectionRun;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\ModuleRegistry;
use App\Models\Recommendation;
use App\Models\Run;
use App\Models\SearchDemandCluster;
use App\Models\SearchDemandCompetitor;
use App\Models\SearchDemandCompetitorPageObservation;
use App\Models\SearchDemandCompetitorPageRunItem;
use App\Models\SearchDemandPageOwnership;
use App\Models\Task;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Services\Integrations\OpenAi\OpenAiProviderCredentialService;
use App\Services\SearchDemand\SearchDemandCompetitiveIntelligenceService;
use App\Services\SearchDemand\SearchDemandPageOwnershipService;
use App\Services\SearchDemand\SearchDemandWebsiteImprovementService;
use App\Services\Website\WebsiteAssessmentService;
use App\Services\Website\WebsiteCoverageService;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use MoxDop\Website\Standards\WebsiteStandardCatalog;
use Tests\TestCase;

final class WebsiteStandardsAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        ModuleRegistry::query()->updateOrCreate(['module_id' => 'website'], ['enabled' => true]);
        Bus::fake();
        Http::preventStrayRequests();
    }

    public function test_stored_technical_assessment_requires_no_queries_competitors_or_ai(): void
    {
        $website = $this->website();
        $this->page($website, '/service', ['document_head' => ['title_present' => false, 'title' => null]]);
        $service = app(WebsiteAssessmentService::class);
        $run = $service->queue($website, $this->admin);
        Bus::assertDispatched(WebsiteStandardsAssessmentJob::class);
        $this->assertSame($run->id, $service->queue($website, $this->admin)->id);
        $service->execute($run->run_id, app(AsyncOperationService::class));
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(0, $run->response_payload['ai_calls']);
        $this->assertSame(1, $run->response_payload['evaluated_pages']);
        $this->assertTrue($run->proposals()->where('stable_key', 'standard:website:head:title-missing')->exists());
        $this->assertSame(0, Finding::query()->count());
        $this->assertSame(0, Recommendation::query()->count());
        $this->assertSame(0, Task::query()->count());
        Http::assertNothingSent();
        Livewire::test(WebsiteAssessmentPanel::class, ['websiteId' => $website->id])
            ->assertSee('Title etiketi eksik')->assertSee('İyileştirmeyi kabul et');
    }

    public function test_exact_reuse_then_new_observation_invalidates_it(): void
    {
        $website = $this->website();
        $profile = $this->page($website, '/service');
        $service = app(WebsiteAssessmentService::class);
        $first = $service->queue($website, $this->admin);
        $service->execute($first->run_id, app(AsyncOperationService::class));
        $second = $service->queue($website, $this->admin);
        $service->execute($second->run_id, app(AsyncOperationService::class));
        $this->assertSame($first->id, $second->fresh()->response_payload['cached_run_id']);
        $profile->update(['source_states' => ['website' => ['http' => ['status_code' => 503]]]]);
        $third = $service->queue($website, $this->admin);
        $service->execute($third->run_id, app(AsyncOperationService::class));
        $this->assertArrayNotHasKey('cached_run_id', $third->fresh()->response_payload);
        $this->assertTrue($third->proposals()->where('stable_key', 'standard:reachability-http')->exists());
    }

    public function test_human_acceptance_uses_existing_finding_recommendation_pipeline_only(): void
    {
        $website = $this->website();
        $this->page($website, '/service', ['http' => ['status_code' => 503]]);
        $service = app(WebsiteAssessmentService::class);
        $run = $service->queue($website, $this->admin);
        $service->execute($run->run_id, app(AsyncOperationService::class));
        $proposal = $run->proposals()->where('stable_key', 'standard:reachability-http')->firstOrFail();
        app(SearchDemandWebsiteImprovementService::class)->review($proposal, 'approved', null, $this->admin);
        $this->assertSame(1, Finding::query()->count());
        $this->assertSame(1, Recommendation::query()->count());
        $this->assertSame(0, Task::query()->count());
        $this->assertNotNull($proposal->fresh()->evidence_id);
    }

    public function test_disabled_standard_cannot_be_promoted_from_an_old_assessment(): void
    {
        $website = $this->website();
        $this->page($website, '/service', ['http' => ['status_code' => 503]]);
        $service = app(WebsiteAssessmentService::class);
        $run = $service->queue($website, $this->admin);
        $service->execute($run->run_id, app(AsyncOperationService::class));
        $proposal = $run->proposals()->where('stable_key', 'standard:reachability-http')->firstOrFail();
        app(WebsiteStandardCatalog::class)->setEnabled('reachability-http', false, $this->admin);
        $this->expectException(ValidationException::class);
        app(SearchDemandWebsiteImprovementService::class)->review($proposal, 'approved', null, $this->admin);
    }

    public function test_operator_pages_render_and_library_controls_are_admin_only(): void
    {
        $website = $this->website();
        $this->get(route('operator.library.website-standards'))->assertOk()->assertSee('Web Sitesi Standartları');
        $this->get(route('operator.website', ['assetId' => $website->id, 'tab' => 'standards']))->assertOk()->assertSee('Web sitesini değerlendir');
        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);
        Livewire::test(WebsiteStandardsPage::class)->call('setEnabled', 'reachability-http', false)->assertForbidden();
    }

    public function test_disabled_module_and_cross_asset_candidate_are_rejected(): void
    {
        $website = $this->website();
        $other = $this->website();
        $profile = $this->page($other, '/service');
        $cluster = SearchDemandCluster::query()->create(['uuid' => (string) Str::uuid(), 'brand_id' => $website->brand_id,
            'cluster_key' => 'service', 'name' => 'Service', 'content_target_cluster' => 'Service', 'status' => 'active']);
        $gate = app(SearchDemandPageOwnershipService::class)->technicalGate($website, $cluster, $profile);
        $this->assertSame('ineligible', $gate['state']);
        ModuleRegistry::query()->where('module_id', 'website')->update(['enabled' => false]);
        $this->expectException(ValidationException::class);
        app(WebsiteAssessmentService::class)->queue($website, $this->admin);
    }

    public function test_changed_page_cannot_be_approved_using_its_old_proposal(): void
    {
        $website = $this->website();
        $profile = $this->page($website, '/service', ['http' => ['status_code' => 503]]);
        $service = app(WebsiteAssessmentService::class);
        $run = $service->queue($website, $this->admin);
        $service->execute($run->run_id, app(AsyncOperationService::class));
        $proposal = $run->proposals()->where('stable_key', 'standard:reachability-http')->firstOrFail();
        $profile->update(['source_states' => ['website' => ['http' => ['status_code' => 200]]]]);
        $this->expectException(ValidationException::class);
        app(SearchDemandWebsiteImprovementService::class)->review($proposal, 'approved', null, $this->admin);
    }

    public function test_empty_inventory_stays_partial_when_reused(): void
    {
        $website = $this->website();
        $service = app(WebsiteAssessmentService::class);
        $first = $service->queue($website, $this->admin);
        $service->execute($first->run_id, app(AsyncOperationService::class));
        $second = $service->queue($website, $this->admin);
        $service->execute($second->run_id, app(AsyncOperationService::class));
        $this->assertSame('partial', $second->fresh()->status);
        $this->assertSame($first->id, $second->fresh()->response_payload['cached_run_id']);
    }

    public function test_coverage_keeps_blocked_owner_and_limits_queries_to_the_website(): void
    {
        $website = $this->website();
        $profile = $this->page($website, '/klima-bakim', ['http' => ['status_code' => 503]]);
        [$cluster, $item, $owner] = $this->ownership($website, $profile);
        $hidden = $this->cluster($website, 'Gizli küme');
        $this->member($website, $hidden, 'pasif sorgu', 'paused');
        $website->brand->serviceAreas()->create(['country_code' => 'TR', 'country_name' => 'Türkiye',
            'city_name' => 'İstanbul', 'district_name' => 'Kadıköy', 'normalized_key' => 'tr:istanbul:kadikoy', 'status' => 'active']);
        $coverage = app(WebsiteCoverageService::class)->assess($website, collect([$profile]));
        $this->assertCount(1, $coverage['clusters']);
        $row = $coverage['clusters'][0];
        $this->assertSame('repair_or_verify', $row['state']);
        $this->assertSame('ineligible', $row['candidates'][0]['technical_state']);
        $this->assertContains('Kadıköy', $row['locations']);
        $this->assertTrue($owner->fresh()->is_locked);
        $this->assertSame($profile->preferred_url, $owner->fresh()->target_url);
        $result = app(SearchDemandPageOwnershipService::class)->queue($website, $cluster,
            CarbonImmutable::today()->subDays(28), CarbonImmutable::yesterday(),
            CarbonImmutable::today()->subDays(56), CarbonImmutable::today()->subDays(29), $this->admin);
        $this->assertSame(0, $result['eligible_count']);
        $this->assertSame('review_required', $result['run']->deterministic_state);
        Http::assertNothingSent();
    }

    public function test_deep_links_keep_the_selected_website_and_cluster(): void
    {
        $website = $this->website();
        DigitalAsset::factory()->create(['brand_id' => $website->brand_id, 'type' => 'website', 'name' => 'AAA first website']);
        $this->cluster($website, 'AAA first cluster');
        $cluster = $this->cluster($website, 'ZZZ chosen cluster');
        foreach ([SearchDemandImprovementPage::class,
            SearchDemandCompetitiveIntelligencePage::class] as $component) {
            Livewire::withQueryParams(['brand' => (string) $website->brand_id, 'website' => (string) $website->id, 'cluster' => (string) $cluster->id])
                ->test($component)->assertSet('selectedWebsiteId', (string) $website->id)->assertSet('selectedClusterId', (string) $cluster->id);
        }
        Livewire::withQueryParams(['website' => (string) $website->id, 'cluster' => (string) $cluster->id])
            ->test(SearchDemandPageOwnershipPage::class)->assertSet('clusterId', (string) $cluster->id);
    }

    public function test_semantic_review_works_without_competitors_and_rejects_unsupported_ai_output(): void
    {
        $website = $this->website();
        $profile = $this->page($website, '/klima-bakim');
        [$cluster] = $this->ownership($website, $profile);
        $this->htmlSnapshot($website, $profile, '<html><head><title>Klima bakım hizmeti</title></head><body><h1>Klima bakım hizmeti</h1><p>Bakım süreci ve kontrol adımları burada anlatılır.</p></body></html>');
        $integration = CoreIntegration::factory()->openai()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        app(OpenAiProviderCredentialService::class)->save($integration, ['api_key' => 'sk-test-website-standards'], $this->admin);
        $row = ['finding_key' => 'scope_detail', 'title' => 'Bakım kapsamını açıklayın',
            'summary' => 'Kullanıcının bakım kapsamını anlaması için işlemler açıklanmalı.', 'rationale' => 'Gözlenen kapsam adları kullanıcıya uygulanacak adımları açıklamıyor.',
            'standard_id' => 'website:review:questions', 'assessment_state' => 'partial',
            'brand_evidence' => 'Bakım süreci ve kontrol adımları', 'action_type' => 'improve_existing',
            'recommendation_title' => 'Kapsamı açıklayın', 'recommendation_action' => 'Bakımın kontrol adımlarını açıklayın.',
            'analysis_ids' => [], 'observation_ids' => [], 'competitor_ids' => [],
            'evidence_explanation' => ['Saklı sayfa kapsamın adını içeriyor; adımlar açıklanmıyor.'],
            'verification_steps' => ['Yeni saklı içerikte açıklanmış adımları inceleyin.'], 'confidence' => 80, 'abstained' => false];
        SearchDemandWebsiteImprovementAgent::fake([['abstained' => false, 'proposals' => [
            $row, array_replace($row, ['finding_key' => 'invented', 'standard_id' => 'invented-standard']),
            array_replace($row, ['finding_key' => 'no_change', 'action_type' => 'no_action']),
        ]]])->preventStrayPrompts();
        $service = app(SearchDemandWebsiteImprovementService::class);
        $queued = $service->queue($website, $cluster, $this->admin);
        $this->assertSame(0, $queued['approved_analysis_count']);
        $this->assertNull($queued['run']->competitive_intelligence_run_id);
        $service->execute($queued['run']->run_id, app(AsyncOperationService::class));
        $this->assertSame(3, $queued['run']->proposals()->count());
        $accepted = $queued['run']->proposals()->where('abstained', false)->sole();
        $this->assertSame('website:review:questions', $accepted->evidence_refs['standard_id']);
        $this->assertSame(2, $queued['run']->proposals()->where('abstained', true)->count());
        $service->review($accepted, 'approved', null, $this->admin);
        $this->assertSame(1, Finding::query()->count());
        $this->assertSame(0, Task::query()->count());
        $this->assertTrue($service->queue($website, $cluster, $this->admin)['cached']);
        Http::assertNothingSent();
    }

    public function test_new_html_invalidates_assessment_cache_even_before_projection_rebuild(): void
    {
        $website = $this->website();
        $profile = $this->page($website, '/service');
        $service = app(WebsiteAssessmentService::class);
        $first = $service->queue($website, $this->admin);
        $service->execute($first->run_id, app(AsyncOperationService::class));
        $this->htmlSnapshot($website, $profile, '<html><head><title>Nitelikli bakım</title></head><body><h1>Bakım</h1><p>Bakım adımları burada.</p></body></html>');
        $second = $service->queue($website, $this->admin);
        $service->execute($second->run_id, app(AsyncOperationService::class));
        $this->assertArrayNotHasKey('cached_run_id', $second->fresh()->response_payload);
        $this->assertTrue($second->proposals()->where('stable_key', 'standard:website:structure:internal-links')->exists());
    }

    public function test_custom_criterion_has_bounded_metadata_and_can_be_disabled(): void
    {
        Livewire::test(WebsiteStandardsPage::class)->set('draft', [
            'title' => 'Bakım kapsamı açıklaması', 'group' => 'content',
            'criterion' => 'Bakımın içerdiği işlemler sayfada açık biçimde anlatılmalı.',
            'action' => 'Yapılan kontrolleri ve dahil olmayan işlemleri açıklayın.', 'source_url' => '',
        ])->call('addCriterion')->assertHasNoErrors()->assertSee('Bakım kapsamı açıklaması');
        $catalog = app(WebsiteStandardCatalog::class);
        $custom = collect($catalog->all())->first(fn ($row) => str_starts_with($row['id'], 'website:custom:'));
        $this->assertSame('expert_review', $custom['method']);
        $catalog->setEnabled($custom['id'], false, $this->admin);
        $this->assertArrayNotHasKey($custom['id'], $catalog->all(true));
        $this->assertArrayHasKey($custom['id'], $catalog->all());
    }

    public function test_shared_competitor_criteria_require_comparability_before_approval_and_enrich_own_page_review(): void
    {
        $website = $this->website();
        $profile = $this->page($website, '/klima-bakim');
        [$cluster] = $this->ownership($website, $profile);
        $this->htmlSnapshot($website, $profile, '<html><head><title>Klima bakım hizmeti</title></head><body><p>Bakım süreci ve kontrol adımları burada anlatılır.</p></body></html>');
        $integration = CoreIntegration::factory()->openai()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        app(OpenAiProviderCredentialService::class)->save($integration, ['api_key' => 'sk-test-website-standards'], $this->admin);
        $competitor = SearchDemandCompetitor::query()->create(['uuid' => (string) Str::uuid(),
            'brand_id' => $website->brand_id, 'display_name' => 'Rival', 'normalized_domain' => 'rival.test',
            'normalized_domain_hash' => hash('sha256', 'rival.test'), 'status' => 'approved']);
        $activity = Run::query()->create(['digital_asset_id' => $website->id, 'module_id' => 'search_demand', 'status' => 'completed', 'started_at' => now()]);
        $outputs = [];
        foreach (['comparable', 'different_intent'] as $index => $comparability) {
            $url = 'https://rival.test/page-'.$index;
            $urlRecord = $competitor->urls()->create(['url' => $url, 'normalized_url_hash' => hash('sha256', $url), 'domain' => 'rival.test', 'source_type' => 'operator']);
            $item = SearchDemandCompetitorPageRunItem::query()->create(['run_id' => $activity->id,
                'search_demand_cluster_id' => $cluster->id, 'search_demand_competitor_id' => $competitor->id,
                'search_demand_competitor_url_id' => $urlRecord->id, 'requested_url' => $url,
                'normalized_url_hash' => hash('sha256', $url), 'selection_order' => $index, 'status' => 'completed']);
            $observation = SearchDemandCompetitorPageObservation::query()->create(['run_item_id' => $item->id,
                'search_demand_competitor_url_id' => $urlRecord->id, 'requested_url' => $url, 'final_url' => $url,
                'status' => 'completed', 'observed_at' => now(), 'content_fingerprint' => hash('sha256', $url),
                'normalized_text' => 'Bakım ücretine dahil işlemler açıkça açıklanır.']);
            $outputs[] = ['observation_id' => $observation->id, 'competitor_id' => $competitor->id,
                'comparability' => $comparability, 'abstained' => false, 'standard_assessments' => [[
                    'standard_id' => 'website:review:questions', 'brand_state' => 'partial', 'competitor_state' => 'pass',
                    'brand_evidence' => 'Bakım süreci ve kontrol adımları', 'competitor_evidence' => 'Bakım ücretine dahil işlemler',
                    'rationale' => 'İşlem kapsamının nasıl açıklandığı karşılaştırılıyor.',
                ]]];
        }
        SearchDemandCompetitiveIntelligenceAgent::fake([['pages' => $outputs, 'abstained' => false]])->preventStrayPrompts();
        $service = app(SearchDemandCompetitiveIntelligenceService::class);
        $queued = $service->queue($website, $cluster, $this->admin);
        $service->execute($queued['run']->run_id, app(AsyncOperationService::class));
        $accepted = $queued['run']->analyses()->where('comparability', 'comparable')->sole();
        $blocked = $queued['run']->analyses()->where('comparability', 'different_intent')->sole();
        $this->assertFalse($accepted->abstained);
        $this->assertTrue($blocked->abstained);
        $service->review($accepted, 'approved', null, $this->admin);
        $improvement = app(SearchDemandWebsiteImprovementService::class)->queue($website, $cluster, $this->admin);
        $this->assertSame(1, $improvement['approved_analysis_count']);
        $this->assertSame($queued['run']->id, $improvement['run']->competitive_intelligence_run_id);
        Livewire::withQueryParams(['brand' => (string) $website->brand_id, 'website' => (string) $website->id,
            'cluster' => (string) $cluster->id, 'run' => (string) $queued['run']->id])
            ->test(SearchDemandCompetitiveIntelligencePage::class)->assertSee('Bakım süreci ve kontrol adımları');
        $this->assertSame(0, Finding::query()->count());
        Http::assertNothingSent();
        $this->expectException(ValidationException::class);
        $service->review($blocked, 'approved', null, $this->admin);
    }

    private function cluster(DigitalAsset $website, string $name): SearchDemandCluster
    {
        return SearchDemandCluster::query()->create(['uuid' => (string) Str::uuid(), 'brand_id' => $website->brand_id,
            'cluster_key' => (string) Str::uuid(), 'name' => $name, 'content_target_cluster' => $name, 'status' => 'active'])->refresh();
    }

    private function member(DigitalAsset $website, SearchDemandCluster $cluster, string $text, string $status = 'active'): BrandQueryPortfolioItem
    {
        $item = BrandQueryPortfolioItem::query()->create(['uuid' => (string) Str::uuid(),
            'brand_id' => $website->brand_id, 'identity_hash' => hash('sha256', (string) Str::uuid()),
            'custom_canonical_text' => $text, 'custom_folded_text' => $text, 'origin_type' => 'custom', 'status' => 'active',
            'area_scope' => 'all_brand_areas']);
        $item->assetStates()->create(['digital_asset_id' => $website->id, 'status' => $status]);
        $item->clusterMembership()->create(['search_demand_cluster_id' => $cluster->id, 'source' => 'operator']);

        return $item;
    }

    private function ownership(DigitalAsset $website, WebsitePageProfile $profile): array
    {
        $cluster = $this->cluster($website, 'Klima bakım');
        $item = $this->member($website, $cluster, 'klima bakım');
        $owner = SearchDemandPageOwnership::query()->create(['uuid' => (string) Str::uuid(),
            'brand_id' => $website->brand_id, 'digital_asset_id' => $website->id, 'search_demand_cluster_id' => $cluster->id,
            'website_page_profile_id' => $profile->id, 'page_identity_id' => $profile->page_identity_id,
            'target_url' => $profile->preferred_url, 'status' => 'verified_owner', 'decision_source' => 'operator',
            'is_locked' => true, 'version' => 1]);

        return [$cluster, $item, $owner];
    }

    private function htmlSnapshot(DigitalAsset $website, WebsitePageProfile $profile, string $html): void
    {
        Storage::fake('website-assessment-test');
        $resource = CollectionResourceRun::factory()->create(['digital_asset_id' => $website->id, 'provider_or_source' => 'website']);
        $key = (string) Str::uuid().'.html';
        Storage::disk('website-assessment-test')->put($key, $html);
        $object = RawIngestionObject::query()->create(['uuid' => (string) Str::uuid(),
            'resource_run_id' => $resource->id, 'collection_run_id' => $resource->collection_run_id, 'dataset_id' => 'website_html_snapshot',
            'batch_key' => $key, 'provider_or_source' => 'website', 'storage_disk' => 'website-assessment-test', 'object_key' => $key,
            'byte_size' => strlen($html), 'sha256' => hash('sha256', $html), 'captured_at' => now()]);
        DB::table('website_html_snapshot')->insert(['digital_asset_id' => $website->id,
            'url' => $profile->preferred_url, 'raw_ingestion_object_id' => $object->id, 'html_hash' => hash('sha256', $html),
            'html_bytes' => strlen($html), 'change_state' => 'new', 'observed_at' => now(), 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $key)]);
    }

    private function website(): DigitalAsset
    {
        return DigitalAsset::factory()->create(['type' => 'website', 'domain' => 'example.test',
            'primary_url' => 'https://example.test/', 'seo_market_language_code' => 'tr']);
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
            'source_states' => ['website' => array_replace(['url' => $url, 'http' => ['status_code' => 200],
                'document_head' => ['title_present' => true, 'title' => 'Klima bakım hizmeti', 'robots' => 'index, follow', 'canonical_hrefs' => [$url]],
                'content' => ['language' => 'tr']], $facts)],
        ]);
    }
}
