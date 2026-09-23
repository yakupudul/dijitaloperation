<?php

namespace Tests\Feature\SearchDemand;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\SearchQueryLibraryItem;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\SearchDemand\LibraryImportWorkflow;
use App\Services\SearchDemand\ServiceCatalogService;
use App\Services\SearchDemand\ServiceKeywordService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Google Business Profile search keywords (already collected monthly) are a query-library import
 * source; matching expressions assign them to services like Google Ads / Search Console terms.
 */
final class GbpLibraryImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_gbp_search_keywords_import_and_are_assigned_by_matching_expressions(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
        $implant = app(ServiceCatalogService::class)->resolveOrCreate('İmplant Tedavisi', 'saglik', actor: $admin);
        $implant = $implant instanceof ServiceCatalogItem ? $implant : ServiceCatalogItem::query()->firstOrFail();
        $this->assertSame(['vidalı diş'], app(ServiceKeywordService::class)->append($implant, ['vidalı diş', 'fiyat ankara', 'ab']));
        $this->assertSame([], app(ServiceKeywordService::class)->append($implant, ['Vidalı Diş']), 'append never duplicates');

        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $location = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => 'google_business_profile',
            'external_id' => 'locations/1', 'display_name' => 'Klinik', 'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        foreach ([['vidalı diş kadıköy', '2026-07-01'], ['diş beyazlatma', '2026-08-01'], ['eski kelime', '2025-01-01']] as [$keyword, $month]) {
            DB::table('gbp_search_keywords_monthly')->insert([
                'digital_asset_id' => null, 'external_resource_id' => $location->id, 'run_id' => 1, 'location_name' => 'locations/1',
                'month_start' => $month, 'search_keyword' => $keyword, 'search_keyword_hash' => hash('sha256', $keyword),
                'impressions' => 40, 'threshold' => null, 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $import = app(LibraryImportWorkflow::class)->queue('google_business_profile', [
            'sector' => 'saglik', 'service_ids' => [], 'resource_ids' => [$location->id], 'date_from' => '2026-07-15', 'date_to' => '2026-09-23',
        ], $admin);

        $this->assertSame('completed', $import->fresh()->status);
        $this->assertSame(['diş beyazlatma', 'vidalı diş'], SearchQueryLibraryItem::query()->orderBy('canonical_text')->pluck('canonical_text')->all(), 'month of date_from counts; older months do not; location stripped');
        $vidali = SearchQueryLibraryItem::query()->where('canonical_text', 'vidalı diş')->firstOrFail();
        $this->assertSame([$implant->id], $vidali->services()->pluck('service_catalog_items.id')->all());
        $this->assertSame([], SearchQueryLibraryItem::query()->where('canonical_text', 'diş beyazlatma')->firstOrFail()->services()->pluck('service_catalog_items.id')->all());
    }
}
