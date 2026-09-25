<?php

namespace Tests\Feature\DataCenter;

use App\Enums\DigitalAssetStatus;
use App\Livewire\Operator\DataCenterPage;
use App\Models\Brand;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\DataCenter\DataCenterReader;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Brain\InsertsFacts;
use Tests\TestCase;

/** Veri merkezi: every source with its stored data sets; selected data sets can be deleted, queries never. */
final class DataCenterTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private User $admin;

    private CoreExternalResource $gsc;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->gsc = CoreExternalResource::factory()->create(['resource_type' => 'search_console', 'external_id' => 'sc-domain:klinik.test', 'display_name' => 'klinik.test']);
        foreach (['gsc_query_daily' => ['query' => 'implant fiyatları'], 'gsc_page_daily' => ['page' => 'https://klinik.test/implant']] as $table => $dimension) {
            $this->insertFact($table, [
                'digital_asset_id' => null, 'external_resource_id' => $this->gsc->id, 'site_url' => 'sc-domain:klinik.test', 'reporting_date' => now()->subDays(3)->toDateString(),
                ...$dimension, 'clicks' => 3, 'impressions' => 40, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
                'record_fingerprint' => hash('sha256', $table), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('dataset_materializations')->insert(['dataset_id' => $table, 'external_resource_id' => $this->gsc->id, 'provider_or_source' => 'GOOGLE_SEARCH_CONSOLE',
                'coverage_start_date' => now()->subDays(30)->toDateString(), 'coverage_end_date' => now()->subDays(3)->toDateString(), 'last_collected_at' => now(),
                'row_count_approx' => 1, 'status' => 'AVAILABLE', 'created_at' => now(), 'updated_at' => now()]);
        }
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas Diş']);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'status' => DigitalAssetStatus::Active, 'domain' => 'atlas.test', 'primary_url' => 'https://atlas.test']);
        DB::table('website_cms_object_snapshot')->insert(['digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'object_type' => 'page', 'object_id' => '7', 'status' => 'publish',
            'title' => 'İmplant', 'permalink' => 'https://atlas.test/implant', 'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', 'p7'), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_sources_list_what_is_stored_and_whether_they_feed_a_brand(): void
    {
        $sources = collect(app(DataCenterReader::class)->sources())->keyBy('key');

        $gsc = $sources['resource:'.$this->gsc->id];
        $this->assertSame('Search Console', $gsc['provider']);
        $this->assertFalse($gsc['bound'], 'not bound to any brand: collection is stopped');
        $datasets = collect($gsc['datasets'])->keyBy('dataset');
        $this->assertTrue($datasets['gsc_query_daily']['protected']);
        $this->assertFalse($datasets['gsc_page_daily']['protected']);

        $site = $sources['asset:'.$this->site->id];
        $this->assertSame(['Atlas Diş'], $site['feeds']);
        $this->assertSame(1, collect($site['datasets'])->firstWhere('dataset', 'website_cms_object_snapshot')['rows']);

        $this->actingAs($this->admin)->get(route('operator.data-center'))->assertOk()->assertSee('Veri merkezi')->assertSee('klinik.test')->assertSee('Bağlı değil');
    }

    public function test_selected_data_sets_are_deleted_and_queries_are_kept(): void
    {
        $key = 'resource:'.$this->gsc->id;
        Livewire::actingAs($this->admin)->test(DataCenterPage::class)
            ->set('picked.'.$key, ['gsc_page_daily', 'gsc_query_daily'])
            ->call('erase', $key)->assertOk();

        $this->assertSame(0, DB::table('gsc_page_daily')->where('external_resource_id', $this->gsc->id)->count());
        $this->assertSame(1, DB::table('gsc_query_daily')->where('external_resource_id', $this->gsc->id)->count(), 'queries are gold and never deleted');
        $this->assertSame(['gsc_query_daily'], DB::table('dataset_materializations')->where('external_resource_id', $this->gsc->id)->pluck('dataset_id')->all());

        Livewire::actingAs($this->admin)->test(DataCenterPage::class)
            ->set('picked.asset:'.$this->site->id, ['website_cms_object_snapshot'])->call('erase', 'asset:'.$this->site->id);
        $this->assertSame(0, DB::table('website_cms_object_snapshot')->count());
    }

    public function test_only_admins_delete(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(Roles::TEAM_MEMBER);
        Livewire::actingAs($viewer)->test(DataCenterPage::class)
            ->set('picked.resource:'.$this->gsc->id, ['gsc_page_daily'])->call('erase', 'resource:'.$this->gsc->id)->assertForbidden();
        $this->assertSame(1, DB::table('gsc_page_daily')->count());
    }
}
