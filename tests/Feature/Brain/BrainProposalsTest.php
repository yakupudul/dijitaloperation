<?php

namespace Tests\Feature\Brain;

use App\Livewire\Operator\Brain\ProposalsPage;
use App\Models\BrainProposal;
use App\Models\SearchQueryLibraryItem;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Brain\Proposals\Kinds\MatchingKeywordKind;
use App\Services\Brain\Proposals\ProposalService;
use App\Services\SearchDemand\ServiceCatalogService;
use App\Services\SearchDemand\ServiceKeywordService;
use App\Services\SeoTasks\SeoText;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_rejected_proposals_are_not_offered_again_and_page_approves_in_bulk(): void
    {
        foreach (['zirkonyum kaplama fiyat', 'zirkonyum kaplama kaç yıl', 'zirkonyum kaplama ömrü'] as $text) {
            $this->libraryQuery($text)->services()->attach($this->whitening->id, ['is_primary' => true, 'provenance' => 'operator']);
        }
        foreach (['tel tedavisi fiyat', 'tel tedavisi kaç ay', 'tel tedavisi ağrı'] as $text) {
            $this->libraryQuery($text)->services()->attach($this->ortho->id, ['is_primary' => true, 'provenance' => 'operator']);
        }
        app(MatchingKeywordKind::class)->prepare([]);
        $proposals = BrainProposal::query()->where('kind', 'matching_keyword')->orderBy('id')->get()->keyBy('proposed.label');
        $this->assertTrue($proposals->has('zirkonyum kaplama'));
        $this->assertTrue($proposals->has('tel tedavisi'));

        Livewire::actingAs($this->admin)->test(ProposalsPage::class)
            ->set('selected', [$proposals['zirkonyum kaplama']->id])->call('rejectSelected')
            ->assertSet('selected', []);
        $this->assertSame(BrainProposal::STATUS_REJECTED, $proposals['zirkonyum kaplama']->fresh()->status);
        app(MatchingKeywordKind::class)->prepare([]);
        $this->assertSame(1, BrainProposal::query()->where('kind', 'matching_keyword')->where('proposed->label', 'zirkonyum kaplama')->count(), 'rejected is not proposed again');

        Livewire::actingAs($this->admin)->test(ProposalsPage::class)
            ->set('min', 50)->call('approveAboveMin')->assertOk();
        $this->assertSame(BrainProposal::STATUS_APPLIED, $proposals['tel tedavisi']->fresh()->status);
        $this->assertContains('tel tedavisi', $this->ortho->matchingKeywords()->pluck('label')->all());

        $this->actingAs($this->admin)->get(route('operator.brain.proposals', ['status' => 'all']))->assertOk()
            ->assertSee('Onay kuyruğu')->assertSee('tel tedavisi');
        $this->actingAs($this->admin)->get(route('operator.library.search-queries'))->assertOk()->assertSee('Sorgular');
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

    private function libraryQuery(string $text): SearchQueryLibraryItem
    {
        return SearchQueryLibraryItem::query()->create([
            'uuid' => (string) Str::uuid(), 'identity_hash' => hash('sha256', $text), 'canonical_text' => $text, 'folded_text' => SeoText::fold($text),
            'sector' => 'saglik', 'status' => 'active', 'is_branded' => false, 'normalization_version' => 'v1', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }
}
