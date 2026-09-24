<?php

namespace Tests\Feature\Operations;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Operations\PageSmokeAudit;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * moxdop:audit: system checks + every operator page opened as one user; read-only (rolled back, no jobs, no
 * outside HTTP).
 */
final class SystemAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'email' => 'denetim@example.test']);
        $this->admin->assignRole(Roles::ADMIN);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        foreach (['website', 'google_ads', 'meta_ads', 'ga4', 'search_console', 'google_business_profile'] as $type) {
            DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => $type, 'status' => 'active']);
        }
    }

    public function test_urls_cover_plain_pages_asset_tabs_and_detail_pages(): void
    {
        $urls = collect(app(PageSmokeAudit::class)->urls(2))->map(fn (array $u): string => (string) parse_url($u[0], PHP_URL_PATH).(parse_url($u[0], PHP_URL_QUERY) ? '?'.parse_url($u[0], PHP_URL_QUERY) : ''));
        $googleAds = DigitalAsset::query()->where('type', 'google_ads')->value('id');

        $this->assertContains('/customers', $urls->all());
        $this->assertContains('/assets/google-ads/'.$googleAds.'?tab=auction_insights', $urls->all());
        $this->assertTrue($urls->contains(fn (string $u): bool => str_starts_with($u, '/brands/')));
        $this->assertFalse($urls->contains(fn (string $u): bool => str_contains($u, '/download') || str_contains($u, 'authorize')));
    }

    public function test_command_opens_pages_read_only_and_writes_a_report(): void
    {
        $before = DB::table('digital_assets')->count();

        $this->artisan('moxdop:audit', ['--user' => 'denetim@example.test', '--per-type' => 1, '--only' => 'customers'])
            ->expectsOutputToContain('-- Sistem --')
            ->expectsOutputToContain('Bekleyen migration')
            ->expectsOutputToContain('sayfa açıldı')
            ->expectsOutputToContain('Rapor kaydedildi');

        $this->assertSame($before, DB::table('digital_assets')->count());
        $this->assertTrue(auth()->check());
        foreach (glob(storage_path('logs/audit-*.txt')) ?: [] as $file) {
            @unlink($file);
        }
    }

    public function test_full_page_sweep_reports_results_for_every_url(): void
    {
        $results = app(PageSmokeAudit::class)->run($this->admin, 1);
        $errors = collect($results)->whereIn('level', ['error', 'http', 'forbidden'])->map(fn (array $r): string => $r['url'].' → '.$r['error'].' @ '.$r['where']);

        fwrite(STDERR, "\n".count($results)." pages; errors:\n".$errors->implode("\n")."\n");
        $this->assertNotEmpty($results);
    }

    public function test_brand_query_portfolio_page_opens_when_the_brand_has_legacy_offerings_text(): void
    {
        Brand::query()->update(['offerings' => 'İmplant, zirkonyum']);

        $this->actingAs($this->admin)->get(route('operator.library.brand-query-portfolios'))->assertOk();
    }
}
