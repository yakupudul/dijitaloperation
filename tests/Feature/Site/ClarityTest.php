<?php

namespace Tests\Feature\Site;

use App\Jobs\Site\PullClarityJob;
use App\Livewire\Operator\Website\V2\SettingsTab;
use App\Livewire\Operator\Work\WorkPage;
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
        Livewire::test(SettingsTab::class, ['assetId' => $this->site->id])->call('pullClarity')->assertSee('son bir saat içinde');
    }

    public function test_a_bad_page_becomes_work_and_a_clear_pass_closes_it_by_itself(): void
    {
        ClarityProject::query()->create(['website_asset_id' => $this->site->id, 'project_id' => 'abc123xyz', 'api_token' => str_repeat('t', 40)]);
        $answer = $this->answer(rage: 9.5, scroll: 60.0, script: 0.5);
        Http::fake(function (Request $request) use (&$answer) {
            $this->assertSame('Bearer '.str_repeat('t', 40), $request->header('Authorization')[0]);
            $this->assertStringContainsString('dimension1=URL', $request->url());

            return Http::response($answer);
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

        Livewire::test(WorkPage::class, ['tab' => 'saglik'])->assertSee('Öfkeli tıklama: /implant/')->assertSee('ziyaretçi davranışı')
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
