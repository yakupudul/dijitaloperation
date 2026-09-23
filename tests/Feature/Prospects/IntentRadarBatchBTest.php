<?php

namespace Tests\Feature\Prospects;

use App\Enums\IntentClassificationStatus;
use App\Enums\IntentPurchaseStage;
use App\Enums\IntentSignalStatus;
use App\Enums\IntentSourceVerificationState;
use App\Enums\ProspectSource;
use App\Models\Prospect;
use App\Models\SalesIntentSignal;
use App\Models\SalesSearchProfile;
use App\Models\User;
use App\Services\Sales\CreateProspectFromIntentSignalService;
use App\Services\Sales\IntentQueryPlanner;
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

    public function test_signal_to_prospect_is_explicit_and_idempotent(): void
    {
        $profile = SalesSearchProfile::factory()->create(['owner_user_id' => $this->admin->id]);
        $signal = SalesIntentSignal::query()->create([
            'sales_search_profile_id' => $profile->id,
            'source_type' => 'search_result',
            'source_url' => 'https://intent-fixture.moxdop-e2e.test/agency-wanted',
            'source_title' => 'Ajans arıyoruz — web sitesi',
            'observed_snippet' => 'Web sitesi yaptırmak için bir ajans arıyoruz.',
            'source_verification_state' => IntentSourceVerificationState::Verified,
            'discovered_at' => now(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'purchase_stage' => IntentPurchaseStage::HighIntent,
            'classification_status' => IntentClassificationStatus::Available,
            'status' => IntentSignalStatus::New,
            'fingerprint' => hash('sha256', 'intent-fixture-agency-wanted'),
        ]);
        $prospect = app(CreateProspectFromIntentSignalService::class)->create($signal, $this->admin);

        $this->assertSame(ProspectSource::IntentRadar, $prospect->source);
        $this->assertStringContainsString('ajans arıyoruz', (string) $prospect->inquiry);
        $this->assertSame($prospect->id, $signal->fresh()?->prospect_id);
        $this->assertSame(IntentSignalStatus::ConvertedToProspect, $signal->fresh()?->status);

        $again = app(CreateProspectFromIntentSignalService::class)->create($signal->fresh(), $this->admin);
        $this->assertSame($prospect->id, $again->id);
        $this->assertSame(1, Prospect::query()->count());
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
