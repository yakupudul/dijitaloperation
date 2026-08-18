<?php

namespace Tests\Feature\Prospects;

use App\Enums\ProspectEvidenceProvenance;
use App\Enums\ProspectIdentityStatus;
use App\Enums\ProspectSalesIntelligenceStatus;
use App\Enums\ProspectSource;
use App\Enums\ProspectStatus;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Prospect;
use App\Models\ProspectDiscoveryCandidate;
use App\Models\ProspectEvidence;
use App\Models\ProspectSalesIntelligence;
use App\Models\User;
use App\Services\Prospects\CreateProspectService;
use App\Services\Prospects\ProspectResearchService;
use App\Services\Prospects\ProspectWebsiteValidator;
use App\Services\Prospects\UpdateProspectService;
use App\Support\Agents\AgentProfileKeys;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Prospects\ProspectResearchConfig;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class ProspectSalesAssistantBatchATest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
    }

    public function test_sales_agent_and_route_registered(): void
    {
        $profile = app(AgentProfileRegistry::class)->get(AgentProfileKeys::SALES_PROSPECT_INTELLIGENCE_ANALYST);
        $this->assertSame('sales.prospect_intelligence_analyst', $profile->slug);
        $this->assertSame(AiRouteKeys::SALES_PROSPECT_INTELLIGENCE, $profile->aiRouteKey);

        $route = app(AiRouteRegistry::class)->get(AiRouteKeys::SALES_PROSPECT_INTELLIGENCE);
        $this->assertNotNull($route);
        $this->assertSame('sales', $route['module'] ?? $route->module ?? 'sales');
    }

    public function test_create_prospect_with_owner_and_source(): void
    {
        $prospect = app(CreateProspectService::class)->create([
            'company_name' => 'ABC Dental',
            'source' => ProspectSource::WhatsApp->value,
            'inquiry' => 'Need website and Google Ads help.',
            'owner_user_id' => $this->admin->id,
        ], $this->admin);

        $this->assertSame('ABC Dental', $prospect->company_name);
        $this->assertSame(ProspectSource::WhatsApp, $prospect->source);
        $this->assertSame(ProspectIdentityStatus::Unknown, $prospect->identity_status);
        $this->assertSame(ProspectStatus::New, $prospect->status);
        $this->assertSame($this->admin->id, $prospect->owner_user_id);
    }

    public function test_website_ssrf_rejection_on_create(): void
    {
        $this->expectException(ValidationException::class);

        app(CreateProspectService::class)->create([
            'company_name' => 'Unsafe Co',
            'source' => ProspectSource::Manual->value,
            'website_url' => 'http://127.0.0.1/private',
        ], $this->admin);
    }

    public function test_update_prospect_status_and_identity(): void
    {
        $prospect = Prospect::factory()->create([
            'status' => ProspectStatus::New,
            'identity_status' => ProspectIdentityStatus::Unknown,
        ]);

        $updated = app(UpdateProspectService::class)->update($prospect, [
            'status' => ProspectStatus::Qualified->value,
            'identity_status' => ProspectIdentityStatus::Partial->value,
        ], $this->admin);

        $this->assertSame(ProspectStatus::Qualified, $updated->status);
        $this->assertSame(ProspectIdentityStatus::Partial, $updated->identity_status);
    }

    public function test_research_persists_observed_evidence_with_idempotency(): void
    {
        config(['moxdop.prospect_research.fixtures' => true]);

        $prospect = Prospect::factory()->create([
            'website_url' => 'http://'.ProspectResearchConfig::FIXTURE_HOST.'/',
            'status' => ProspectStatus::New,
        ]);

        $run = app(ProspectResearchService::class)->queue($prospect, $this->admin);
        app(ProspectResearchService::class)->execute($run->fresh(), $this->admin);

        $this->assertDatabaseCount('prospect_evidence', 2);
        $this->assertTrue(
            ProspectEvidence::query()->where('provenance', ProspectEvidenceProvenance::Observed)->exists()
        );
        $this->assertGreaterThan(0, ProspectDiscoveryCandidate::query()->count());

        $secondRun = app(ProspectResearchService::class)->queue($prospect->fresh(), $this->admin);
        app(ProspectResearchService::class)->execute($secondRun->fresh(), $this->admin);

        $this->assertDatabaseCount('prospect_evidence', 2);
    }

    public function test_research_without_website_is_partial_and_intelligence_fixture_available(): void
    {
        config(['moxdop.prospect_research.fixtures' => true]);

        $prospect = Prospect::factory()->create([
            'website_url' => null,
            'inquiry' => 'Google Ads support',
        ]);

        $run = app(ProspectResearchService::class)->queue($prospect, $this->admin);
        $finished = app(ProspectResearchService::class)->execute($run->fresh(), $this->admin);

        $this->assertSame('partial', $finished->status->value);
        $this->assertDatabaseHas('prospect_sales_intelligence', [
            'prospect_id' => $prospect->id,
            'status' => ProspectSalesIntelligenceStatus::Available->value,
        ]);
    }

    public function test_ai_unavailable_without_provider_still_completes_observed_research(): void
    {
        config([
            'moxdop.prospect_research.fixtures' => false,
            'moxdop.openai.api_key' => null,
            'ai.providers.openai.key' => null,
        ]);

        Http::fake([
            'http://1.1.1.1*' => Http::response('<html><head><title>Clinic</title></head><body><h1>Clinic</h1><nav><a href="/services">Services</a></nav></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $prospect = Prospect::factory()->create([
            'website_url' => 'http://1.1.1.1',
        ]);

        $run = app(ProspectResearchService::class)->queue($prospect, $this->admin);
        app(ProspectResearchService::class)->execute($run->fresh(), $this->admin);

        $this->assertTrue(ProspectEvidence::query()->exists());
        $this->assertDatabaseHas('prospect_sales_intelligence', [
            'prospect_id' => $prospect->id,
            'status' => ProspectSalesIntelligenceStatus::Unavailable->value,
        ]);
    }

    public function test_research_does_not_create_customer_brand_or_digital_asset(): void
    {
        config(['moxdop.prospect_research.fixtures' => true]);

        $customerBefore = Customer::query()->count();
        $brandBefore = Brand::query()->count();
        $assetBefore = DigitalAsset::query()->count();

        $prospect = Prospect::factory()->withWebsite('http://'.ProspectResearchConfig::FIXTURE_HOST.'/')->create();
        $run = app(ProspectResearchService::class)->queue($prospect, $this->admin);
        app(ProspectResearchService::class)->execute($run->fresh(), $this->admin);

        $this->assertSame($customerBefore, Customer::query()->count());
        $this->assertSame($brandBefore, Brand::query()->count());
        $this->assertSame($assetBefore, DigitalAsset::query()->count());
    }

    public function test_prospect_pages_require_authentication(): void
    {
        $this->get('/app/prospects')->assertRedirect('/app/login');
        $this->get('/app/prospects/create')->assertRedirect('/app/login');
    }

    public function test_authenticated_operator_can_open_prospect_pages(): void
    {
        $this->actingAs($this->admin)
            ->get('/app/prospects')
            ->assertOk()
            ->assertSee(__('operator.nav.prospects'));

        $this->actingAs($this->admin)
            ->get('/app/prospects/create')
            ->assertOk()
            ->assertSee(__('operator.prospects.new_prospect'));
    }

    public function test_prospect_website_validator_rejects_private_host(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ProspectWebsiteValidator::class)->assertSafe('http://localhost/test');
    }

    public function test_sales_intelligence_maps_catalog_service_codes_in_fixture_mode(): void
    {
        config(['moxdop.prospect_research.fixtures' => true]);

        $prospect = Prospect::factory()->withWebsite('http://'.ProspectResearchConfig::FIXTURE_HOST.'/')->create();
        $run = app(ProspectResearchService::class)->queue($prospect, $this->admin);
        app(ProspectResearchService::class)->execute($run->fresh(), $this->admin);

        $intelligence = ProspectSalesIntelligence::query()->where('prospect_id', $prospect->id)->first();
        $this->assertNotNull($intelligence);
        $codes = collect($intelligence->recommended_services ?? [])->pluck('service_definition_code')->all();
        $this->assertContains('google_ads', $codes);
        $this->assertContains('website_design', $codes);
    }
}
