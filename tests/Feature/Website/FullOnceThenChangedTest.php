<?php

namespace Tests\Feature\Website;

use App\Livewire\Operator\Integrations\WebsiteIntegrationIndex;
use App\Models\Brand;
use App\Models\Collection\CollectionRun;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use App\Services\Integrations\WordPress\WordPressEventReconciliation;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Full once, then only changed": the operator's Genel çekim reads changed pages only (Tam yeniden okuma is the
 * explicit, rare option); the automatic WordPress refresh reads the inventory + changed pages and a full HTML
 * re-read happens at most every 30 days, only at night (Europe/Istanbul 01:00–06:00).
 */
final class FullOnceThenChangedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DigitalAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Queue::fake();
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => Brand::factory()->create(['customer_id' => Customer::factory()->create()->id])->id,
            'type' => 'website', 'cms' => 'wordpress', 'domain' => 'example.com', 'primary_url' => 'https://example.com/',
        ]);
    }

    #[Test]
    public function genel_cekim_reads_changed_pages_and_tam_yeniden_okuma_reads_every_page(): void
    {
        app()->setLocale('tr');
        $component = Livewire::actingAs($this->admin)->test(WebsiteIntegrationIndex::class, ['assetId' => $this->asset->id])
            ->assertSee('Tam yeniden okuma')
            ->assertSet('collectionScope', 'full')
            ->call('collectNow', $this->asset->id);
        $run = CollectionRun::query()->latest('id')->firstOrFail();
        $this->assertFalse(data_get($run->request_context, 'context.refetch_unchanged'), 'Genel çekim = changed pages only');
        $this->assertSame('recheck', data_get($run->request_context, 'context.crawl_mode'));

        CollectionRun::query()->update(['status' => 'completed']);
        $component->set('collectionScope', 'full_reread')->call('collectNow', $this->asset->id);
        $reread = CollectionRun::query()->latest('id')->firstOrFail();
        $this->assertNotSame($run->id, $reread->id);
        $this->assertTrue(data_get($reread->request_context, 'context.refetch_unchanged'));
        $this->assertSame('full', data_get($reread->request_context, 'context.crawl_mode'));
        $this->assertSame('full_reread', data_get($reread->request_context, 'context.collection_scope'));
        $this->assertContains(WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL, $reread->datasetRuns()->pluck('request_family_id')->all());
    }

    #[Test]
    public function the_daily_wordpress_refresh_reads_the_inventory_and_only_changed_pages(): void
    {
        $connectionId = $this->pair();
        $this->storedPage();
        $this->travelTo('2026-11-09 11:00:00'); // 14:00 in Istanbul
        DB::table('website_crawl_state')->insert(['digital_asset_id' => $this->asset->id, 'last_full_read_at' => now()->subDays(40), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('website_connector_delivery')->where('connection_id', $connectionId)->update(['last_inventory_at' => now()->subDays(2), 'plugin_version' => '1.6.0']);

        app(WordPressEventReconciliation::class)->tick();

        $run = CollectionRun::query()->findOrFail(DB::table('website_connector_delivery')->where('connection_id', $connectionId)->value('collection_run_id'));
        $this->assertSame('wordpress', data_get($run->request_context, 'context.collection_scope'));
        $this->assertSame([WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL], data_get($run->request_context, 'context.chain_after_wordpress'), 'inventory, then the changed pages');
        $this->assertSame('changed', data_get($run->request_context, 'context.crawl_mode'));
        $this->assertFalse(data_get($run->request_context, 'context.refetch_unchanged'));
        $this->assertSame(1, CollectionRun::query()->count(), 'no full HTML re-read in the daytime, even when one is due');
    }

    #[Test]
    public function a_full_html_re_read_runs_at_most_every_30_days_and_only_at_night(): void
    {
        $connectionId = $this->pair();
        $this->storedPage();
        DB::table('website_crawl_state')->insert(['digital_asset_id' => $this->asset->id, 'last_full_read_at' => '2026-10-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
        $fresh = fn () => DB::table('website_connector_delivery')->where('connection_id', $connectionId)->update([
            'last_inventory_at' => now()->subHour(), 'collection_run_id' => null, 'next_reconcile_at' => null, 'plugin_version' => '1.6.0',
        ]);

        // 05:59 in Istanbul, the last full read 20 days ago: not due.
        $this->travelTo('2026-10-21 02:59:00');
        $fresh();
        app(WordPressEventReconciliation::class)->tick();
        $this->assertSame(0, CollectionRun::query()->count());

        // 06:00 in Istanbul, 40 days: due but outside the night window.
        $this->travelTo('2026-11-10 03:00:00');
        $fresh();
        app(WordPressEventReconciliation::class)->tick();
        $this->assertSame(0, CollectionRun::query()->count());
        $this->assertFalse(WordPressEventReconciliation::inNightWindow());

        // 02:30 in Istanbul: the monthly full read starts (inventory first, then every page).
        $this->travelTo('2026-11-10 23:30:00');
        $this->assertTrue(WordPressEventReconciliation::inNightWindow());
        $fresh();
        app(WordPressEventReconciliation::class)->tick();
        $run = CollectionRun::query()->sole();
        $this->assertTrue(data_get($run->request_context, 'context.refetch_unchanged'));
        $this->assertSame('full', data_get($run->request_context, 'context.crawl_mode'));
        $this->assertSame('monthly_night', data_get($run->request_context, 'context.full_read_reason'));
        $this->assertContains(WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL, data_get($run->request_context, 'context.chain_after_wordpress'));
        $this->assertSame($run->id, (int) DB::table('website_connector_delivery')->where('connection_id', $connectionId)->value('collection_run_id'));

        // Read in full recently: nothing more that night.
        CollectionRun::query()->update(['status' => 'completed', 'finished_at' => now()]);
        DB::table('website_crawl_state')->update(['last_full_read_at' => now()]);
        $this->travel(30)->minutes();
        $fresh();
        app(WordPressEventReconciliation::class)->tick();
        $this->assertSame(1, CollectionRun::query()->count());

        $this->assertSame(30, config('moxdop-website-intelligence.crawl.full_read_interval_days'));
    }

    #[Test]
    public function a_site_never_read_gets_its_full_read_right_away(): void
    {
        $connectionId = $this->pair();
        $this->travelTo('2026-11-09 11:00:00');
        DB::table('website_connector_delivery')->where('connection_id', $connectionId)->update(['last_inventory_at' => now()->subDays(2), 'plugin_version' => '1.6.0']);

        app(WordPressEventReconciliation::class)->tick();

        $run = CollectionRun::query()->sole();
        $chain = data_get($run->request_context, 'context.chain_after_wordpress');
        $this->assertContains(WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL, $chain);
        $this->assertContains(WebsiteRequestFamilyCatalog::FAMILY_HTTP_HTML_DIAGNOSIS, $chain);
        $this->assertNull(data_get($run->request_context, 'context.crawl_mode'), 'no page stored yet: every page is read');
    }

    private function pair(): int
    {
        $pairing = app(WordPressConnectorPairingService::class);
        $issued = $pairing->issue($this->asset, $this->admin);
        $pairing->complete([
            'pairing_code' => $issued['code'],
            'site_url' => 'https://example.com/',
            'home_url' => 'https://example.com/',
            'status_url' => 'https://example.com/wp-json/moxdop/v1/status',
            'snapshot_url' => 'https://example.com/wp-json/moxdop/v1/snapshot',
            'installation_id' => '550e8400-e29b-41d4-a716-446655440000',
            'plugin_version' => '1.6.0',
        ]);
        app(WordPressEventReconciliation::class)->initialize($issued['connection']->fresh('credential'));

        return (int) $issued['connection']->id;
    }

    private function storedPage(): void
    {
        DB::table('website_html_snapshot')->insert([
            'digital_asset_id' => $this->asset->id, 'url' => 'https://example.com/', 'html_hash' => str_repeat('a', 64), 'change_state' => 'new', 'html_bytes' => 10,
            'observed_at' => '2026-10-01 00:00:00', 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', 'home'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
