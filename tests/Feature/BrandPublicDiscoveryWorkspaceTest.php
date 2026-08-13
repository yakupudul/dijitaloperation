<?php

namespace Tests\Feature;

use App\Livewire\Demo\Portfolio\BrandShow;
use App\Models\User;
use App\Support\Demo\BrandPublicDiscoveryFixtures;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BrandPublicDiscoveryWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        DemoState::reset();
    }

    public function test_public_discovery_sections_and_overview(): void
    {
        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID, 'tab' => 'discovery'])
            ->assertSee('Public Discovery')
            ->assertSee('Observed Facts')
            ->assertSee('Candidates')
            ->assertSee('Conflicts')
            ->assertSee('Sources & History')
            ->assertSee('Observed facts')
            ->assertSee('Awaiting review')
            ->assertSee('Public identity')
            ->assertSee('Phone')
            ->assertSee('Conflict')
            ->assertSee('Source coverage')
            ->assertSee('Google Business Profile')
            ->assertSee('Not connected')
            ->assertDontSee('Discovery Score')
            ->assertDontSee('AI confidence');
    }

    public function test_observed_facts_show_provenance_not_canonical_truth(): void
    {
        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID, 'tab' => 'discovery'])
            ->call('setDiscovery', 'facts')
            ->assertSee('Observed value')
            ->assertSee('+90 312 000 00 00')
            ->assertSee('+90 312 555 01 01')
            ->assertSee('Provider')
            ->assertSee('Website observation')
            ->assertSee('do not equal canonical Brand Context');
    }

    public function test_map_to_existing_avoids_duplicate_offering_and_records_history(): void
    {
        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID, 'tab' => 'discovery'])
            ->call('setDiscovery', 'candidates')
            ->call('openCandidate', 'dc-offering-implant')
            ->call('mapDiscoveryCandidate', 'dc-offering-implant', 'Implant Treatment')
            ->assertSee('Implant Treatment');

        $state = DemoState::all();
        $candidate = collect($state['discovery_candidates'])->firstWhere('id', 'dc-offering-implant');
        $this->assertSame('mapped', $candidate['status']);
        $this->assertSame('Implant Treatment', $candidate['mapped_to']);

        $history = collect($state['discovery_history']);
        $this->assertTrue($history->contains(fn (array $row): bool => ($row['action'] ?? '') === 'accepted'
            && str_contains((string) ($row['detail'] ?? ''), 'Implant Treatment')));

        // Brand Context offerings list is not silently grown with a duplicate "Dental Implant".
        $context = DemoCatalog::brandBusinessContext();
        $labels = collect($context['products_services'] ?? $context['priority_offerings'] ?? [])
            ->map(fn ($v) => is_array($v) ? ($v['label'] ?? $v['name'] ?? '') : (string) $v)
            ->implode(' ');
        $this->assertStringNotContainsString('Dental Implant', $labels.' '.json_encode($state['brands'] ?? []));
    }

    public function test_accept_and_ignore_are_human_reviewed_with_history(): void
    {
        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID, 'tab' => 'discovery'])
            ->call('acceptDiscoveryCandidate', 'dc-location-cankaya')
            ->set('ignoreReason', 'outdated')
            ->call('ignoreDiscoveryCandidate', 'dc-offering-smile');

        $state = DemoState::all();
        $this->assertSame('accepted', collect($state['discovery_candidates'])->firstWhere('id', 'dc-location-cankaya')['status']);
        $ignored = collect($state['discovery_candidates'])->firstWhere('id', 'dc-offering-smile');
        $this->assertSame('ignored', $ignored['status']);
        $this->assertSame('outdated', $ignored['ignore_reason']);
    }

    public function test_conflict_resolution_does_not_write_providers(): void
    {
        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID, 'tab' => 'discovery'])
            ->call('setDiscovery', 'conflicts')
            ->assertSee('Primary phone')
            ->assertSee('+90 312 555 01 01')
            ->assertSee('+90 312 000 00 00')
            ->call('openConflict', 'conflict-phone')
            ->assertSee('Keep canonical')
            ->call('resolveConflict', 'conflict-phone', 'keep_canonical')
            ->assertSee('no provider write');

        $state = DemoState::all();
        $this->assertSame('keep_canonical', $state['discovery_conflict_resolutions']['conflict-phone']['decision']);
    }

    public function test_no_cross_brand_leakage_of_discovery_fixtures(): void
    {
        $state = DemoState::all();
        $state['brands'][] = [
            'id' => 'other-brand-pd',
            'customer_id' => DemoCatalog::CUSTOMER_ID,
            'name' => 'Other Brand PD',
            'sector' => 'dental',
            'primary_country' => 'TR',
            'target_markets' => ['TR'],
            'languages' => ['tr'],
            'responsible_user_ids' => [],
            'assets_count' => 0,
            'connected_assets' => 0,
            'open_findings' => 0,
            'open_tasks' => 0,
            'context_completed' => 0,
            'context_total' => 8,
        ];
        DemoState::put($state);

        Livewire::test(BrandShow::class, ['brand' => 'other-brand-pd', 'tab' => 'discovery'])
            ->assertSee('Public Discovery')
            ->assertSee('No cross-Brand leakage')
            ->assertDontSee('Dental Implant')
            ->assertDontSee('+90 312 000 00 00');
    }

    public function test_fixtures_are_deterministic_and_distinguish_observation_layers(): void
    {
        $a = BrandPublicDiscoveryFixtures::workspace();
        $b = BrandPublicDiscoveryFixtures::workspace();
        $this->assertSame($a, $b);

        $implant = collect($a['candidates'])->firstWhere('id', 'dc-offering-implant');
        $this->assertSame('Derived', $implant['provenance']);
        $this->assertNull($implant['confidence']);

        $positioning = collect($a['candidates'])->firstWhere('id', 'dc-positioning');
        $this->assertSame('AI-derived', $positioning['provenance']);
        $this->assertTrue($positioning['ai_assisted']);
    }
}
