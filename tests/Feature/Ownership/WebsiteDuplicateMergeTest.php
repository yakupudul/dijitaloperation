<?php

namespace Tests\Feature\Ownership;

use App\Enums\CustomerStatus;
use App\Enums\DigitalAssetStatus;
use App\Jobs\IntelligenceProjection\RebuildWebsiteProjectionJob;
use App\Livewire\Operator\Integrations\WebsiteDuplicatesPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\OwnershipTransfer;
use App\Models\User;
use App\Services\Ownership\WebsiteDuplicateMerger;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

    public function test_merge_queues_a_projection_rebuild_for_the_keeper(): void
    {
        Bus::fake([RebuildWebsiteProjectionJob::class]);
        [$keeper, $duplicate] = $this->pair();

        app(WebsiteDuplicateMerger::class)->merge($keeper, $duplicate, $this->admin);

        Bus::assertDispatched(RebuildWebsiteProjectionJob::class, fn (RebuildWebsiteProjectionJob $job): bool => $job->websiteAssetId === $keeper->id && $job->trigger === 'asset_merge');
        Bus::assertNotDispatched(RebuildWebsiteProjectionJob::class, fn (RebuildWebsiteProjectionJob $job): bool => $job->websiteAssetId === $duplicate->id);
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
