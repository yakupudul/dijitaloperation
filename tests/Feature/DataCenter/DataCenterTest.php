<?php

namespace Tests\Feature\DataCenter;

use App\Enums\DigitalAssetStatus;
use App\Jobs\RefreshDataCenterSummaryJob;
use App\Livewire\Operator\DataCenterPage;
use App\Models\Brand;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\DataCenter\DataCenterCatalog;
use App\Services\DataCenter\DataCenterReader;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\InsertsFacts;
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

    public function test_a_single_checkbox_value_true_or_false_never_breaks_the_page(): void
    {
        // Production: ViewException "count(): Argument #1 must be of type Countable|array, bool given" — a checkbox
        // bound to picked.<key> before the key was a list makes Livewire store true / false.
        $key = 'resource:'.$this->gsc->id;
        Livewire::actingAs($this->admin)->test(DataCenterPage::class)
            ->call('toggle', $key)
            ->set('picked.'.$key, true)->assertOk()->assertSet('picked.'.$key, [])
            ->set('picked.'.$key, false)->assertOk()
            ->call('erase', $key)->assertOk();
        $this->assertSame(1, DB::table('gsc_page_daily')->count(), 'nothing selected, nothing deleted');
        $this->assertSame(['gsc_page_daily'], DataCenterPage::selection(['gsc_page_daily', true, '']));
        $this->assertSame([], DataCenterPage::selection(true));
    }

    public function test_only_admins_delete(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(Roles::TEAM_MEMBER);
        Livewire::actingAs($viewer)->test(DataCenterPage::class)
            ->set('picked.resource:'.$this->gsc->id, ['gsc_page_daily'])->call('erase', 'resource:'.$this->gsc->id)->assertForbidden();
        $this->assertSame(1, DB::table('gsc_page_daily')->count());
    }

    public function test_the_screen_reads_the_counts_the_refresh_job_wrote_without_scanning_tables(): void
    {
        RefreshDataCenterSummaryJob::dispatchSync();
        $this->assertTrue(Cache::has(DataCenterReader::CACHE_KEY));

        $queries = [];
        /** @var ?list<string> $window the queries of one single request */
        $window = null;
        DB::listen(function (QueryExecuted $query) use (&$queries, &$window): void {
            $queries[] = $query->sql;
            if (is_array($window)) {
                $window[] = $query->sql;
            }
        });
        $key = 'resource:'.$this->gsc->id;
        $page = Livewire::actingAs($this->admin)->test(DataCenterPage::class)
            ->assertSee('klinik.test')->assertSee('atlas.test')
            ->call('toggle', $key)->assertSee('Page (günlük)');

        $window = [];
        $page->call('pickAll', $key)->assertSet('picked.'.$key, ['gsc_page_daily']);
        $reads = array_values(array_filter($window, fn (string $sql): bool => str_contains($sql, 'from "core_external_resources"')));
        $window = null;
        $this->assertCount(1, $reads, 'pickAll and the render after it share one read of the sources');

        $page->set('provider', 'Search Console')->assertDontSee('atlas.test');

        $this->assertNotSame([], $queries, 'names, brands and bindings are still read live');
        $scans = array_values(array_filter($queries, fn (string $sql): bool => preg_match('/group by|sqlite_master|pragma|information_schema|pg_catalog|pg_class|pg_namespace/i', $sql) === 1));
        $this->assertSame([], $scans, 'opening, toggling and picking never count the tables nor read the schema');
    }

    public function test_counts_are_computed_once_when_the_cache_is_empty_and_kept_until_the_next_refresh(): void
    {
        Cache::forget(DataCenterReader::CACHE_KEY);
        $site = collect(app(DataCenterReader::class)->sources())->firstWhere('key', 'asset:'.$this->site->id);
        $this->assertSame(1, $site['rows']);
        $this->assertSame(substr((string) DB::table('website_cms_object_snapshot')->max('observed_at'), 0, 16), $site['collected_at'], 'website rows: observed_at is the last collection');
        $this->assertTrue(Cache::has(DataCenterReader::CACHE_KEY));

        DB::table('website_cms_object_snapshot')->insert(['digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'object_type' => 'page', 'object_id' => '8', 'status' => 'publish',
            'title' => 'Zirkonyum', 'permalink' => 'https://atlas.test/zirkonyum', 'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', 'p8'), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, collect(app(DataCenterReader::class)->sources())->firstWhere('key', 'asset:'.$this->site->id)['rows'], 'the cached count is approximate');

        RefreshDataCenterSummaryJob::dispatchSync();
        $this->assertSame(2, collect(app(DataCenterReader::class)->sources())->firstWhere('key', 'asset:'.$this->site->id)['rows']);
    }

    public function test_an_erase_counts_again_so_the_screen_shows_the_new_numbers(): void
    {
        RefreshDataCenterSummaryJob::dispatchSync();
        $key = 'resource:'.$this->gsc->id;
        Livewire::actingAs($this->admin)->test(DataCenterPage::class)
            ->call('toggle', $key)->call('pickAll', $key)->call('erase', $key)->assertOk();
        Livewire::actingAs($this->admin)->test(DataCenterPage::class)
            ->set('picked.asset:'.$this->site->id, ['website_cms_object_snapshot'])->call('erase', 'asset:'.$this->site->id);

        $this->assertTrue(Cache::has(DataCenterReader::CACHE_KEY), 'rewritten by the erase, not counted again on open');
        $sources = collect(app(DataCenterReader::class)->sources())->keyBy('key');
        $this->assertSame(['gsc_query_daily'], collect($sources[$key]['datasets'])->pluck('dataset')->all());
        $this->assertFalse($sources->has('asset:'.$this->site->id), 'nothing stored for the site any more');
        Livewire::actingAs($this->admin)->test(DataCenterPage::class)->assertSee('klinik.test')->assertDontSee('atlas.test');
    }

    public function test_the_refresh_is_scheduled_hourly_on_the_background_queue(): void
    {
        config(['queue.background_queue' => 'background']);
        Queue::fake();
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'data-center-summary');
        $this->assertInstanceOf(CallbackEvent::class, $event);
        $this->assertSame('41 * * * *', $event->expression);

        $event->run($this->app);
        Queue::assertPushedOn('background', RefreshDataCenterSummaryJob::class);
    }

    public function test_every_extra_table_has_its_key_and_time_columns(): void
    {
        foreach (DataCenterCatalog::EXTRA_TABLES as $table => [$column, , , $stamp]) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertTrue(Schema::hasColumns($table, [$column, $stamp]), $table.': '.$column.', '.$stamp);
        }
    }
}
