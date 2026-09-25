<?php

namespace Tests\Feature\Portfolio;

use App\Livewire\Demo\Portfolio\BrandsIndex;
use App\Livewire\Demo\Portfolio\CustomersIndex;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Portfolio\PortfolioDeletionService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Admin-only bulk delete of customers / brands, cascading through scoped rows including a restrict child-of-child chain. */
final class PortfolioBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);

        return $admin;
    }

    /** @return array{0: Customer, 1: Brand, 2: DigitalAsset} */
    private function portfolio(): array
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id]);

        // Restrict child, and a restrict child-of-child chain (names must be deleted before the offering).
        DB::table('brand_goals')->insert(['brand_id' => $brand->id, 'kind' => 'lead', 'label' => 'Teklif', 'normalized_key' => 'teklif', 'created_at' => now(), 'updated_at' => now()]);
        $offeringId = DB::table('brand_offerings')->insertGetId(['brand_id' => $brand->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('brand_offering_names')->insert(['brand_id' => $brand->id, 'brand_offering_id' => $offeringId, 'raw_label' => 'İmplant', 'normalized_key' => 'implant', 'name_kind' => 'service', 'provenance' => 'manual', 'normalization_version' => 'v1', 'created_at' => now(), 'updated_at' => now()]);

        // A report snapshot (restrict blocker on customer_id/brand_id) with a delivery child that carries NO scope key:
        // it can only be reached through the foreign-key graph, proving the recursive walk closes that gap.
        $author = User::factory()->create();
        $snapshotId = DB::table('report_snapshots')->insertGetId([
            'customer_id' => $customer->id, 'brand_id' => $brand->id, 'report_type' => 'monthly', 'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
            'title_snapshot' => 'Rapor', 'customer_name_snapshot' => 'M', 'brand_name_snapshot' => 'B', 'locale' => 'tr', 'reporting_timezone' => 'Europe/Istanbul',
            'snapshot_schema_version' => 'v1', 'content_payload' => '{}', 'source_manifest_payload' => '{}', 'source_manifest_fingerprint' => str_repeat('a', 64),
            'content_checksum' => str_repeat('b', 64), 'generated_by' => $author->id, 'generated_at' => now(), 'created_at' => now(),
        ]);
        DB::table('report_deliveries')->insert([
            'report_snapshot_id' => $snapshotId, 'recipient_email_snapshot' => 'a@b.com', 'delivery_mode' => 'email', 'locale' => 'tr',
            'subject_template_version' => 'v1', 'email_template_version' => 'v1', 'status' => 'pending', 'created_at' => now(),
        ]);

        return [$customer, $brand, $asset];
    }

    public function test_deleting_a_customer_removes_brands_assets_and_restrict_children(): void
    {
        [$customer, $brand, $asset] = $this->portfolio();

        $result = app(PortfolioDeletionService::class)->deleteCustomers([$customer->id]);

        $this->assertSame(['deleted' => 1, 'skipped' => 0], $result);
        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
        $this->assertDatabaseMissing('digital_assets', ['id' => $asset->id]);
        $this->assertSame(0, DB::table('brand_goals')->where('brand_id', $brand->id)->count());
        $this->assertSame(0, DB::table('brand_offerings')->where('brand_id', $brand->id)->count());
        $this->assertSame(0, DB::table('brand_offering_names')->where('brand_id', $brand->id)->count());
        $this->assertSame(0, DB::table('report_snapshots')->where('customer_id', $customer->id)->count());
        $this->assertSame(0, DB::table('report_deliveries')->count(), 'the FK-only delivery child was reached via the FK graph');
    }

    public function test_deleting_a_brand_keeps_the_customer(): void
    {
        [$customer, $brand, $asset] = $this->portfolio();

        $result = app(PortfolioDeletionService::class)->deleteBrands([$brand->id]);

        $this->assertSame(['deleted' => 1, 'skipped' => 0], $result);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
        $this->assertDatabaseMissing('digital_assets', ['id' => $asset->id]);
    }

    public function test_bulk_delete_is_admin_only_and_wired_on_the_customers_list(): void
    {
        [$customer] = $this->portfolio();
        $other = Customer::factory()->create();

        // Non-admin cannot delete.
        $member = User::factory()->create(['is_active' => true]);
        Livewire::actingAs($member)->test(CustomersIndex::class)->set('selected', [$customer->id])->call('deleteSelected')->assertStatus(403);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);

        // Admin deletes the selected one and keeps the other.
        Livewire::actingAs($this->admin())->test(CustomersIndex::class)
            ->set('selected', [$customer->id])->call('deleteSelected')->assertHasNoErrors();
        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('customers', ['id' => $other->id]);
    }

    public function test_brands_list_bulk_delete_wires_through(): void
    {
        [, $brand] = $this->portfolio();

        Livewire::actingAs($this->admin())->test(BrandsIndex::class)
            ->set('selected', [$brand->id])->call('deleteSelected')->assertHasNoErrors();
        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
    }
}
