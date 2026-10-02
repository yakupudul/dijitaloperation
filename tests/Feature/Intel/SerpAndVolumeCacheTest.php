<?php

namespace Tests\Feature\Intel;

use App\Models\CoreIntegration;
use App\Models\User;
use App\Services\Integrations\DataForSeo\DataForSeoException;
use App\Services\Integrations\DataForSeo\DataForSeoProviderCredentialService;
use App\Services\Intel\QueryVolumes;
use App\Services\Intel\SerpResults;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** MoxDOP v2 Faz 1: DataForSEO is limited to a cached top-10 SERP (30 days) and cached search volume (90 days). */
final class SerpAndVolumeCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.dataforseo.login' => null, 'moxdop.dataforseo.password' => null, 'moxdop.dataforseo.base_url' => 'https://api.dataforseo.com']);
        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);
        app(DataForSeoProviderCredentialService::class)->save(CoreIntegration::factory()->dataforseo()->create(), [
            'login' => 'agency@example.com', 'password' => 'not-a-real-password',
        ], $admin);
    }

    public function test_serp_miss_calls_the_provider_once_then_serves_the_cache_for_thirty_days(): void
    {
        Http::fake(['api.dataforseo.com/v3/serp/google/organic/live/advanced' => Http::response($this->serpResponse())]);
        $serp = app(SerpResults::class);

        $miss = $serp->topTen('  Ankara İmplant  ', 1012782, 'tr', 'mobile');

        $this->assertFalse($miss['cached']);
        $this->assertSame('ankara implant', $miss['query']);
        $this->assertSame(['rank' => 1, 'url' => 'https://rakip.example/implant/', 'domain' => 'rakip.example', 'title' => 'İmplant Ankara', 'type' => 'organic'], $miss['results'][1]);
        $this->assertSame('local_pack', $miss['results'][0]['type'], 'SERP features above the tenth result are kept');
        $this->assertCount(11, $miss['results'], 'ten organic results + the local pack; the eleventh organic result is cut');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['0']['device'] === 'mobile' && $request['0']['location_code'] === 1012782 && $request['0']['depth'] === 10);
        $this->assertSame(1, DB::table('dataforseo_tasks')->where('purpose', 'serp_top10')->count(), 'cost recorded for the monthly cap');

        $hit = $serp->topTen('ankara implant', 1012782, 'tr', 'mobile');
        $this->assertTrue($hit['cached']);
        Http::assertSentCount(1);

        // Another device is another key; an expired row is fetched again.
        $serp->topTen('ankara implant', 1012782, 'tr', 'desktop');
        Http::assertSentCount(2);
        DB::table('serp_results')->update(['fetched_at' => now()->subDays(31)]);
        $this->assertFalse($serp->topTen('ankara implant', 1012782, 'tr', 'mobile')['cached']);
        Http::assertSentCount(3);
        $this->assertSame(2, DB::table('serp_results')->count());
    }

    public function test_spend_guard_blocks_a_miss_but_not_a_cache_hit(): void
    {
        Http::fake(['api.dataforseo.com/*' => Http::response($this->serpResponse())]);
        $serp = app(SerpResults::class);
        $serp->topTen('diş hekimi', 1012782, 'tr');
        config(['moxdop-intel.global_monthly_usd' => 1]);
        DB::table('dataforseo_monthly_spend')->updateOrInsert(['month' => now()->format('Y-m')], ['cost_usd' => 5, 'calls' => 1, 'blocked' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertTrue($serp->topTen('diş hekimi', 1012782, 'tr')['cached']);
        $this->expectException(DataForSeoException::class);
        $serp->topTen('diş kliniği', 1012782, 'tr');
    }

    public function test_search_volume_is_fetched_only_for_missing_queries_and_cached_ninety_days(): void
    {
        Http::fake(['api.dataforseo.com/v3/keywords_data/google_ads/search_volume/live' => Http::sequence()
            ->push($this->volumeResponse(['implant fiyatları' => 880, 'diş beyazlatma' => 390]))
            ->push($this->volumeResponse(['zirkonyum kaplama' => 210])),
        ]);
        $volumes = app(QueryVolumes::class);

        $first = $volumes->volumes(['İmplant Fiyatları', 'diş beyazlatma', str_repeat('çok uzun sorgu ', 10)], 2792, 'tr');

        $this->assertSame(880, $first['implant fiyatları']);
        $this->assertSame(390, $first['diş beyazlatma']);
        $this->assertNull($first[trim(str_repeat('çok uzun sorgu ', 10))], 'too long for the provider: no call, no volume');
        Http::assertSent(fn (Request $request): bool => $request['0']['keywords'] === ['implant fiyatları', 'diş beyazlatma']);

        $second = $volumes->volumes(['implant fiyatları', 'zirkonyum kaplama'], 2792, 'tr');
        $this->assertSame(['implant fiyatları' => 880, 'zirkonyum kaplama' => 210], $second);
        Http::assertSent(fn (Request $request): bool => $request['0']['keywords'] === ['zirkonyum kaplama']);
        Http::assertSentCount(2);
        $this->assertSame(3, DB::table('query_volumes')->count());

        $volumes->volumes(['implant fiyatları', 'diş beyazlatma', 'zirkonyum kaplama'], 2792, 'tr');
        Http::assertSentCount(2);
    }

    /** @return array<string, mixed> */
    private function serpResponse(): array
    {
        $items = [['type' => 'local_pack', 'rank_group' => 1, 'rank_absolute' => 1, 'url' => 'https://maps.example/', 'domain' => 'maps.example', 'title' => 'Harita']];
        for ($i = 1; $i <= 11; $i++) {
            $items[] = ['type' => 'organic', 'rank_group' => $i, 'rank_absolute' => $i + 1,
                'url' => $i === 1 ? 'https://rakip.example/implant/' : 'https://site'.$i.'.example/', 'domain' => $i === 1 ? 'rakip.example' : 'site'.$i.'.example',
                'title' => $i === 1 ? 'İmplant Ankara' : 'Sonuç '.$i];
        }

        return ['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.002, 'tasks_count' => 1, 'tasks_error' => 0,
            'tasks' => [['id' => 'task-1', 'status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.002, 'result' => [['items' => $items]]]]];
    }

    /** @param array<string, int> $volumes @return array<string, mixed> */
    private function volumeResponse(array $volumes): array
    {
        $result = [];
        foreach ($volumes as $keyword => $volume) {
            $result[] = ['keyword' => $keyword, 'location_code' => 2792, 'language_code' => 'tr', 'search_volume' => $volume,
                'competition_index' => 40, 'cpc' => 1.25, 'monthly_searches' => [['year' => 2026, 'month' => 8, 'search_volume' => $volume]]];
        }

        return ['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.05, 'tasks_count' => 1, 'tasks_error' => 0,
            'tasks' => [['id' => 'task-v', 'status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.05, 'result' => $result]]];
    }
}
