<?php

namespace Tests\Feature\Intel;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Market\BacklinksPage;
use App\Models\Brand;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Intel\BrandIntelSetting;
use App\Models\SearchDemandCompetitor;
use App\Models\User;
use App\Services\Demand\DemandPageFetcher;
use App\Services\Integrations\DataForSeo\DataForSeoProviderCredentialService;
use App\Services\Intel\BacklinkEngine;
use App\Services\Intel\BacklinkLinkChecker;
use App\Services\Intel\DataForSeoTaskQueue;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 8c: backlink summaries, referring domains (new / lost), competitor-intersection opportunities, the Turkish
 * directory list, outreach status and the live link check.
 */
final class BacklinkEngineTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    /** @var list<string> */
    private array $referring = ['haber.com', 'rehber.com'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.dataforseo.base_url' => 'https://api.dataforseo.com', 'moxdop.dataforseo.login' => null, 'moxdop.dataforseo.password' => null]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $integration = CoreIntegration::factory()->dataforseo()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        app(DataForSeoProviderCredentialService::class)->save($integration, ['login' => 'agency@example.com', 'password' => 'secret'], $admin);

        $this->brand = Brand::factory()->create(['name' => 'Atlas Dental', 'sector' => 'dental', 'customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'domain' => 'atlasdis.com']);
        foreach (['rakip1.com', 'rakip2.com', 'reddedilen.com'] as $domain) {
            SearchDemandCompetitor::query()->create(['uuid' => (string) Str::uuid(), 'brand_id' => $this->brand->id, 'display_name' => $domain, 'normalized_domain' => $domain,
                'normalized_domain_hash' => hash('sha256', $domain), 'status' => $domain === 'reddedilen.com' ? 'rejected' : 'approved']);
        }
        BrandIntelSetting::for($this->brand)->fill(['monthly_usd' => 1])->save();

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $task = $request->data()[0] ?? [];
            $result = match (true) {
                str_ends_with($request->url(), 'backlinks/summary/live') => ['rank' => $task['target'] === 'atlasdis.com' ? 120 : 300, 'backlinks' => 900, 'referring_domains' => $task['target'] === 'atlasdis.com' ? count($this->referring) : 80, 'broken_backlinks' => 2, 'backlinks_spam_score' => 5],
                str_ends_with($request->url(), 'backlinks/referring_domains/live') => ['items' => array_map(fn (string $d): array => ['domain' => $d, 'rank' => 200, 'backlinks' => 3, 'backlinks_spam_score' => 2, 'first_seen' => '2026-09-01 10:00:00 +00:00', 'referring_links_attributes' => ['nofollow' => 1]], $this->referring)],
                str_ends_with($request->url(), 'backlinks/domain_intersection/live') => ['items' => [
                    ['domain_intersection' => ['1' => ['target' => 'rakip1.com', 'domain' => 'saglikrehberi.com', 'rank' => 250, 'backlinks_spam_score' => 3], '2' => ['target' => 'rakip2.com', 'domain' => 'saglikrehberi.com', 'rank' => 250, 'backlinks_spam_score' => 3]]],
                    ['domain_intersection' => ['1' => ['target' => 'rakip1.com', 'domain' => 'spamdizin.com', 'rank' => 400, 'backlinks_spam_score' => 80], '2' => ['target' => 'rakip2.com', 'domain' => 'spamdizin.com', 'rank' => 400, 'backlinks_spam_score' => 80]]],
                    ['domain_intersection' => ['1' => ['target' => 'rakip1.com', 'domain' => 'dusuk.com', 'rank' => 10, 'backlinks_spam_score' => 1], '2' => ['target' => 'rakip2.com', 'domain' => 'dusuk.com', 'rank' => 10, 'backlinks_spam_score' => 1]]],
                    ['domain_intersection' => ['1' => ['target' => 'rakip1.com', 'domain' => 'haber.com', 'rank' => 300, 'backlinks_spam_score' => 1], '2' => ['target' => 'rakip2.com', 'domain' => 'haber.com', 'rank' => 300, 'backlinks_spam_score' => 1]]],
                ]],
                default => [],
            };

            return Http::response(['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.02, 'tasks' => [['id' => 'x', 'status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.02, 'result' => [$result]]]]);
        });
    }

    public function test_refresh_builds_profile_and_quality_opportunities(): void
    {
        $engine = app(BacklinkEngine::class);
        $this->assertSame(['rakip1.com', 'rakip2.com'], $engine->competitorDomains($this->brand), 'rejected competitor left out');
        $stats = $engine->refresh($this->brand);

        $this->assertSame(2, $stats['referring_domains']);
        $this->assertSame(2, $stats['new']);
        $this->assertEqualsWithDelta(0.10, $stats['spent_usd'], 0.0001, '3 summaries + referring + intersection = 5 calls');
        $this->assertEqualsWithDelta(0.10, app(DataForSeoTaskQueue::class)->spentThisMonth($this->brand->id), 0.0001);
        $this->assertSame(3, DB::table('backlink_snapshots')->count());
        $this->assertTrue((bool) DB::table('backlink_referring_domains')->where('domain', 'haber.com')->value('dofollow'));

        $opportunities = DB::table('backlink_opportunities')->where('brand_id', $this->brand->id)->pluck('source', 'domain')->all();
        $this->assertSame('intersection', $opportunities['saglikrehberi.com']);
        $this->assertArrayNotHasKey('spamdizin.com', $opportunities, 'spam score filter');
        $this->assertArrayNotHasKey('dusuk.com', $opportunities, 'rank filter');
        $this->assertArrayNotHasKey('haber.com', $opportunities, 'already links to us');
        $this->assertSame('citation', $opportunities['doktortakvimi.com'], 'dental sector directory');
        $this->assertSame('citation', $opportunities['foursquare.com']);

        // Outreach status survives a refresh; a domain that stops linking is marked lost.
        DB::table('backlink_opportunities')->where('domain', 'saglikrehberi.com')->update(['status' => 'contacted']);
        $this->referring = ['haber.com'];
        $stats = $engine->refresh($this->brand);
        $this->assertSame(1, $stats['lost']);
        $this->assertNotNull(DB::table('backlink_referring_domains')->where('domain', 'rehber.com')->value('lost_on'));
        $this->assertSame('contacted', DB::table('backlink_opportunities')->where('domain', 'saglikrehberi.com')->value('status'));

        Livewire::test(BacklinksPage::class, ['brand' => $this->brand->id])
            ->assertSee('saglikrehberi.com')->assertSee('Doktortakvimi')->assertSee('rehber.com')->assertSee('2 rakibe link veriyor');
        $this->get(route('operator.market.backlinks'))->assertOk();
    }

    public function test_cap_and_link_check(): void
    {
        BrandIntelSetting::query()->update(['monthly_usd' => 0.05]);
        try {
            app(BacklinkEngine::class)->refresh($this->brand);
            $this->fail('cap should block');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Aylık tavan', collect($exception->errors())->flatten()->first());
        }
        $this->assertSame(0, DB::table('backlink_snapshots')->count(), 'nothing called over the cap');
        $this->linkCheck();
    }

    private function linkCheck(): void
    {
        $id = DB::table('backlink_opportunities')->insertGetId(['brand_id' => $this->brand->id, 'domain' => 'blog.com', 'source' => 'manual', 'status' => 'waiting', 'link_url' => 'https://blog.com/yazi', 'created_at' => now(), 'updated_at' => now()]);
        $html = '<p><a href="https://www.atlasdis.com/implant/">Atlas</a></p>';
        $this->mock(DemandPageFetcher::class, function ($mock) use (&$html): void {
            $mock->shouldReceive('fetch')->andReturnUsing(function () use (&$html): array {
                return ['status_code' => 200, 'html' => $html, 'final_url' => null, 'error' => null];
            });
        });
        $checker = app(BacklinkLinkChecker::class);
        $this->assertTrue($checker->check(DB::table('backlink_opportunities')->find($id)));
        $this->assertSame('live', DB::table('backlink_opportunities')->where('id', $id)->value('status'));
        $html = '<p>link kaldırıldı</p>';
        $this->assertFalse($checker->check(DB::table('backlink_opportunities')->find($id)));
        $this->assertSame('lost', DB::table('backlink_opportunities')->where('id', $id)->value('status'));
    }
}
