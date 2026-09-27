<?php

namespace Tests\Feature\Ownership;

use App\Enums\CustomerStatus;
use App\Enums\DigitalAssetStatus;
use App\Livewire\Operator\Integrations\WebsiteDuplicatesPage;
use App\Models\AssetMerge;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreConnection;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\FindingEvaluation;
use App\Models\OwnershipTransfer;
use App\Models\User;
use App\Services\Ownership\WebsiteDuplicateMerger;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kopya web siteleri: duplicate website assets (same host, created before the model guard) are found, previewed
 * and merged into a keeper; the duplicate is archived, collisions are resolved per table, cross-customer merges
 * need an Admin confirmation and are recorded as a yetki devri.
 */
final class WebsiteDuplicateMergeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $adadent;

    private Brand $atlas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        $this->adadent = Brand::factory()->create(['customer_id' => Customer::factory()->create(['name' => 'Adadent', 'status' => CustomerStatus::Active])->id, 'name' => 'Adadent Marka']);
        $this->atlas = Brand::factory()->create(['customer_id' => Customer::factory()->create(['name' => 'Atlas Dental', 'status' => CustomerStatus::Active])->id, 'name' => 'Atlas Marka']);
    }

    public function test_groups_are_found_by_normalized_host(): void
    {
        $first = $this->site(['brand_id' => $this->adadent->id, 'name' => 'Adadent', 'primary_url' => 'https://www.adadent.com.tr/', 'domain' => null], '-3 days');
        $second = $this->site(['brand_id' => null, 'name' => 'Adadent kopya', 'primary_url' => 'http://adadent.com.tr', 'domain' => null], '-2 days');
        $third = $this->site(['brand_id' => null, 'name' => 'Adadent 3', 'primary_url' => null, 'domain' => 'ADADENT.com.tr/'], '-1 day');
        $this->site(['brand_id' => $this->atlas->id, 'name' => 'Atlas', 'primary_url' => 'https://atlasdental.com', 'domain' => 'atlasdental.com']);
        $this->site(['brand_id' => $this->atlas->id, 'name' => 'Atlas blog', 'primary_url' => 'https://blog.atlasdental.com', 'domain' => null]);

        $groups = app(WebsiteDuplicateMerger::class)->findGroups();

        $this->assertCount(1, $groups);
        $this->assertSame('adadent.com.tr', $groups[0]['host']);
        $this->assertSame([$first->id, $second->id, $third->id], array_column($groups[0]['assets'], 'id'));
        $this->assertSame($first->id, $groups[0]['suggested_keeper_id'], 'The site on an active customer\'s brand is kept.');
        $this->assertFalse($groups[0]['cross_customer']);
    }

    public function test_keeper_prefers_bindings_then_data_then_the_oldest(): void
    {
        $old = $this->site(['brand_id' => $this->adadent->id, 'name' => 'Eski', 'primary_url' => 'https://adadent.com.tr'], '-10 days');
        $withData = $this->site(['brand_id' => $this->adadent->id, 'name' => 'Veri', 'primary_url' => 'https://www.adadent.com.tr'], '-5 days');
        $this->siteFix($withData, 'k1');
        $merger = app(WebsiteDuplicateMerger::class);

        $this->assertSame($withData->id, $merger->findGroups()[0]['suggested_keeper_id']);

        $bound = $this->site(['brand_id' => $this->adadent->id, 'name' => 'Bağlı', 'primary_url' => 'adadent.com.tr/iletisim'], '-1 day');
        CoreAssetBinding::factory()->create(['digital_asset_id' => $bound->id, 'capability' => 'ga4']);
        $this->assertSame($bound->id, $merger->findGroups()[0]['suggested_keeper_id']);

        $this->assertSame($old->id, $merger->suggestKeeper([
            ['id' => $old->id, 'customer_active' => true, 'counts' => ['bindings' => 0], 'connector' => null, 'data_total' => 0, 'created_at' => '2026-01-01T00:00:00+00:00'],
            ['id' => $withData->id, 'customer_active' => true, 'counts' => ['bindings' => 0], 'connector' => null, 'data_total' => 0, 'created_at' => '2026-02-01T00:00:00+00:00'],
        ]));
    }

    public function test_dry_run_plan_reports_moves_and_collisions_without_changing_anything(): void
    {
        [$keeper, $duplicate] = $this->pair();
        $this->siteFix($keeper, 'same');
        $this->siteFix($duplicate, 'same');
        $this->siteFix($duplicate, 'only-dup');

        $plan = app(WebsiteDuplicateMerger::class)->plan($keeper, $duplicate);

        $fixes = collect($plan['tables'])->firstWhere('table', 'site_fix_items');
        $this->assertSame(2, $fixes['rows']);
        $this->assertSame(1, $fixes['collisions']);
        $this->assertSame('keeper', $fixes['rule']);
        $this->assertSame(2, DB::table('site_fix_items')->where('digital_asset_id', $duplicate->id)->count());
        $this->assertNull($duplicate->fresh()->deleted_at);
        $this->assertSame(0, AssetMerge::query()->count());
    }

    public function test_merge_moves_data_resolves_collisions_and_archives_the_duplicate(): void
    {
        [$keeper, $duplicate] = $this->pair();

        // Bindings: ga4 only on the duplicate (moves); search_console on both (keeper's active one stays).
        $ga4 = CoreAssetBinding::factory()->create(['digital_asset_id' => $duplicate->id, 'capability' => 'ga4']);
        $keeperGsc = CoreAssetBinding::factory()->create(['digital_asset_id' => $keeper->id, 'capability' => 'search_console']);
        $dupGsc = CoreAssetBinding::factory()->create(['digital_asset_id' => $duplicate->id, 'capability' => 'search_console']);

        // SEO tasks: one shared task_key (keeper's wins), one only on the duplicate.
        $keeperPlan = $this->seoPlan($keeper);
        $dupPlan = $this->seoPlan($duplicate);
        $this->seoTask($keeper, $keeperPlan, 'shared', 'Tutulan görev');
        $this->seoTask($duplicate, $dupPlan, 'shared', 'Kopya görev');
        $this->seoTask($duplicate, $dupPlan, 'dup-only', 'Yalnız kopyada');

        // Site fixes and collected pages.
        $this->siteFix($keeper, 'fix-shared');
        $this->siteFix($duplicate, 'fix-shared');
        $this->siteFix($duplicate, 'fix-dup');
        $this->page($duplicate, 'https://adadent.com.tr/hizmetler');

        // Findings: same fingerprint on both; the duplicate's evaluation (restrict-free child) follows the keeper's finding.
        $keeperFinding = Finding::factory()->create(['digital_asset_id' => $keeper->id, 'fingerprint' => 'fp-shared']);
        $dupFinding = Finding::factory()->create(['digital_asset_id' => $duplicate->id, 'fingerprint' => 'fp-shared']);
        $evaluation = FindingEvaluation::factory()->create(['finding_id' => $dupFinding->id]);
        Finding::factory()->create(['digital_asset_id' => $duplicate->id, 'fingerprint' => 'fp-dup']);

        // WordPress connector paired on the duplicate only; its site health follows it.
        $connector = CoreConnection::factory()->create(['digital_asset_id' => $duplicate->id, 'type' => 'wordpress_connector', 'enabled' => true, 'config' => ['pairing_state' => 'paired']]);
        DB::table('wordpress_site_health')->insert(['digital_asset_id' => $keeper->id, 'checked_at' => now()->subDay(), 'error' => 'eski']);
        DB::table('wordpress_site_health')->insert(['digital_asset_id' => $duplicate->id, 'checked_at' => now(), 'error' => null]);

        $merge = app(WebsiteDuplicateMerger::class)->merge($keeper, $duplicate, $this->admin);

        $this->assertSame($keeper->id, $ga4->fresh()->digital_asset_id);
        $this->assertSame(CoreAssetBinding::STATUS_ACTIVE, $keeperGsc->fresh()->status);
        $dupGsc->refresh();
        $this->assertSame($duplicate->id, $dupGsc->digital_asset_id);
        $this->assertSame(CoreAssetBinding::STATUS_DISABLED, $dupGsc->status);
        $this->assertSame('merged', $dupGsc->configuration['closed_reason']);

        $this->assertSame(['Tutulan görev', 'Yalnız kopyada'], DB::table('seo_tasks')->where('digital_asset_id', $keeper->id)->orderBy('title')->pluck('title')->sort()->values()->all());
        $this->assertSame(0, DB::table('seo_tasks')->where('digital_asset_id', $duplicate->id)->count());
        $this->assertSame(2, DB::table('seo_plans')->where('digital_asset_id', $keeper->id)->count());
        $this->assertSame(2, DB::table('site_fix_items')->where('digital_asset_id', $keeper->id)->count());
        $this->assertSame(1, DB::table('website_url')->where('digital_asset_id', $keeper->id)->count());

        $this->assertSame(2, Finding::query()->where('digital_asset_id', $keeper->id)->count());
        $this->assertNull(Finding::query()->find($dupFinding->id));
        $this->assertSame($keeperFinding->id, $evaluation->fresh()->finding_id);

        $this->assertSame($keeper->id, $connector->fresh()->digital_asset_id);
        $health = DB::table('wordpress_site_health')->get();
        $this->assertCount(1, $health);
        $this->assertSame($keeper->id, (int) $health[0]->digital_asset_id);
        $this->assertNull($health[0]->error, 'The health row of the connector that won is kept.');

        $archived = DigitalAsset::withTrashed()->find($duplicate->id);
        $this->assertTrue($archived->trashed());
        $this->assertSame(DigitalAssetStatus::Archived, $archived->status);
        $this->assertSame($keeper->id, (int) $archived->merged_into_asset_id);
        $this->assertFalse($keeper->fresh()->trashed());

        $this->assertSame($keeper->id, (int) $merge->keeper_id);
        $this->assertSame($duplicate->id, (int) $merge->duplicate_id);
        $this->assertSame(1, $merge->dropped['seo_tasks']);
        $this->assertSame(1, $merge->dropped['site_fix_items']);
        $this->assertSame(1, $merge->dropped['findings']);
        $this->assertSame(1, $merge->dropped['core_asset_bindings']);
        $this->assertSame(1, $merge->moved['seo_tasks']);
        $this->assertStringContainsString('merged into #'.$keeper->id, (string) $merge->note);
        $this->assertFalse($merge->cross_customer);
        $this->assertSame(0, OwnershipTransfer::query()->count());
        $this->assertSame([], app(WebsiteDuplicateMerger::class)->findGroups());
    }

    public function test_an_active_duplicate_binding_replaces_the_keepers_inactive_one(): void
    {
        [$keeper, $duplicate] = $this->pair();
        $old = CoreAssetBinding::factory()->create(['digital_asset_id' => $keeper->id, 'capability' => 'ga4', 'status' => CoreAssetBinding::STATUS_DISABLED]);
        $active = CoreAssetBinding::factory()->create(['digital_asset_id' => $duplicate->id, 'capability' => 'ga4']);

        app(WebsiteDuplicateMerger::class)->merge($keeper, $duplicate, $this->admin);

        $active->refresh();
        $this->assertSame($keeper->id, $active->digital_asset_id);
        $this->assertSame('ga4', $active->capability);
        $this->assertSame(CoreAssetBinding::STATUS_ACTIVE, $active->status);
        $this->assertSame($duplicate->id, $old->fresh()->digital_asset_id);
    }

    public function test_single_state_rows_keep_the_newer_one(): void
    {
        [$keeper, $duplicate] = $this->pair();
        DB::table('website_sitemap_watch')->insert(['digital_asset_id' => $keeper->id, 'page_count' => 10, 'created_at' => now()->subDays(9), 'updated_at' => now()->subDays(9)]);
        DB::table('website_sitemap_watch')->insert(['digital_asset_id' => $duplicate->id, 'page_count' => 42, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);

        app(WebsiteDuplicateMerger::class)->merge($keeper, $duplicate, $this->admin);

        $rows = DB::table('website_sitemap_watch')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($keeper->id, (int) $rows[0]->digital_asset_id);
        $this->assertSame(42, (int) $rows[0]->page_count);
    }

    public function test_cross_customer_merge_needs_confirmation_and_records_a_transfer(): void
    {
        $keeper = $this->site(['brand_id' => $this->adadent->id, 'name' => 'Adadent', 'primary_url' => 'https://adadent.com.tr'], '-5 days');
        $duplicate = $this->site(['brand_id' => $this->atlas->id, 'name' => 'Atlas kopya', 'primary_url' => 'https://www.adadent.com.tr/'], '-1 day');
        $plan = $this->seoPlan($duplicate);
        $this->seoTask($duplicate, $plan, 'k', 'Görev');
        $merger = app(WebsiteDuplicateMerger::class);

        $this->assertTrue($merger->findGroups()[0]['cross_customer']);
        try {
            $merger->merge($keeper, $duplicate, $this->admin);
            $this->fail('A cross-customer merge must be confirmed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirmation', $exception->errors());
        }
        $this->assertFalse($duplicate->fresh()->trashed());
        $this->assertSame(1, DB::table('seo_tasks')->where('digital_asset_id', $duplicate->id)->count());

        $merge = $merger->merge($keeper, $duplicate, $this->admin, confirmedCrossCustomer: true, note: 'Aynı site');

        $task = DB::table('seo_tasks')->first();
        $this->assertSame($keeper->id, (int) $task->digital_asset_id);
        $this->assertSame($this->adadent->id, (int) $task->brand_id, 'Moved rows follow the keeper\'s brand.');
        $this->assertSame($this->adadent->customer_id, (int) $task->customer_id);

        $transfer = OwnershipTransfer::query()->sole();
        $this->assertSame(OwnershipTransfer::SUBJECT_ASSET, $transfer->subject_type);
        $this->assertSame($duplicate->id, (int) $transfer->from_asset_id);
        $this->assertSame($keeper->id, (int) $transfer->to_asset_id);
        $this->assertSame($this->atlas->customer_id, (int) $transfer->from_customer_id);
        $this->assertSame($this->adadent->customer_id, (int) $transfer->to_customer_id);
        $this->assertSame('Aynı site', $transfer->note);
        $this->assertTrue($merge->cross_customer);
        $this->assertSame($transfer->id, (int) $merge->ownership_transfer_id);
    }

    public function test_only_an_admin_merges_and_hosts_must_match(): void
    {
        [$keeper, $duplicate] = $this->pair();
        $operator = User::factory()->create(['is_active' => true]);
        $merger = app(WebsiteDuplicateMerger::class);

        try {
            $merger->merge($keeper, $duplicate, $operator);
            $this->fail('Only an Admin merges.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('authorization', $exception->errors());
        }

        $other = $this->site(['brand_id' => $this->atlas->id, 'name' => 'Atlas', 'primary_url' => 'https://atlasdental.com']);
        $this->expectException(ValidationException::class);
        $merger->merge($keeper, $other, $this->admin);
    }

    public function test_command_dry_run_changes_nothing_and_apply_merges_only_same_customer_groups(): void
    {
        [$keeper, $duplicate] = $this->pair();
        $this->siteFix($keeper, 'y');
        $this->siteFix($duplicate, 'x');
        $crossKeeper = $this->site(['brand_id' => $this->atlas->id, 'name' => 'Atlas', 'primary_url' => 'https://atlasdental.com'], '-5 days');
        $crossDuplicate = $this->site(['brand_id' => $this->adadent->id, 'name' => 'Atlas kopya', 'primary_url' => 'http://www.atlasdental.com'], '-1 day');

        $this->artisan('moxdop:websites:merge-duplicates')
            ->expectsOutputToContain('adadent.com.tr')
            ->expectsOutputToContain('Deneme (dry run)')
            ->assertSuccessful();
        $this->assertFalse($duplicate->fresh()->trashed());
        $this->assertSame(0, AssetMerge::query()->count());

        $this->artisan('moxdop:websites:merge-duplicates', ['--apply' => true])
            ->expectsOutputToContain('yetki devri gerekir')
            ->expectsOutputToContain('1 kopya birleştirildi')
            ->assertSuccessful();

        $this->assertNotNull(DigitalAsset::withTrashed()->find($duplicate->id)->deleted_at);
        $this->assertSame($keeper->id, (int) DB::table('site_fix_items')->value('digital_asset_id'));
        $this->assertFalse($crossDuplicate->fresh()->trashed());
        $this->assertFalse($crossKeeper->fresh()->trashed());
        $this->assertSame(1, AssetMerge::query()->count());
    }

    public function test_page_lists_groups_previews_and_merges(): void
    {
        [$keeper, $duplicate] = $this->pair();
        $this->siteFix($duplicate, 'x');

        $this->get(route('operator.integrations.website-duplicates'))->assertOk()->assertSee('Kopya web siteleri')->assertSee('adadent.com.tr');

        Livewire::test(WebsiteDuplicatesPage::class)
            ->set('keepers.'.$keeper->id, $keeper->id)
            ->call('preview', $keeper->id)
            ->assertSee('Önizleme')
            ->assertSee('#'.$duplicate->id.' → #'.$keeper->id)
            ->assertSee('Site düzeltmeleri')
            ->call('mergeGroup', $keeper->id)
            ->assertSet('error', '')
            ->assertSee('1 kopya kayıt');

        $this->assertTrue(DigitalAsset::withTrashed()->find($duplicate->id)->trashed());
    }

    public function test_page_asks_for_a_transfer_confirmation_on_a_cross_customer_group(): void
    {
        $keeper = $this->site(['brand_id' => $this->adadent->id, 'name' => 'Adadent', 'primary_url' => 'https://adadent.com.tr'], '-5 days');
        $duplicate = $this->site(['brand_id' => $this->atlas->id, 'name' => 'Atlas kopya', 'primary_url' => 'https://adadent.com.tr/'], '-1 day');

        Livewire::test(WebsiteDuplicatesPage::class)
            ->call('mergeGroup', $keeper->id)
            ->assertSee('Yetki devri gerekiyor')
            ->assertSee('Birleştir')
            ->call('confirmMerge')
            ->assertHasErrors('transferAcknowledged')
            ->set('transferAcknowledged', true)
            ->call('confirmMerge')
            ->assertSee('1 kopya kayıt');

        $this->assertTrue(DigitalAsset::withTrashed()->find($duplicate->id)->trashed());
        $this->assertSame(1, OwnershipTransfer::query()->count());
    }

    public function test_page_hides_the_merge_button_from_non_admins(): void
    {
        $this->pair();
        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($operator);

        Livewire::test(WebsiteDuplicatesPage::class)
            ->assertSee('adadent.com.tr')
            ->assertDontSeeHtml('wire:click="mergeGroup');
    }

    /** @return array{0: DigitalAsset, 1: DigitalAsset} */
    private function pair(): array
    {
        return [
            $this->site(['brand_id' => $this->adadent->id, 'name' => 'Adadent Web Sitesi', 'primary_url' => 'https://adadent.com.tr', 'domain' => 'adadent.com.tr'], '-5 days'),
            $this->site(['brand_id' => $this->adadent->id, 'name' => 'Adadent kopya', 'primary_url' => 'https://www.adadent.com.tr/', 'domain' => null], '-1 day'),
        ];
    }

    /** Creates a website without the one-site-per-domain guard (as legacy rows were). @param array<string, mixed> $attributes */
    private function site(array $attributes, string $age = 'now'): DigitalAsset
    {
        return DigitalAsset::withoutEvents(fn (): DigitalAsset => DigitalAsset::factory()->create(array_merge([
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
            'domain' => null,
            'created_at' => now()->modify($age),
        ], $attributes)));
    }

    private function siteFix(DigitalAsset $asset, string $key): void
    {
        DB::table('site_fix_items')->insert(['digital_asset_id' => $asset->id, 'brand_id' => $asset->brand_id, 'type' => 'meta', 'label' => 'Başlık '.$key, 'item_key' => hash('sha256', $key), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function seoPlan(DigitalAsset $asset): int
    {
        return (int) DB::table('seo_plans')->insertGetId(['brand_id' => $asset->brand_id, 'digital_asset_id' => $asset->id, 'customer_id' => $asset->brand?->customer_id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function seoTask(DigitalAsset $asset, int $planId, string $key, string $title): void
    {
        DB::table('seo_tasks')->insert([
            'brand_id' => $asset->brand_id,
            'customer_id' => $asset->brand?->customer_id,
            'digital_asset_id' => $asset->id,
            'task_key' => hash('sha256', $key),
            'type' => 'fix',
            'rule_id' => 'meta.title',
            'title' => $title,
            'first_seen_plan_id' => $planId,
            'last_seen_plan_id' => $planId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function page(DigitalAsset $asset, string $url): void
    {
        DB::table('website_url')->insert([
            'digital_asset_id' => $asset->id,
            'asset_id' => (string) $asset->id,
            'normalized_url' => $url,
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', $url),
        ]);
    }
}
