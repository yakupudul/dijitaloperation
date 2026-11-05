<?php

namespace Tests\Feature\Site;

use App\Jobs\Site\PullClarityJob;
use App\Livewire\Operator\Website\V2\SettingsTab;
use App\Livewire\Operator\Work\WorkPage;
use App\Mcp\Tools\ChannelData;
use App\Models\ClarityProject;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Site\Clarity\ClarityCollector;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\CreatesCanonicalPortfolio;
use Tests\TestCase;

/** Microsoft Clarity: per-site token, daily pull folded per page × device, behaviour rules → Teknik sağlık work. */
class ClarityTest extends TestCase
{
    use CreatesCanonicalPortfolio;
    use RefreshDatabase;

    private DigitalAsset $site;

    private Page $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['locale' => 'tr']);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $this->seedCanonicalPortfolio();
        $this->site = $this->createPortfolioAsset('website', 'Northwind Website', ['status' => 'active']);
        $this->page = Page::query()->create(['website_asset_id' => $this->site->id, 'url' => 'https://northwind.test/implant/', 'url_hash' => hash('sha256', 'implant'),
            'path' => '/implant/', 'title' => 'İmplant', 'category' => 'hizmet', 'language' => 'tr', 'is_indexable' => true]);
    }

    public function test_the_token_is_saved_encrypted_and_never_sent_back(): void
    {
        Queue::fake();
        Livewire::test(SettingsTab::class, ['assetId' => $this->site->id])
            ->call('saveClarity')->assertHasErrors('clarityToken')
            ->set('clarityProjectId', 'abc123xyz')->set('clarityToken', str_repeat('t', 40))->call('saveClarity')
            ->assertSee('Clarity kaydedildi')->assertSet('clarityToken', '')->assertDontSee(str_repeat('t', 40))->assertSee('Token kayıtlı')
            ->call('pullClarity')->assertSee('kuyruğa alındı');
        Queue::assertPushed(PullClarityJob::class);

        $project = ClarityProject::query()->sole();
        $this->assertSame(str_repeat('t', 40), $project->api_token);
        $this->assertNotSame(str_repeat('t', 40), DB::table('clarity_projects')->value('api_token'));
        $this->assertSame('https://clarity.microsoft.com/projects/view/abc123xyz/dashboard', $project->dashboardUrl());

        $project->forceFill(['last_pulled_at' => now()->subMinutes(5)])->save();
        Livewire::test(SettingsTab::class, ['assetId' => $this->site->id])->call('pullClarity')->assertSee('son 6 saat içinde');
    }

    public function test_a_bad_page_becomes_work_and_a_clear_pass_closes_it_by_itself(): void
    {
        ClarityProject::query()->create(['website_asset_id' => $this->site->id, 'project_id' => 'abc123xyz', 'api_token' => str_repeat('t', 40)]);
        $answer = $this->answer(rage: 9.5, scroll: 60.0, script: 0.5);
        Http::fake(function (Request $request) use (&$answer) {
            $this->assertSame('Bearer '.str_repeat('t', 40), $request->header('Authorization')[0]);
            $this->assertMatchesRegularExpression('/dimension1=(URL|Channel)/', $request->url());

            return Http::response(str_contains($request->url(), 'dimension1=Channel') ? [] : $answer);
        });

        $result = app(ClarityCollector::class)->pull($this->site);
        $this->assertSame('ok', $result['status']);
        $rows = DB::table('clarity_page_days')->where('website_asset_id', $this->site->id)->orderBy('device')->get();
        $this->assertSame(['desktop', 'mobile'], $rows->pluck('device')->all());
        $this->assertSame([$this->page->id, $this->page->id], $rows->pluck('page_id')->map(fn ($id): int => (int) $id)->all(), 'query strings fold into the page');
        $this->assertSame(60, (int) $rows->firstWhere('device', 'mobile')->sessions);

        $work = Suggestion::query()->where('action_type', 'clarity')->sole();
        $this->assertSame(['Öfkeli tıklama: /implant/', Suggestion::OPEN, $this->page->id], [$work->title, $work->status, (int) $work->page_id]);
        $this->assertStringContainsString('100 oturum', $work->reason);

        Livewire::test(WorkPage::class, ['tab' => 'saglik'])->assertSee('Öfkeli tıklama: /implant/')->assertSee('Ziyaretçilerin takıldığı yerler')
            ->assertSee('Clarity\'de aç')->assertSee('Yaptım')->assertDontSee('wire:click="approve('.$work->id.')"', false);
        Livewire::test(WorkPage::class)->assertDontSee('Öfkeli tıklama: /implant/');

        $answer = $this->answer(rage: 0.8, scroll: 60.0, script: 0.5);
        app(ClarityCollector::class)->pull($this->site);
        $this->assertSame([Suggestion::APPLIED, Suggestion::VERIFY_AUTO], [$work->fresh()->status, $work->fresh()->verification]);
    }

    public function test_a_rejected_token_is_shown_on_the_settings(): void
    {
        ClarityProject::query()->create(['website_asset_id' => $this->site->id, 'api_token' => str_repeat('t', 40)]);
        Http::fake(['*' => Http::response(['message' => 'Unauthorized'], 401)]);

        $this->assertSame('error', app(ClarityCollector::class)->pull($this->site)['status']);
        Livewire::test(SettingsTab::class, ['assetId' => $this->site->id])->assertSee('Clarity token geçersiz');
    }

    public function test_paid_traffic_that_leaves_at_once_or_looks_like_bots_becomes_work_and_mcp_reads_it(): void
    {
        ClarityProject::query()->create(['website_asset_id' => $this->site->id, 'project_id' => 'abc123xyz', 'api_token' => str_repeat('t', 40)]);
        $traffic = $this->trafficAnswer(quickback: 40.0, bots: 20);
        Http::fake(function (Request $request) use (&$traffic) {
            return Http::response(str_contains($request->url(), 'dimension1=Channel') ? $traffic : $this->answer(rage: 0.5, scroll: 60.0, script: 0.5));
        });

        $result = app(ClarityCollector::class)->pull($this->site);
        $this->assertSame('ok', $result['status']);
        $this->assertStringContainsString('3 trafik kaynağı satırı', $result['message']);
        $this->assertSame(3, DB::table('clarity_traffic_days')->where('website_asset_id', $this->site->id)->count());

        $work = Suggestion::query()->where('action_type', 'clarity')->get()->keyBy(fn (Suggestion $s): string => (string) $s->action['rule']);
        $this->assertSame(['traffic_quickback'], $work->keys()->all(), 'only the paid Google campaign fails; organic traffic and the bot share under the line do not');
        $this->assertSame('Reklamdan gelen hemen dönüyor: «implant-izmir» kampanyası', $work['traffic_quickback']->title);
        $this->assertStringContainsString('Google Ads', $work['traffic_quickback']->reason);
        Livewire::test(WorkPage::class, ['tab' => 'saglik'])->assertSee('Reklamdan gelen hemen dönüyor');

        $traffic = $this->trafficAnswer(quickback: 5.0, bots: 60);
        app(ClarityCollector::class)->pull($this->site);
        $this->assertSame([Suggestion::APPLIED, Suggestion::VERIFY_AUTO], [$work['traffic_quickback']->fresh()->status, $work['traffic_quickback']->fresh()->verification]);
        $bots = Suggestion::query()->where('action_type', 'clarity')->where('action->rule', 'traffic_bots')->sole();
        $this->assertStringContainsString('%60\'i bot', $bots->reason);

        $tool = new ChannelData;
        $response = $tool->handle(new \Laravel\Mcp\Request(['brand_id' => $this->site->brand_id, 'dataset' => 'clarity.traffic', 'days' => 30]));
        $json = json_decode((string) $response->content(), true);
        $this->assertSame('Paid search', $json['rows'][0]['channel']);
        $this->assertSame(100, $json['rows'][0]['sessions']);
        $this->assertSame(5, $json['rows'][0]['quickback_pct']);
    }

    /** @return list<array<string, mixed>> traffic answer: a paid Google campaign (100 sessions), organic Google (80), direct (10) */
    private function trafficAnswer(float $quickback, int $bots): array
    {
        $rows = fn (callable $fields): array => [
            ['Channel' => 'Paid search', 'Source' => 'Google', 'Campaign' => 'implant-izmir'] + $fields(100, $quickback),
            ['Channel' => 'Organic search', 'Source' => 'google', 'Campaign' => ''] + $fields(80, 60.0),
            ['Channel' => 'Direct', 'Source' => '', 'Campaign' => ''] + $fields(10, 60.0),
        ];

        return [
            ['metricName' => 'Traffic', 'information' => $rows(fn (int $n, float $q): array => ['totalSessionCount' => (string) $n, 'totalBotSessionCount' => (string) ($n === 100 ? $bots : 0)])],
            ['metricName' => 'QuickbackClick', 'information' => $rows(fn (int $n, float $q): array => ['sessionsCount' => (string) $n, 'sessionsWithMetricPercentage' => $q])],
            ['metricName' => 'ScrollDepth', 'information' => $rows(fn (int $n, float $q): array => ['averageScrollDepth' => 55.0])],
        ];
    }

    /** @return list<array<string, mixed>> Clarity "project-live-insights" answer for one page (two query variants), desktop + mobile */
    private function answer(float $rage, float $scroll, float $script): array
    {
        $rows = fn (callable $fields): array => [
            ['URL' => 'https://northwind.test/implant/?utm_source=x', 'Device' => 'PC'] + $fields(25),
            ['URL' => 'https://northwind.test/implant/', 'Device' => 'PC'] + $fields(15),
            ['URL' => 'https://northwind.test/implant/', 'Device' => 'Mobile'] + $fields(60),
        ];
        $event = fn (float $pct): callable => fn (int $n): array => ['sessionsCount' => (string) $n, 'sessionsWithMetricPercentage' => $pct, 'sessionsWithoutMetricPercentage' => 100 - $pct, 'pagesViews' => (string) $n, 'subTotal' => '3'];

        return [
            ['metricName' => 'Traffic', 'information' => $rows(fn (int $n): array => ['totalSessionCount' => (string) $n, 'totalBotSessionCount' => '0', 'distinctUserCount' => (string) $n, 'pagesPerSessionPercentage' => 1.2])],
            ['metricName' => 'RageClickCount', 'information' => $rows($event($rage))],
            ['metricName' => 'ScriptErrorCount', 'information' => $rows($event($script))],
            ['metricName' => 'ScrollDepth', 'information' => $rows(fn (int $n): array => ['averageScrollDepth' => $scroll])],
            ['metricName' => 'EngagementTime', 'information' => $rows(fn (int $n): array => ['totalTime' => '120', 'activeTime' => '45'])],
        ];
    }
}
