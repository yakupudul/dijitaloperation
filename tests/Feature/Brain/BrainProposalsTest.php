<?php

namespace Tests\Feature\Brain;

use App\Ai\Agents\Brain\AccountMappingAgent;
use App\Ai\Agents\Brain\QueryServiceClassifierAgent;
use App\Livewire\Operator\Brain\ProposalsPage;
use App\Models\BrainProposal;
use App\Models\CoreExternalResource;
use App\Models\ResourceAutomation;
use App\Models\SearchQueryLibraryItem;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Brain\Proposals\Kinds\AccountMappingKind;
use App\Services\Brain\Proposals\Kinds\MatchingKeywordKind;
use App\Services\Brain\Proposals\Kinds\QueryServiceKind;
use App\Services\Brain\Proposals\ProposalService;
use App\Services\SearchDemand\ServiceCatalogService;
use App\Services\SearchDemand\ServiceKeywordService;
use App\Services\SeoTasks\SeoText;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Livewire\Livewire;
use Tests\TestCase;

/** Brain phase 1: the review queue, the four-tier query → service assignment, account mapping and learned expressions. */
final class BrainProposalsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceCatalogItem $implant;

    private ServiceCatalogItem $ortho;

    private ServiceCatalogItem $whitening;

    private string $lawSector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
        $this->lawSector = (string) ServiceCategory::query()->firstOrCreate(['normalized_key' => 'hukuk'], ['code' => 'hukuk', 'name' => 'Hukuk'])->code;
        $catalog = app(ServiceCatalogService::class);
        $this->implant = $catalog->resolveOrCreate('İmplant Tedavisi', 'saglik', actor: $this->admin)['service'];
        $this->ortho = $catalog->resolveOrCreate('Ortodonti', 'saglik', actor: $this->admin)['service'];
        $this->whitening = $catalog->resolveOrCreate('Diş Beyazlatma', 'saglik', actor: $this->admin)['service'];
        app(ServiceKeywordService::class)->append($this->implant, ['implant']);
        app(ServiceKeywordService::class)->append($this->ortho, ['diş teli']);
    }

    public function test_query_assignment_uses_rules_then_similarity_then_ai_and_applies_on_approval(): void
    {
        $this->enableAi();
        $this->fakeEmbeddings();
        $rule = $this->libraryQuery('implant fiyatları');
        $vector = $this->libraryQuery('gülüş beyazlatma');
        $unclear = $this->libraryQuery('dişlerim sarardı');
        $none = $this->libraryQuery('en yakın eczane');
        QueryServiceClassifierAgent::fake([['items' => [
            ['query_id' => $unclear->id, 'service_id' => $this->whitening->id, 'intent' => 'informational', 'reason' => 'Renk değişimi beyazlatma ile ilgili.'],
            ['query_id' => $none->id, 'service_id' => null, 'intent' => 'local', 'reason' => 'Hizmetle ilgisi yok.'],
        ]]]);

        $created = app(QueryServiceKind::class)->prepare([]);

        $this->assertSame(2, $created);
        $this->assertSame('keyword_match', $rule->services()->first()?->pivot->provenance, 'one rule hit is filed directly');
        $byQuery = BrainProposal::query()->where('kind', 'query_service')->where('status', BrainProposal::STATUS_PENDING)->get()->keyBy('subject_id');
        $this->assertSame(1, BrainProposal::query()->where('status', BrainProposal::STATUS_NOTHING)->where('subject_id', $none->id)->count(), 'remembered so AI is not asked again');
        $this->assertSame('vector', $byQuery[$vector->id]->source);
        $this->assertSame($this->whitening->id, $byQuery[$vector->id]->proposed['service_id']);
        $this->assertGreaterThanOrEqual(0.8, $byQuery[$vector->id]->confidence);
        $this->assertSame('ai', $byQuery[$unclear->id]->source);
        $this->assertSame(0.45, $byQuery[$unclear->id]->confidence, 'AI disagreeing with the similarity model gets low confidence');
        $this->assertFalse($byQuery->has($none->id), 'AI "none" is not proposed');
        $this->assertSame(0, $vector->services()->count(), 'nothing changes before approval');

        $result = app(ProposalService::class)->approve($byQuery->pluck('id')->all(), $this->admin);

        $this->assertSame(['applied' => 2, 'failed' => 0], $result);
        $this->assertSame('brain_review', $vector->services()->first()->pivot->provenance);
        $this->assertSame('informational', $unclear->fresh()->search_intent);
        $this->assertSame(0, app(QueryServiceKind::class)->prepare([]), 'applied proposals are not offered again');
    }

    public function test_rejected_proposals_are_not_offered_again_and_page_approves_in_bulk(): void
    {
        $this->enableAi();
        $this->fakeEmbeddings();
        $first = $this->libraryQuery('gülüş beyazlatma');
        $second = $this->libraryQuery('beyaz dişler');
        QueryServiceClassifierAgent::fake([['items' => []]]);
        app(QueryServiceKind::class)->prepare([]);
        $proposals = BrainProposal::query()->orderBy('subject_id')->get();
        $this->assertCount(2, $proposals);

        Livewire::actingAs($this->admin)->test(ProposalsPage::class)
            ->set('selected', [$proposals[0]->id])->call('rejectSelected')
            ->assertSet('selected', []);
        $this->assertSame(BrainProposal::STATUS_REJECTED, $proposals[0]->fresh()->status);
        $this->assertSame(0, app(QueryServiceKind::class)->prepare([]), 'rejected is not proposed again');

        Livewire::actingAs($this->admin)->test(ProposalsPage::class)
            ->set('min', 80)->call('approveAboveMin')->assertOk();
        $this->assertSame(BrainProposal::STATUS_APPLIED, $proposals[1]->fresh()->status);
        $this->assertSame([$this->whitening->id], $second->services()->pluck('service_catalog_items.id')->all());
        $this->assertSame(0, $first->services()->count());

        $this->actingAs($this->admin)->get(route('operator.brain.proposals', ['status' => 'all']))->assertOk()
            ->assertSee('Onay kuyruğu')->assertSee('AI ile hazırla')->assertSee('gülüş beyazlatma');
        $this->actingAs($this->admin)->get(route('operator.library.search-queries'))->assertOk()->assertSee('Sorgu → hizmet ataması');
    }

    public function test_account_mapping_is_checked_against_matching_expressions_and_saved_on_approval(): void
    {
        $this->enableAi();
        $resource = CoreExternalResource::factory()->create(['resource_type' => 'search_console', 'external_id' => 'sc-domain:klinik.test', 'display_name' => 'klinik.test']);
        $automation = ResourceAutomation::query()->create(['external_resource_id' => $resource->id]);
        foreach ([['implant fiyatları', 300], ['implant yapan yerler', 100], ['klinik adı', 100]] as $i => [$text, $impressions]) {
            DB::table('gsc_query_daily')->insert([
                'digital_asset_id' => null, 'external_resource_id' => $resource->id, 'site_url' => 'sc-domain:klinik.test', 'reporting_date' => now()->subDays($i + 1)->toDateString(),
                'query' => $text, 'clicks' => 5, 'impressions' => $impressions, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
                'record_fingerprint' => hash('sha256', $text), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $lawService = app(ServiceCatalogService::class)->resolveOrCreate('Boşanma Davası', $this->lawSector, actor: $this->admin)['service'];
        AccountMappingAgent::fake([['items' => [
            ['account_id' => $automation->id, 'sector' => 'saglik', 'service_ids' => [$this->implant->id, $lawService->id], 'reason' => 'Sorgular implant üzerine.'],
        ]]]);

        $this->assertSame(1, app(AccountMappingKind::class)->prepare([]));

        $proposal = BrainProposal::query()->where('kind', 'account_mapping')->firstOrFail();
        $this->assertSame([$this->implant->id], $proposal->proposed['service_ids'], 'a service from another sector is dropped');
        $this->assertSame(0.8, $proposal->confidence, 'confidence = impressions the expressions cover (400 of 500), not the model opinion');
        $this->assertNull($automation->fresh()->sector);

        app(ProposalService::class)->approve([$proposal->id], $this->admin);

        $automation->refresh();
        $this->assertSame('saglik', $automation->sector);
        $this->assertSame([$this->implant->id], array_map('intval', $automation->service_ids));
        $this->assertTrue($automation->query_enabled);
    }

    public function test_matching_expressions_are_learned_from_approved_queries_without_ai(): void
    {
        foreach (['zirkonyum kaplama fiyat', 'zirkonyum kaplama kaç yıl', 'zirkonyum kaplama ömrü', 'lamina ve zirkonyum farkı'] as $text) {
            $this->libraryQuery($text)->services()->attach($this->whitening->id, ['is_primary' => true, 'provenance' => 'operator']);
        }
        $this->libraryQuery('implant zirkonyum üst yapı')->services()->attach($this->implant->id, ['is_primary' => true, 'provenance' => 'operator']);

        app(MatchingKeywordKind::class)->prepare([]);

        $proposals = BrainProposal::query()->where('kind', 'matching_keyword')->get();
        $labels = $proposals->pluck('proposed.label')->all();
        $this->assertContains('zirkonyum kaplama', $labels, '3 queries, only under this service');
        $this->assertNotContains('zirkonyum', $labels, 'also used by another service: precision 4/5 is too low');
        $this->assertNotContains('kaplama', $labels, 'inside the kept phrase and catches nothing more');

        app(ProposalService::class)->approve($proposals->where('proposed.label', 'zirkonyum kaplama')->pluck('id')->all(), $this->admin);
        $this->assertContains('zirkonyum kaplama', $this->whitening->matchingKeywords()->pluck('label')->all());
    }

    private function enableAi(): void
    {
        config(['moxdop.openai.api_key' => 'sk-test', 'moxdop.anthropic.api_key' => 'sk-ant-test']);
    }

    private function fakeEmbeddings(): void
    {
        Embeddings::fake(function (EmbeddingsPrompt $prompt): array {
            return array_map(function (string $text): array {
                return match (true) {
                    str_contains($text, 'implant') => [1.0, 0.0, 0.0],
                    str_contains($text, 'beyaz') => [0.0, 0.0, 1.0],
                    str_contains($text, 'ortodonti'), str_contains($text, 'tel') => [0.0, 1.0, 0.0],
                    default => [0.5, 0.45, 0.4],
                };
            }, $prompt->inputs);
        });
    }

    private function libraryQuery(string $text): SearchQueryLibraryItem
    {
        return SearchQueryLibraryItem::query()->create([
            'uuid' => (string) Str::uuid(), 'identity_hash' => hash('sha256', $text), 'canonical_text' => $text, 'folded_text' => SeoText::fold($text),
            'sector' => 'saglik', 'status' => 'active', 'is_branded' => false, 'normalization_version' => 'v1', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }
}
