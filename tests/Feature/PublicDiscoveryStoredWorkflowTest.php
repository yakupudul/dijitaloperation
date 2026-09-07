<?php

namespace Tests\Feature;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\OfferingStatus;
use App\Events\Collection\CollectionRunCompleted;
use App\Jobs\Async\PublicDiscoveryJob;
use App\Livewire\Demo\Integrations\IntegrationsIndex;
use App\Livewire\Operator\PublicDiscoveryIndex;
use App\Livewire\Operator\Website\PublicDiscoveryPage;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\DataPool\RawIngestionObject;
use App\Models\DigitalAsset;
use App\Models\DiscoveryCandidate;
use App\Models\Evidence;
use App\Models\ModuleRegistry;
use App\Models\Run;
use App\Models\SearchDemandCompetitor;
use App\Models\ServiceCatalogItem;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Services\SearchDemand\BrandCommercialContextService;
use App\Services\SearchDemand\SearchDemandCompetitorLibraryService;
use App\Services\Website\PublicDiscovery\StoredDiscoverySource;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use MoxDop\Website\Discovery\DiscoveryCandidateReviewService;
use MoxDop\Website\Discovery\DiscoveryConfig;
use MoxDop\Website\Discovery\PublicDiscoveryService;
use MoxDop\Website\Discovery\PublicPageExtractor;
use Tests\TestCase;

class PublicDiscoveryStoredWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DigitalAsset $website;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['locale' => 'tr']);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        app()->setLocale('tr');
        ModuleRegistry::query()->updateOrCreate(['module_id' => 'website'], ['enabled' => true]);
        $this->website = DigitalAsset::factory()->create([
            'type' => 'website', 'module_id' => 'website', 'primary_url' => 'https://example.test/', 'domain' => 'example.test',
        ]);
        Storage::fake('discovery-test');
        Bus::fake();
        Http::preventStrayRequests();
        config(['moxdop-collection.queue_connection' => 'database', 'moxdop-collection.require_queue_connection' => false]);
    }

    public function test_stored_html_produces_grouped_provenance_without_external_calls_and_reuses_unchanged_input(): void
    {
        $this->snapshot('/', $this->html(), now()->subDays(2)->toDateTimeString());
        $this->snapshot('/hizmetler/klima-bakimi', '<html lang="tr"><body><h1>Klima Bakımı</h1><p>Bakımda filtre temizliği yapılır.</p></body></html>');
        $service = app(PublicDiscoveryService::class);
        $first = $service->discover($this->website);
        $this->assertSame('succeeded', $first['status']);
        $this->assertSame(2, $first['pages_inspected']);
        $candidate = $this->candidates()->where('target_field', 'products_services')->sole();
        $this->assertSame('Klima Bakımı', $candidate->proposed_value);
        $this->assertCount(2, $candidate->support_json['sources']);
        $this->assertSame(now()->subDays(2)->toDateString(), substr($candidate->support_json['sources'][0]['observed_at'], 0, 10));
        $this->assertFalse($first['coverage']['complete_site_claim']);
        $this->assertSame(0, $first['run']->metadata['paid_requests']);
        $second = $service->discover($this->website);
        $this->assertTrue($second['cached']);
        $this->assertSame($first['run']->id, $second['run']->id);
        $this->assertSame(3, Evidence::query()->where('run_id', $first['run']->id)->count());
        Http::assertNothingSent();
    }

    public function test_navigation_addresses_and_post_urls_are_not_promoted_to_services_areas_or_profiles(): void
    {
        $extractor = app(PublicPageExtractor::class);
        $extracted = $extractor->extract('https://example.test/', '<html><body><nav><a href="/contact">Contact</a><a href="/iletisim">İletişim</a><a href="/services">Services</a><a href="/hizmetler">Hizmetlerimiz</a><a href="/about">Hakkımızda</a></nav><address>Kızılay Mahallesi, Ankara, Türkiye</address><a href="https://instagram.com/p/abc">Post</a><a href="https://youtube.com/watch?v=abc">Video</a><a href="https://x.com/clinic/status/123">Post</a><a href="https://instagram.com/clinic">Clinic</a></body></html>');
        $this->assertSame([], $extracted['service_claims']);
        $this->assertSame([], $extracted['service_area_claims']);
        $this->assertCount(1, $extracted['address_candidates']);
        $this->assertSame([['platform' => 'instagram', 'url' => 'https://instagram.com/clinic']], $extracted['social_links']);
        $english = $extractor->extract('https://example.test/services/boiler-repair', '<html><body><h1>Boiler Repair</h1><p>We repair boiler pumps.</p></body></html>');
        $this->assertSame('Boiler Repair', $english['service_claims'][0]['name']);
        $this->assertNull($extractor->normalizeSocialProfile('https://instagram.com.evil.test/clinic'));
    }

    public function test_missing_html_uses_existing_collection_and_completion_resumes_same_operation_once(): void
    {
        $async = app(AsyncOperationService::class);
        $run = $async->queuePublicDiscovery($this->website, $this->admin)['run'];
        $this->assertFalse($async->queuePublicDiscovery($this->website, $this->admin)['queued']);
        (new PublicDiscoveryJob($run->id))->handle($async);
        $run->refresh();
        $this->assertSame('awaiting_collection', $run->metadata['phase']);
        $collection = CollectionRun::query()->findOrFail($run->metadata['source_collection_run_id']);
        $this->assertSame([
            WebsiteRequestFamilyCatalog::FAMILY_HTTP_HTML_DIAGNOSIS, WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL,
        ], $collection->request_context['request_family_ids']);
        $this->assertFalse($collection->request_context['context']['paid_enrichment_consented']);
        $this->assertTrue($collection->request_context['context']['public_discovery']);
        $this->assertFalse($collection->datasetRuns()->where('provider_or_source', 'DATAFORSEO')->exists());
        (new PublicDiscoveryJob($run->id))->handle($async);
        $this->assertSame(1, CollectionRun::query()->where('idempotency_key', 'public-discovery:'.$run->id)->count());
        $this->snapshot('/', $this->html());
        $collection->update(['status' => CollectionRunStatus::Completed]);
        Bus::fake();
        event(new CollectionRunCompleted($collection));
        Bus::assertDispatchedTimes(PublicDiscoveryJob::class, 1);
        event(new CollectionRunCompleted($collection));
        Bus::assertDispatched(PublicDiscoveryJob::class, fn ($job) => $job->runId === $run->id);
        (new PublicDiscoveryJob($run->id))->handle($async);
        $this->assertSame('completed', $run->fresh()->status);
        $count = Run::query()->where('module_id', DiscoveryConfig::MODULE_ID)->count();
        (new PublicDiscoveryJob($run->id))->handle($async);
        $this->assertSame($count, Run::query()->where('module_id', DiscoveryConfig::MODULE_ID)->count());
        Http::assertNothingSent();
    }

    public function test_failed_refresh_keeps_partial_coverage_and_does_not_loop_collection(): void
    {
        $this->snapshot('/', $this->html());
        $this->snapshot('/old', $this->html(), now()->subDays(10)->toDateTimeString());
        $async = app(AsyncOperationService::class);
        $run = $async->queuePublicDiscovery($this->website, $this->admin)['run'];
        (new PublicDiscoveryJob($run->id))->handle($async);
        $collection = CollectionRun::query()->findOrFail($run->fresh()->metadata['source_collection_run_id']);
        $this->assertSame(['https://example.test/old'], $collection->request_context['context']['targeted_verification']['urls']);
        $collection->update(['status' => CollectionRunStatus::Failed]);
        (new PublicDiscoveryJob($run->id))->handle($async);
        $this->assertSame('partial', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->metadata['coverage']['uninspected_pages']);
        $this->assertSame('failed', $run->fresh()->metadata['source_collection_status']);
        $this->assertSame(1, CollectionRun::query()->where('idempotency_key', 'public-discovery:'.$run->id)->count());
    }

    public function test_missing_completion_notification_is_recovered_from_durable_collection_state(): void
    {
        $async = app(AsyncOperationService::class);
        $run = $async->queuePublicDiscovery($this->website, $this->admin)['run'];
        (new PublicDiscoveryJob($run->id))->handle($async);
        CollectionRun::query()->findOrFail($run->fresh()->metadata['source_collection_run_id'])->update(['status' => CollectionRunStatus::Cancelled]);
        Bus::fake();
        $async->markStaleRuns();
        Bus::assertDispatched(PublicDiscoveryJob::class, fn ($job) => $job->runId === $run->id);
        (new PublicDiscoveryJob($run->id))->handle($async);
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_corrupt_and_cross_asset_raw_objects_cannot_become_facts(): void
    {
        $id = $this->snapshot('/', $this->html());
        $row = DB::table('website_html_snapshot')->find($id);
        $object = RawIngestionObject::query()->findOrFail($row->raw_ingestion_object_id);
        Storage::disk('discovery-test')->put($object->object_key, 'corrupt');
        $result = app(PublicDiscoveryService::class)->discover($this->website);
        $this->assertSame('failed', $result['status']);
        $this->assertSame(1, $result['coverage']['unreadable_pages']);
        $this->assertSame(0, $this->candidates()->count());
        Storage::disk('discovery-test')->put($object->object_key, gzencode($this->html()));
        $other = DigitalAsset::factory()->create(['type' => 'website']);
        $object->resourceRun->update(['digital_asset_id' => $other->id]);
        $this->assertSame('failed', app(PublicDiscoveryService::class)->discover($this->website)['status']);
    }

    public function test_verified_redirect_alias_does_not_create_a_false_missing_homepage(): void
    {
        $this->website->update(['primary_url' => 'https://www.example.test/']);
        $id = $this->snapshot('/', $this->html());
        DB::table('website_html_snapshot')->where('id', $id)->update(['requested_url' => 'https://www.example.test/']);
        $inventory = app(StoredDiscoverySource::class)->inventory($this->website);
        $this->assertFalse($inventory['needs_collection']);
        $this->assertSame(1, $inventory['inventory_urls']);
    }

    public function test_service_acceptance_creates_real_identities_and_repeated_discovery_preserves_decisions(): void
    {
        $this->snapshot('/', $this->html());
        app(PublicDiscoveryService::class)->discover($this->website);
        $candidate = $this->candidates()->where('target_field', 'products_services')->sole();
        $language = $this->candidates()->where('target_field', 'languages')->sole();
        $reviews = app(DiscoveryCandidateReviewService::class);
        $accepted = $reviews->accept($candidate, $this->admin, 'Klima Periyodik Bakımı');
        $reviews->ignore($language, $this->admin);
        $offering = BrandOffering::query()->findOrFail($accepted->support_json['application']['record_id']);
        $this->assertSame($this->website->brand_id, $offering->brand_id);
        $this->assertSame('Klima Periyodik Bakımı', $offering->primaryName->raw_label);
        $this->assertNotNull($offering->service_catalog_item_id);
        $this->assertSame(1, ServiceCatalogItem::query()->count());
        $this->travel(1)->minutes();
        $this->snapshot('/', $this->html());
        app(PublicDiscoveryService::class)->discover($this->website);
        $this->assertSame('accepted', $candidate->fresh()->status);
        $this->assertSame($offering->id, $candidate->fresh()->support_json['application']['record_id']);
        $this->assertSame('ignored', $language->fresh()->status);
        $this->assertSame(1, BrandOffering::query()->count());
        $this->assertSame(1, $this->candidates()->where('target_field', 'products_services')->count());
    }

    public function test_mapping_preserves_service_name_priority_and_other_services(): void
    {
        $existing = app(BrandOfferingService::class)->resolveOrCreate($this->website->brand, 'İklimlendirme Bakımı', actor: $this->admin)['offering'];
        $existing->update(['priority_rank' => 2]);
        $candidate = $this->candidate('products_services', 'Klima Bakımı');
        $accepted = app(DiscoveryCandidateReviewService::class)->accept($candidate, $this->admin, options: ['offering_id' => $existing->id]);
        $this->assertSame($existing->id, $accepted->support_json['application']['record_id']);
        $this->assertSame('İklimlendirme Bakımı', $existing->fresh()->primaryName->raw_label);
        $this->assertSame(2, $existing->fresh()->priority_rank);
        $this->assertSame(1, BrandOffering::query()->count());
    }

    public function test_cross_brand_mapping_is_rejected_atomically(): void
    {
        $other = DigitalAsset::factory()->create();
        $offering = app(BrandOfferingService::class)->resolveOrCreate($other->brand, 'Repair')['offering'];
        $candidate = $this->candidate('products_services', 'Maintenance');
        try {
            app(DiscoveryCandidateReviewService::class)->accept($candidate, $this->admin, options: ['offering_id' => $offering->id]);
            $this->fail('Cross-brand mapping must fail.');
        } catch (ValidationException) {
            $this->assertSame('pending', $candidate->fresh()->status);
            $this->assertSame(0, ServiceCatalogItem::query()->count());
        }
    }

    public function test_archived_service_is_not_automatically_restored(): void
    {
        $offering = app(BrandOfferingService::class)->resolveOrCreate($this->website->brand, 'Maintenance')['offering'];
        $offering->update(['status' => OfferingStatus::Archived]);
        $this->expectException(ValidationException::class);
        app(DiscoveryCandidateReviewService::class)->accept($this->candidate('products_services', 'Maintenance'), $this->admin);
    }

    public function test_address_stays_an_observation_and_explicit_area_confirmation_adds_without_archiving_others(): void
    {
        $reviews = app(DiscoveryCandidateReviewService::class);
        $accepted = $reviews->accept($this->candidate('physical_addresses', 'Kızılay Mahallesi Ankara'), $this->admin);
        $this->assertSame('observation_only', $accepted->support_json['application']['state']);
        $this->assertSame(0, BrandServiceArea::query()->count());
        $existing = app(BrandCommercialContextService::class)->addServiceArea($this->website->brand, ['country_code' => 'TR', 'city_name' => 'İstanbul']);
        $existing->update(['priority_rank' => 1]);
        $area = $reviews->accept($this->candidate('service_areas', 'Ankara'), $this->admin, options: [
            'confirm_service_area' => true, 'country_code' => 'TR', 'city_name' => 'Ankara', 'district_name' => 'Çankaya',
        ]);
        $this->assertSame(2, BrandServiceArea::query()->where('status', 'active')->count());
        $this->assertSame(1, $existing->fresh()->priority_rank);
        $this->assertNotNull($area->support_json['application']['record_id']);
    }

    public function test_area_without_country_is_not_silently_accepted(): void
    {
        $this->expectException(ValidationException::class);
        app(DiscoveryCandidateReviewService::class)->accept($this->candidate('service_areas', 'Ankara'), $this->admin,
            options: ['confirm_service_area' => true, 'city_name' => 'Ankara']);
    }

    public function test_competitor_handoff_preserves_existing_roles_relations_and_notes(): void
    {
        $competitor = app(SearchDemandCompetitorLibraryService::class)->addManual($this->website->brand, [
            'domain' => 'rival.test', 'display_name' => 'Reviewed rival', 'is_content_competitor' => true,
            'entity_kind' => 'authority', 'notes' => 'Operator note',
        ], $this->admin);
        $area = app(BrandCommercialContextService::class)->addServiceArea($this->website->brand, ['country_code' => 'TR', 'city_name' => 'Ankara']);
        $competitor->serviceAreas()->attach($area->id, ['provenance' => 'operator']);
        $candidate = $this->candidate('known_competitors', 'rival.test');
        $accepted = app(DiscoveryCandidateReviewService::class)->accept($candidate, $this->admin);
        $this->assertSame($competitor->id, $accepted->support_json['application']['record_id']);
        $this->assertSame('Operator note', $competitor->fresh()->notes);
        $this->assertTrue($competitor->fresh()->is_content_competitor);
        $this->assertSame('authority', $competitor->fresh()->entity_kind);
        $this->assertSame([$area->id], $competitor->serviceAreas()->pluck('brand_service_areas.id')->all());
        $this->assertTrue($competitor->sources()->where('source_type', 'public_discovery')->exists());
    }

    public function test_rejected_competitor_is_not_resurrected(): void
    {
        $competitor = SearchDemandCompetitor::query()->create(['uuid' => (string) Str::uuid(), 'brand_id' => $this->website->brand_id,
            'display_name' => 'Rival', 'normalized_domain' => 'rival.test', 'normalized_domain_hash' => hash('sha256', 'rival.test'), 'status' => 'rejected']);
        $this->expectException(ValidationException::class);
        app(DiscoveryCandidateReviewService::class)->accept($this->candidate('known_competitors', $competitor->normalized_domain), $this->admin);
    }

    public function test_social_profile_is_visible_in_integrations_without_creating_an_asset_or_binding(): void
    {
        $before = DigitalAsset::query()->count();
        $candidate = $this->candidate('social_links', 'instagram: https://instagram.com/clinic/?utm_source=site');
        $accepted = app(DiscoveryCandidateReviewService::class)->accept($candidate, $this->admin);
        $this->assertSame('https://instagram.com/clinic', $accepted->support_json['application']['url']);
        Livewire::test(IntegrationsIndex::class)->assertSee('https://instagram.com/clinic')->assertSee($this->website->brand->name);
        $this->assertSame($before, DigitalAsset::query()->count());
        $this->assertSame(0, CoreAssetBinding::query()->count());
    }

    public function test_legacy_acceptance_requires_an_explicit_application_and_service_review_ui_works(): void
    {
        $candidate = $this->candidate('products_services', 'Klima Bakımı');
        $candidate->update(['status' => 'accepted', 'accepted_value' => 'Klima Bakımı', 'reviewed_at' => now()]);
        app(DiscoveryCandidateReviewService::class)->accept($candidate, $this->admin);
        $this->assertSame(0, BrandOffering::query()->count());
        Livewire::test(PublicDiscoveryPage::class, ['assetId' => (string) $this->website->id])
            ->set('filter', 'unapplied')->assertSee('hedef kayda aktarım makbuzu yok')
            ->call('reviewCandidate', $candidate->id)->set('editedValue', 'Periyodik Klima Bakımı')
            ->call('applyReview')->assertHasNoErrors()->assertSee(__('public_discovery.receipt.applied'));
        $this->assertSame(1, BrandOffering::query()->count());
        $this->assertNotNull($candidate->fresh()->applicationUrl());
    }

    public function test_scalar_replacement_requires_explicit_choice_and_matching_current_value(): void
    {
        BrandIntelligenceContext::factory()->create(['brand_id' => $this->website->brand_id, 'business_summary' => 'Operator summary']);
        $candidate = $this->candidate('business_summary', 'Site summary');
        $reviews = app(DiscoveryCandidateReviewService::class);
        $conflict = $reviews->accept($candidate, $this->admin);
        $this->assertSame('conflict', $conflict->support_json['application']['state']);
        $second = $this->candidate('business_summary', 'Reviewed replacement');
        $reviews->accept($second, $this->admin, options: ['replace_existing' => true, 'expected_current' => 'Operator summary']);
        $this->assertSame('Reviewed replacement', $this->website->brand->intelligenceContext->business_summary);
        $third = $this->candidate('business_summary', 'Outdated form');
        $this->expectException(ValidationException::class);
        $reviews->accept($third, $this->admin, options: ['replace_existing' => true, 'expected_current' => 'Operator summary']);
    }

    public function test_changed_source_cannot_be_approved_until_rediscovery_confirms_the_claim(): void
    {
        $this->snapshot('/', $this->html());
        app(PublicDiscoveryService::class)->discover($this->website);
        $candidate = $this->candidates()->where('target_field', 'products_services')->sole();
        $this->travel(1)->minutes();
        $this->snapshot('/', '<html lang="tr"><body>We no longer provide this service.</body></html>');
        $this->expectException(ValidationException::class);
        app(DiscoveryCandidateReviewService::class)->accept($candidate, $this->admin);
    }

    public function test_disabled_module_or_revoked_operator_cannot_start_or_execute_discovery(): void
    {
        $async = app(AsyncOperationService::class);
        $run = $async->queuePublicDiscovery($this->website, $this->admin)['run'];
        $this->admin->update(['is_active' => false]);
        (new PublicDiscoveryJob($run->id))->handle($async);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(0, CollectionRun::query()->count());
        $this->assertSame(0, $this->candidates()->count());
    }

    public function test_current_stored_html_job_never_starts_collection(): void
    {
        $this->snapshot('/', $this->html());
        $before = CollectionRun::query()->count();
        $async = app(AsyncOperationService::class);
        $run = $async->queuePublicDiscovery($this->website, $this->admin)['run'];
        (new PublicDiscoveryJob($run->id))->handle($async);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame($before, CollectionRun::query()->count());
        Http::assertNothingSent();
    }

    public function test_page_limit_reports_remaining_inventory_without_claiming_whole_site_success(): void
    {
        $id = $this->snapshot('/', '<html lang="tr"><body>Public page</body></html>');
        $row = (array) DB::table('website_html_snapshot')->find($id);
        unset($row['id']);
        $rows = [];
        for ($i = 1; $i <= 500; $i++) {
            $rows[] = array_merge($row, ['url' => 'https://example.test/page-'.$i,
                'requested_url' => 'https://example.test/page-'.$i, 'record_fingerprint' => hash('sha256', 'page-'.$i)]);
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('website_html_snapshot')->insert($chunk);
        }
        $result = app(StoredDiscoverySource::class)->read($this->website);
        $this->assertSame('partial', $result['status']);
        $this->assertSame(500, $result['pages_inspected']);
        $this->assertSame(501, $result['coverage']['inventory_urls']);
        $this->assertSame(1, $result['coverage']['uninspected_pages']);
        $this->assertFalse($result['coverage']['complete_site_claim']);
    }

    public function test_snapshot_hash_must_match_the_verified_raw_file(): void
    {
        $id = $this->snapshot('/', $this->html());
        DB::table('website_html_snapshot')->where('id', $id)->update(['html_hash' => hash('sha256', 'different html')]);
        $this->assertSame('failed', app(PublicDiscoveryService::class)->discover($this->website)['status']);
        $this->assertSame(0, $this->candidates()->count());
    }

    public function test_known_brand_service_heading_is_recognized_on_a_plain_wordpress_slug(): void
    {
        $this->snapshot('/', '<html lang="tr"><body>Home</body></html>');
        $this->snapshot('/klima-bakimi', '<html><body><h1>Klima Bakımı</h1><p>Filtreleri temizler ve performansı kontrol ederiz.</p></body></html>');
        app(BrandOfferingService::class)->resolveOrCreate($this->website->brand, 'Klima Bakımı');
        app(PublicDiscoveryService::class)->discover($this->website);
        $service = $this->candidates()->where('target_field', 'products_services')->sole();
        $this->assertSame('known_service_heading', $service->support_json['sources'][0]['from']);
    }

    public function test_social_channel_ids_retain_case_sensitive_url_identity(): void
    {
        $this->snapshot('/', '<html><body><a href="https://youtube.com/channel/UCExampleA">A</a><a href="https://youtube.com/channel/UCexampleA">B</a></body></html>');
        app(PublicDiscoveryService::class)->discover($this->website);
        $this->assertSame(2, $this->candidates()->where('target_field', 'social_links')->count());
    }

    public function test_review_form_can_apply_an_explicit_area_and_revisit_a_kept_scalar_conflict(): void
    {
        $area = $this->candidate('service_areas', 'Ankara');
        Livewire::test(PublicDiscoveryPage::class, ['assetId' => (string) $this->website->id])
            ->call('reviewCandidate', $area->id)->call('applyReview')->assertHasErrors('confirmServiceArea')
            ->set('confirmServiceArea', true)->set('countryCode', 'TR')->set('cityName', 'Ankara')
            ->call('applyReview')->assertHasNoErrors();
        $this->assertSame(1, BrandServiceArea::query()->count());
        BrandIntelligenceContext::query()->updateOrCreate(['brand_id' => $this->website->brand_id], ['business_summary' => 'Existing']);
        $scalar = $this->candidate('business_summary', 'Updated');
        $reviews = app(DiscoveryCandidateReviewService::class);
        $reviews->accept($scalar, $this->admin);
        Livewire::test(PublicDiscoveryPage::class, ['assetId' => (string) $this->website->id])
            ->set('filter', 'accepted')->call('reviewCandidate', $scalar->id)
            ->set('replaceExisting', true)->call('applyReview')->assertHasNoErrors();
        $this->assertSame('Updated', $this->website->brand->intelligenceContext->business_summary);
        $this->assertSame('conflict', $scalar->fresh()->support_json['application_history'][0]['state']);
    }

    public function test_http_200_error_templates_do_not_create_brand_claims(): void
    {
        $this->snapshot('/', '<html lang="tr"><head><title>WordPress Error</title></head><body>There has been a critical error on this website.</body></html>');
        $result = app(PublicDiscoveryService::class)->discover($this->website);
        $this->assertSame('failed', $result['status']);
        $this->assertSame(1, $result['coverage']['ineligible_pages']);
        $this->assertSame(0, $this->candidates()->count());
    }

    public function test_newer_redirect_replaces_older_direct_page_without_double_counting_inventory(): void
    {
        $this->snapshot('/', '<html><body>Old homepage</body></html>', now()->subDays(2)->toDateTimeString());
        $id = $this->snapshot('/home', $this->html());
        DB::table('website_html_snapshot')->where('id', $id)->update(['requested_url' => 'https://example.test/']);
        $result = app(StoredDiscoverySource::class)->read($this->website);
        $this->assertSame(1, $result['coverage']['inventory_urls']);
        $this->assertSame(1, $result['pages_inspected']);
        $this->assertSame('https://example.test/home', $result['pages'][0]['final_url']);
    }

    public function test_authenticated_operator_entry_routes_render_in_both_locales(): void
    {
        $this->get(route('operator.public-discovery'))->assertOk();
        $this->get(route('operator.website.discovery', ['assetId' => $this->website->id]))->assertOk()->assertSee('Bilgileri gözden geçir');
        $this->get(route('operator.integrations'))->assertOk()->assertSee('Keşiften gelen sosyal profiller');
        $this->admin->update(['locale' => 'en']);
        $this->get(route('operator.website.discovery', ['assetId' => $this->website->id]))->assertOk()->assertSee('Review information');
    }

    public function test_index_shows_parent_partial_when_the_newer_child_has_usable_stored_data(): void
    {
        $this->freezeTime();
        $parent = Run::query()->create(['digital_asset_id' => $this->website->id,
            'module_id' => 'public-discovery', 'status' => 'partial', 'started_at' => now(), 'finished_at' => now()]);
        $child = Run::query()->create(['digital_asset_id' => $this->website->id,
            'module_id' => DiscoveryConfig::MODULE_ID, 'status' => 'completed', 'started_at' => now(), 'finished_at' => now(),
            'metadata' => ['discovery_status' => 'succeeded', 'pages_inspected' => 1]]);
        $parent->update(['metadata' => ['child_run_ids' => [$child->id]]]);
        Livewire::test(PublicDiscoveryIndex::class)
            ->assertViewHas('rows', fn ($rows) => $rows[0]['status'] === 'partial');
    }

    private function candidates(): Builder
    {
        return DiscoveryCandidate::query()->where('digital_asset_id', $this->website->id);
    }

    private function candidate(string $field, string $value): DiscoveryCandidate
    {
        return DiscoveryCandidate::factory()->create(['brand_id' => $this->website->brand_id,
            'digital_asset_id' => $this->website->id, 'target_field' => $field, 'proposed_value' => $value]);
    }

    private function html(): string
    {
        return '<html lang="tr"><head><title>Klima Servisi</title><meta name="description" content="Ankara bölgesinde klima bakım ve onarım hizmetleri sunuyoruz."><script type="application/ld+json">{"@type":"Service","name":"Klima Bakımı","areaServed":{"@type":"City","name":"Ankara"}}</script></head><body><h1>Klima Servisi</h1><address>Kızılay Mahallesi, Ankara, Türkiye</address><a href="https://instagram.com/clinic">Instagram</a></body></html>';
    }

    private function snapshot(string $path, string $html, ?string $observedAt = null): int
    {
        $resource = CollectionResourceRun::factory()->create(['digital_asset_id' => $this->website->id, 'provider_or_source' => 'WEBSITE_DIRECT']);
        $key = (string) Str::uuid().'.html.gz';
        $bytes = gzencode($html);
        Storage::disk('discovery-test')->put($key, $bytes);
        $object = RawIngestionObject::query()->create(['uuid' => (string) Str::uuid(),
            'resource_run_id' => $resource->id, 'collection_run_id' => $resource->collection_run_id, 'dataset_id' => 'website_html_snapshot',
            'batch_key' => $key, 'provider_or_source' => 'WEBSITE_DIRECT', 'storage_disk' => 'discovery-test', 'object_key' => $key,
            'byte_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'compression' => 'gzip', 'captured_at' => $observedAt ?? now()]);

        return DB::table('website_html_snapshot')->insertGetId(['digital_asset_id' => $this->website->id,
            'url' => 'https://example.test'.$path, 'requested_url' => 'https://example.test'.$path,
            'status_code' => 200, 'content_type' => 'text/html', 'raw_ingestion_object_id' => $object->id,
            'html_hash' => hash('sha256', $html), 'html_bytes' => strlen($html), 'change_state' => 'new',
            'observed_at' => $observedAt ?? now(), 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $key)]);
    }
}
