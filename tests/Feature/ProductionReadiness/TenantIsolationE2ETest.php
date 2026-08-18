<?php

namespace Tests\Feature\ProductionReadiness;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\User;
use App\Services\ClientValueStory\ClientValueStoryReadService;
use App\Services\Findings\FindingReadService;
use App\Support\Roles;
use App\Support\Security\TenantScopeGuard;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Prompt 68 multi-tenant E2E — Customer A cannot access Customer B truth.
 */
class TenantIsolationE2ETest extends TestCase
{
    use RefreshDatabase;

    public function test_forged_customer_a_with_brand_b_scope_is_rejected(): void
    {
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);

        $customerA = Customer::factory()->create(['name' => 'Tenant A']);
        $brandA = Brand::factory()->create(['customer_id' => $customerA->id, 'name' => 'Brand A']);
        DigitalAsset::factory()->create(['brand_id' => $brandA->id, 'name' => 'Asset A']);

        $customerB = Customer::factory()->create(['name' => 'Tenant B']);
        $brandB = Brand::factory()->create(['customer_id' => $customerB->id, 'name' => 'Brand B']);
        DigitalAsset::factory()->create(['brand_id' => $brandB->id, 'name' => 'Asset B']);

        $this->expectException(ValidationException::class);
        app(TenantScopeGuard::class)->resolveConsistentScope([
            'customer_id' => $customerA->id,
            'brand_id' => $brandB->id,
        ]);
    }

    public function test_brand_a_with_asset_b_scope_is_rejected(): void
    {
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);

        $customerA = Customer::factory()->create();
        $brandA = Brand::factory()->create(['customer_id' => $customerA->id]);
        $customerB = Customer::factory()->create();
        $brandB = Brand::factory()->create(['customer_id' => $customerB->id]);
        $assetB = DigitalAsset::factory()->create(['brand_id' => $brandB->id]);

        $this->expectException(ValidationException::class);
        app(TenantScopeGuard::class)->resolveConsistentScope([
            'customer_id' => $customerA->id,
            'brand_id' => $brandA->id,
            'digital_asset_id' => $assetB->id,
        ]);
    }

    public function test_finding_read_service_does_not_leak_other_customer_rows(): void
    {
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);

        $customerA = Customer::factory()->create();
        $brandA = Brand::factory()->create(['customer_id' => $customerA->id]);
        $assetA = DigitalAsset::factory()->create(['brand_id' => $brandA->id]);
        Finding::factory()->create([
            'customer_id' => $customerA->id,
            'brand_id' => $brandA->id,
            'digital_asset_id' => $assetA->id,
            'title' => 'Finding A only',
        ]);

        $customerB = Customer::factory()->create();
        $brandB = Brand::factory()->create(['customer_id' => $customerB->id]);
        $assetB = DigitalAsset::factory()->create(['brand_id' => $brandB->id]);
        Finding::factory()->create([
            'customer_id' => $customerB->id,
            'brand_id' => $brandB->id,
            'digital_asset_id' => $assetB->id,
            'title' => 'Finding B secret',
        ]);

        $rows = app(FindingReadService::class)->query(['customer_id' => $customerA->id]);
        $titles = collect($rows)->map(fn ($dto) => $dto->title)->all();
        $this->assertContains('Finding A only', $titles);
        $this->assertNotContains('Finding B secret', $titles);
    }

    public function test_value_story_for_brand_a_excludes_brand_b_findings(): void
    {
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);

        $customerA = Customer::factory()->create();
        $brandA = Brand::factory()->create(['customer_id' => $customerA->id]);
        $assetA = DigitalAsset::factory()->create(['brand_id' => $brandA->id]);
        Finding::factory()->create([
            'customer_id' => $customerA->id,
            'brand_id' => $brandA->id,
            'digital_asset_id' => $assetA->id,
            'title' => 'Brand A Finding',
            'first_seen_at' => '2026-07-05 10:00:00',
            'last_seen_at' => '2026-07-20 10:00:00',
        ]);

        $customerB = Customer::factory()->create();
        $brandB = Brand::factory()->create(['customer_id' => $customerB->id]);
        $assetB = DigitalAsset::factory()->create(['brand_id' => $brandB->id]);
        Finding::factory()->create([
            'customer_id' => $customerB->id,
            'brand_id' => $brandB->id,
            'digital_asset_id' => $assetB->id,
            'title' => 'Brand B Finding Secret',
            'first_seen_at' => '2026-07-05 10:00:00',
            'last_seen_at' => '2026-07-20 10:00:00',
        ]);

        $story = app(ClientValueStoryReadService::class)->forBrand($brandA, '2026-07-01', '2026-07-31');
        $encoded = json_encode($story->toPresentationArray());
        $this->assertStringContainsString('Brand A Finding', (string) $encoded);
        $this->assertStringNotContainsString('Brand B Finding Secret', (string) $encoded);
    }
}
