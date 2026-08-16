<?php

namespace Tests\Feature\ProductionReadiness;

use App\Enums\DataPool\DataSourceState;
use App\Livewire\Demo\Operations\FindingsIndex;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\ClientValueStory\ClientValueStoryReadService;
use App\Services\Ga4\Ga4SpecialistReadService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 68 negative / demo-free production path checks.
 */
class NegativePathE2ETest extends TestCase
{
    use RefreshDatabase;

    public function test_unbound_ga4_asset_does_not_fall_back_to_demo_fixtures(): void
    {
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'ga4',
            'module_id' => 'google_analytics',
        ]);

        $workspace = app(Ga4SpecialistReadService::class)->workspace((string) $asset->id, 'last_28');
        $this->assertNotSame('demo_catalog', $workspace['migration_mode'] ?? null);
        $this->assertSame([], $workspace['needs_attention'] ?? ['not-empty']);
        $this->assertSame([], $workspace['opportunities'] ?? ['not-empty']);
        foreach ($workspace['data_provenance'] ?? [] as $state) {
            $this->assertNotSame(DataSourceState::Demo->value, $state);
        }
    }

    public function test_empty_findings_index_does_not_invent_sample_rows(): void
    {
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        Livewire::test(FindingsIndex::class)
            ->assertOk()
            ->assertSee('No Findings yet')
            ->assertDontSee('Meta CPL deteriorated')
            ->assertDontSee('Atlas Dental — GA4');
    }

    public function test_empty_brand_value_story_is_truthful_without_demo_narrative(): void
    {
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        DigitalAsset::factory()->create(['brand_id' => $brand->id]);

        $story = app(ClientValueStoryReadService::class)->forBrand($brand, '2026-07-01', '2026-07-31');
        $presentation = $story->toPresentationArray();
        $encoded = json_encode($presentation);
        $this->assertStringNotContainsString('Atlas Dental', (string) $encoded);
        $this->assertFalse($story->hasAnyOutcomeData());
        $this->assertSame([], $story->findings);
    }
}
