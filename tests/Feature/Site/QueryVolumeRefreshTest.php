<?php

namespace Tests\Feature\Site;

use App\Enums\CustomerStatus;
use App\Models\BrandClusterPage;
use App\Models\CoreIntegration;
use App\Services\Integrations\DataForSeo\DataForSeoProviderCredentialService;
use App\Support\ServiceScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** v2: monthly DataForSEO search volume only for cluster main queries and brand target queries of operational brands. */
final class QueryVolumeRefreshTest extends TestCase
{
    use RefreshDatabase;
    use SiteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSite();
        config(['moxdop.dataforseo.login' => null, 'moxdop.dataforseo.password' => null, 'moxdop.dataforseo.base_url' => 'https://api.dataforseo.com']);
        app(DataForSeoProviderCredentialService::class)->save(CoreIntegration::factory()->dataforseo()->create(), [
            'login' => 'agency@example.com', 'password' => 'not-a-real-password',
        ], $this->admin);
    }

    public function test_main_and_target_queries_get_volume_suggested_main_queries_do_not(): void
    {
        $measured = $this->cluster('İmplant merkezi', 'implant merkezi', 'commercial');
        $suggested = $this->cluster('İmplant fiyatı', 'implant fiyatı', 'commercial');
        $suggested->mainQuery()->update(['is_suggested' => true]);
        $unused = $this->cluster('Kullanılmayan', 'diş teli', 'commercial');
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $measured->id, 'website_asset_id' => $this->site->id,
            'state' => 'sufficient', 'language' => 'tr', 'target_query' => 'ankara implant merkezi']);
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $suggested->id, 'website_asset_id' => $this->site->id,
            'state' => 'no_page', 'language' => 'tr']);
        Http::fake(['api.dataforseo.com/v3/keywords_data/google_ads/search_volume/live' => Http::sequence()
            ->push($this->volumeResponse(['implant merkezi' => 320]))
            ->push($this->volumeResponse(['ankara implant merkezi' => 90])),
        ]);

        $this->assertSame(0, Artisan::call('moxdop:intel:query-volumes'));

        $this->assertSame(320, (int) $measured->mainQuery()->value('volume'));
        $this->assertNull($suggested->mainQuery()->value('volume'), 'AI-suggested query: no volume shown as measured');
        $this->assertNull($unused->mainQuery()->value('volume'), 'cluster no brand uses: not paid for');
        $this->assertSame(90, (int) DB::table('query_volumes')->where('query', 'ankara implant merkezi')->value('volume'));
        Http::assertSent(fn (Request $request): bool => $request['0']['keywords'] === ['implant merkezi'] && $request['0']['location_code'] === 2792);
        Http::assertSentCount(2);
    }

    public function test_passive_customers_are_not_paid_for(): void
    {
        $cluster = $this->cluster('İmplant merkezi', 'implant merkezi', 'commercial');
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id,
            'state' => 'sufficient', 'language' => 'tr', 'target_query' => 'ankara implant merkezi']);
        $this->customer->update(['status' => CustomerStatus::Inactive]);
        app(ServiceScope::class)->flush();
        Http::fake();

        $this->assertSame(0, Artisan::call('moxdop:intel:query-volumes'));
        Http::assertNothingSent();
    }

    /** @param array<string, int> $volumes @return array<string, mixed> */
    private function volumeResponse(array $volumes): array
    {
        $result = [];
        foreach ($volumes as $keyword => $volume) {
            $result[] = ['keyword' => $keyword, 'search_volume' => $volume];
        }

        return ['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.05, 'tasks_count' => 1, 'tasks_error' => 0,
            'tasks' => [['id' => 'task-v', 'status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.05, 'result' => $result]]];
    }
}
