<?php

namespace Tests\Feature\Prospects;

use App\Enums\IntentClassificationStatus;
use App\Enums\IntentPurchaseStage;
use App\Enums\IntentRadarRunStatus;
use App\Enums\IntentSignalStatus;
use App\Enums\IntentSourceVerificationState;
use App\Enums\ProspectIdentityStatus;
use App\Enums\ProspectSource;
use App\Models\Prospect;
use App\Models\SalesIntentSignal;
use App\Models\SalesSearchProfile;
use App\Models\User;
use App\Services\Sales\CreateProspectFromIntentSignalService;
use App\Services\Sales\IntentClassificationService;
use App\Services\Sales\IntentQueryPlanner;
use App\Services\Sales\IntentRadarService;
use App\Services\Sales\SearchProfileService;
use App\Support\Roles;
use App\Support\Sales\IntentSearchConfig;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntentRadarBatchBTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        Http::preventStrayRequests();
    }

    public function test_search_profile_crud_and_bounded_queries(): void
    {
        $service = app(SearchProfileService::class);
        $profile = $service->create([
            'name' => 'Web Sitesi Arayan İşletmeler',
            'service_definition_code' => 'website_design',
            'language' => 'tr',
            'country' => 'TR',
            'include_concepts' => [
                'web sitesi yaptırmak',
                'web tasarım ajansı arıyoruz',
                'extra 1',
                'extra 2',
                'extra 3',
                'extra 4',
                'extra 5',
            ],
            'exclude_concepts' => ['nasıl yapılır', 'ücretsiz'],
            'minimum_intent_confidence' => 60,
            'active' => true,
            'owner_user_id' => $this->admin->id,
        ], $this->admin);

        $this->assertDatabaseHas('sales_search_profiles', [
            'id' => $profile->id,
            'name' => 'Web Sitesi Arayan İşletmeler',
            'active' => 1,
            'owner_user_id' => $this->admin->id,
        ]);

        $updated = $service->update($profile, [
            'name' => 'Web Sitesi Arayan İşletmeler',
            'service_definition_code' => 'website_design',
            'include_concepts' => ['web sitesi yaptırmak'],
            'exclude_concepts' => ['nasıl yapılır'],
            'active' => true,
        ]);

        $plan = app(IntentQueryPlanner::class)->plan($updated);
        $this->assertLessThanOrEqual(IntentSearchConfig::MAX_QUERIES_PER_RUN, count($plan));
        $this->assertContains('web sitesi yaptırmak', $plan);
    }

    public function test_paid_calls_default_off_and_run_requires_consent(): void
    {
        $this->assertFalse(config('moxdop.sales_intent_discovery.paid_calls_enabled'));

        $profile = SalesSearchProfile::factory()->create(['owner_user_id' => $this->admin->id]);
        config(['moxdop.sales_intent_discovery.fixtures' => false]);

        $run = app(IntentRadarService::class)->run($profile, $this->admin, paidConsent: false);
        $this->assertSame(IntentRadarRunStatus::Failed, $run->status);
        $this->assertFalse($run->paid_call);
        $this->assertSame(0, SalesIntentSignal::query()->count());
        $this->assertSame(0, Prospect::query()->count());

        config(['moxdop.sales_intent_discovery.paid_calls_enabled' => true]);
        $blocked = app(IntentRadarService::class)->run($profile->fresh(), $this->admin, paidConsent: false);
        $this->assertSame(IntentRadarRunStatus::Failed, $blocked->status);
        $this->assertStringContainsString('consent', strtolower((string) json_encode($blocked->error_summary)));
    }

    public function test_fixture_run_creates_high_and_informational_signals_without_prospects(): void
    {
        config(['moxdop.sales_intent_discovery.fixtures' => true]);
        $profile = SalesSearchProfile::factory()->create([
            'owner_user_id' => $this->admin->id,
            'include_concepts' => ['web sitesi yaptırmak'],
        ]);

        $run = app(IntentRadarService::class)->run($profile, $this->admin);
        $this->assertSame(IntentRadarRunStatus::Completed, $run->status);
        $this->assertFalse($run->paid_call);
        $this->assertSame('partial', $run->provider_reality);
        $this->assertSame(2, $run->signal_count);
        $this->assertSame(0, Prospect::query()->count());

        $high = SalesIntentSignal::query()->where('purchase_stage', IntentPurchaseStage::HighIntent)->first();
        $info = SalesIntentSignal::query()->where('purchase_stage', IntentPurchaseStage::Informational)->first();
        $this->assertNotNull($high);
        $this->assertNotNull($info);
        $this->assertSame('Web sitesi yaptırmak için bir ajans arıyoruz.', $high->observed_snippet);
        $this->assertSame('Web sitesi nasıl yapılır?', $info->observed_snippet);
        $this->assertNotSame($high->observed_snippet, $high->fetched_source_excerpt);
        $this->assertSame(IntentSourceVerificationState::Verified, $high->source_verification_state);
        $this->assertSame(ProspectIdentityStatus::Unknown, $high->identity_status);
        $this->assertNull($high->detected_company_name);
        $this->assertSame('website_design', $high->service_definition_code);
        $this->assertSame(IntentClassificationStatus::Available, $high->classification_status);
        $this->assertArrayHasKey('snippet_vs_fetch', $high->provenance ?? []);
    }

    public function test_fingerprint_deduplicates_across_runs(): void
    {
        config(['moxdop.sales_intent_discovery.fixtures' => true]);
        $profile = SalesSearchProfile::factory()->create(['owner_user_id' => $this->admin->id]);

        app(IntentRadarService::class)->run($profile, $this->admin);
        $first = SalesIntentSignal::query()->orderBy('id')->first();
        $this->assertNotNull($first);

        app(IntentRadarService::class)->run($profile->fresh(), $this->admin);

        $this->assertSame(2, SalesIntentSignal::query()->count());
        $updated = $first->fresh();
        $this->assertNotNull($updated?->last_seen_at);
        $this->assertSame($first->first_seen_at?->toIso8601String(), $updated?->first_seen_at?->toIso8601String());
    }

    public function test_signal_to_prospect_is_explicit_and_idempotent(): void
    {
        config(['moxdop.sales_intent_discovery.fixtures' => true]);
        $profile = SalesSearchProfile::factory()->create(['owner_user_id' => $this->admin->id]);
        app(IntentRadarService::class)->run($profile, $this->admin);

        $signal = SalesIntentSignal::query()->where('purchase_stage', IntentPurchaseStage::HighIntent)->firstOrFail();
        $prospect = app(CreateProspectFromIntentSignalService::class)->create($signal, $this->admin);

        $this->assertSame(ProspectSource::IntentRadar, $prospect->source);
        $this->assertStringContainsString('ajans arıyoruz', (string) $prospect->inquiry);
        $this->assertSame($prospect->id, $signal->fresh()?->prospect_id);
        $this->assertSame(IntentSignalStatus::ConvertedToProspect, $signal->fresh()?->status);

        $again = app(CreateProspectFromIntentSignalService::class)->create($signal->fresh(), $this->admin);
        $this->assertSame($prospect->id, $again->id);
        $this->assertSame(1, Prospect::query()->count());
    }

    public function test_classification_unavailable_without_fixtures_or_ai(): void
    {
        config([
            'moxdop.sales_intent_discovery.fixtures' => false,
            'moxdop.openai.api_key' => null,
            'ai.providers.openai.key' => null,
        ]);

        $profile = SalesSearchProfile::factory()->create(['owner_user_id' => $this->admin->id]);
        $classified = app(IntentClassificationService::class)->classify($profile, 'Web sitesi yaptırmak için bir ajans arıyoruz.');

        $this->assertSame(IntentClassificationStatus::Unavailable, $classified['classification_status']);
        $this->assertNull($classified['intent_confidence']);
        $this->assertSame(IntentPurchaseStage::Unknown, $classified['purchase_stage']);
    }

    public function test_operator_pages_require_auth_and_list_search_profiles(): void
    {
        $this->get('/prospects/search-profiles')->assertRedirect('/login');
        $this->get('/prospects/intent-radar')->assertRedirect('/login');

        $profile = SalesSearchProfile::factory()->create([
            'name' => 'Visible Profile',
            'owner_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get('/prospects/search-profiles')
            ->assertOk()
            ->assertSee('Visible Profile');

        $this->actingAs($this->admin)
            ->get('/prospects/search-profiles/'.$profile->id)
            ->assertOk()
            ->assertSee(__('operator.sales_intent.run_search'));
    }
}
