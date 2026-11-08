<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operator\Settings\ImprovementsPage;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\DeployNow;
use App\Mcp\Tools\SystemHealth;
use App\Models\AgencySetting;
use App\Models\Observability\OperationalAlert;
use App\Services\Observability\OperationalAlertLifecycleService;
use App\Services\Observability\SystemRedEvents;
use App\Services\Operations\AutoDeployStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\Feature\Site\SiteTestCase;

/**
 * Otomatik deploy (yakup, 2026-10-06): deploy/staging/auto-deploy.sh deploys a watched branch only when it is built on
 * the live release and its tests pass; the pool page shows the last check and the phone hears about stops.
 */
final class AutoDeployTest extends SiteTestCase
{
    private string $repo = '';

    protected function tearDown(): void
    {
        File::delete([AutoDeployStatus::path(), AutoDeployStatus::failurePath()]);
        if ($this->repo !== '') {
            File::deleteDirectory($this->repo);
        }
        parent::tearDown();
    }

    public function test_the_script_tests_before_it_deploys_and_never_deploys_a_branch_that_drops_the_live_release(): void
    {
        $script = (string) file_get_contents(base_path('deploy/staging/auto-deploy.sh'));
        $guard = strpos($script, 'git merge-base --is-ancestor "$LIVE" "$head"');
        $tests = strpos($script, 'php artisan test --compact');
        $checkout = strpos($script, 'git checkout --quiet --detach "$TARGET"');
        $deploy = strpos($script, 'bash deploy/staging/deploy.sh >> "$LOG"');
        $this->assertNotFalse($guard);
        $this->assertNotFalse($tests);
        $this->assertTrue($guard < $tests && $tests < $checkout && $checkout < $deploy, 'ancestor guard → tests → checkout → deploy');
        $this->assertStringContainsString('flock -n 9', $script);
        $this->assertStringContainsString('DB_DATABASE=:memory:', $script, 'tests never touch the live database');
        $this->assertStringContainsString('git checkout --quiet --force --detach "$LIVE"', $script, 'a failed deploy goes back to the live release');
        $this->assertStringContainsString('auto-deploy.off', $script);
    }

    public function test_a_branch_not_built_on_the_live_release_is_reported_and_left_alone(): void
    {
        $this->repo = sys_get_temp_dir().'/moxdop-autodeploy-'.uniqid();
        $origin = $this->repo.'/origin.git';
        $app = $this->repo.'/app';
        File::ensureDirectoryExists($this->repo);
        $git = fn (string $dir, string ...$args) => $this->shell(['git', '-C', $dir, '-c', 'user.email=t@t', '-c', 'user.name=t', ...$args]);
        $this->shell(['git', 'init', '--quiet', '--bare', $origin]);
        $this->shell(['git', 'init', '--quiet', $app]);
        File::ensureDirectoryExists($app.'/deploy/staging');
        File::copy(base_path('deploy/staging/auto-deploy.sh'), $app.'/deploy/staging/auto-deploy.sh');
        File::put($app.'/deploy/staging/deploy.sh', "echo deployed > deployed.txt\n");
        File::put($app.'/.gitignore', "storage/\ndeployed.txt\n");
        File::put($app.'/a.txt', 'live');
        $git($app, 'add', '.');
        $git($app, 'commit', '--quiet', '-m', 'live');
        $git($app, 'remote', 'add', 'origin', $origin);
        $live = trim($git($app, 'rev-parse', 'HEAD'));
        // The watched branch starts from an older history: it does not contain the live commit.
        $git($app, 'checkout', '--quiet', '--orphan', 'other');
        File::put($app.'/a.txt', 'other');
        $git($app, 'commit', '--quiet', '-am', 'other');
        $git($app, 'push', '--quiet', 'origin', 'other:refs/heads/watched');
        $git($app, 'checkout', '--quiet', '--detach', $live);

        // Its own lock: on the server this test runs inside a check that holds the real one.
        $env = ['MOXDOP_AUTODEPLOY_BRANCHES' => 'watched', 'MOXDOP_WEB_USER' => get_current_user(), 'MOXDOP_AUTODEPLOY_LOCK' => $this->repo.'/auto-deploy.lock'];
        (new Process(['bash', $app.'/deploy/staging/auto-deploy.sh'], $app, $env))->mustRun();

        $status = json_decode((string) file_get_contents($app.'/storage/app/auto-deploy.json'), true);
        $this->assertSame('blocked', $status['state']);
        $this->assertStringContainsString('canlı sürümün', $status['message']);
        $this->assertSame($live, trim($git($app, 'rev-parse', 'HEAD')), 'the live checkout did not move');
        $this->assertFileDoesNotExist($app.'/deployed.txt');
        // Sürümler lists the branch's commit as not live yet.
        $pending = (string) file_get_contents($app.'/storage/app/auto-deploy-pending.tsv');
        $this->assertStringContainsString("\twatched\t", $pending);
        $this->assertStringContainsString("\tother\n", $pending);
    }

    public function test_the_pool_page_shows_the_last_check_and_a_stop_reaches_the_phone(): void
    {
        File::put(AutoDeployStatus::path(), json_encode(['state' => 'tests_failed', 'branch' => 'claude/x', 'sha' => str_repeat('a', 40),
            'message' => 'aaaaaaaa testleri geçmedi, canlıya alınmadı.', 'checked_at' => '2026-10-06T10:00:00Z']));
        Livewire::test(ImprovementsPage::class)
            ->assertSee('Otomatik deploy: Testler geçmedi, canlıya alınmadı')
            ->assertSee('aaaaaaaa testleri geçmedi')
            ->assertSee('15 dakikada bir');

        Http::fake(['*' => Http::response('ok')]);
        AgencySetting::query()->create(['push_ntfy_url' => 'https://ntfy.example.test/moxdop', 'push_min_severity' => 'high']);
        // Failed tests reach the phone only through the red alert after two hours without a deploy (yakup 2026-11-06).
        $this->artisan('moxdop:auto-deploy:report', ['state' => 'tests_failed', '--branch' => 'claude/x', '--sha' => str_repeat('a', 40), '--reason' => 'Testler kırıldı.'])
            ->assertSuccessful();
        $this->assertSame(0, DB::table('push_notifications')->count());
        $this->artisan('moxdop:auto-deploy:report', ['state' => 'deploy_failed', '--branch' => 'claude/x', '--sha' => str_repeat('a', 40), '--reason' => 'Deploy durdu.'])
            ->assertSuccessful();
        $sent = DB::table('push_notifications')->sole();
        $this->assertStringContainsString('Deploy durdu', (string) $sent->title);
        $this->assertStringContainsString('dal: claude/x', (string) $sent->body);
        // A successful deploy is 'info': below the phone threshold, nothing is sent.
        $this->artisan('moxdop:auto-deploy:report', ['state' => 'deployed', '--sha' => str_repeat('b', 40), '--reason' => 'Yeni sürüm'])->assertSuccessful();
        $this->assertSame(1, DB::table('push_notifications')->count());
        $this->artisan('moxdop:auto-deploy:report', ['state' => 'nonsense'])->assertFailed();
    }

    public function test_failed_tests_become_a_red_alert_only_after_two_hours_without_a_deploy(): void
    {
        $red = fn (): int => OperationalAlert::query()->where('rule_key', SystemRedEvents::DEPLOY_RULE)->whereNull('resolved_at')->count();
        File::put(AutoDeployStatus::path(), json_encode(['state' => 'tests_failed', 'branch' => 'claude/x', 'sha' => str_repeat('a', 40), 'message' => 'kırık', 'checked_at' => now()->toIso8601String()]));
        app(SystemRedEvents::class)->evaluate(app(OperationalAlertLifecycleService::class));
        $this->assertSame(0, $red());

        // Another commit is tested meanwhile: the clock keeps running.
        File::put(AutoDeployStatus::path(), json_encode(['state' => 'testing', 'branch' => 'claude/x', 'sha' => str_repeat('c', 40), 'message' => '', 'checked_at' => now()->toIso8601String()]));
        app(SystemRedEvents::class)->evaluate(app(OperationalAlertLifecycleService::class));
        $this->travel(121)->minutes();
        File::put(AutoDeployStatus::path(), json_encode(['state' => 'tests_failed', 'branch' => 'claude/x', 'sha' => str_repeat('c', 40), 'message' => 'kırık', 'checked_at' => now()->toIso8601String()]));
        app(SystemRedEvents::class)->evaluate(app(OperationalAlertLifecycleService::class));
        $this->assertSame(1, $red());

        File::put(AutoDeployStatus::path(), json_encode(['state' => 'deployed', 'branch' => 'claude/x', 'sha' => str_repeat('d', 40), 'message' => 'canlıda', 'checked_at' => now()->toIso8601String()]));
        app(SystemRedEvents::class)->evaluate(app(OperationalAlertLifecycleService::class));
        $this->assertSame(0, $red());
    }

    public function test_the_failing_tests_of_a_stopped_check_are_readable_on_the_page_and_for_claude(): void
    {
        File::put(AutoDeployStatus::path(), json_encode(['state' => 'tests_failed', 'branch' => 'claude/x', 'sha' => str_repeat('a', 40),
            'message' => 'aaaaaaaa testleri geçmedi, canlıya alınmadı. 1 test: Tests\\Feature\\XTest > it works;', 'checked_at' => '2026-10-06T10:00:00Z']));
        File::put(AutoDeployStatus::failurePath(), "aaaaaaaa · 2026-10-06T10:00:00Z\n\nFAILED  Tests\\Feature\\XTest > it works\nFailed asserting that 57 is identical to 58.\n");

        $this->actingAs($this->admin)->get(route('operator.settings.releases'))->assertOk()
            ->assertSee('Geçmeyen testlerin ayrıntısı')->assertSee('Failed asserting that 57 is identical to 58.');
        MoxdopServer::tool(SystemHealth::class)->assertOk()->assertSee('auto_deploy')->assertSee('Failed asserting that 57 is identical to 58.');

        File::put(AutoDeployStatus::path(), json_encode(['state' => 'deployed', 'branch' => 'claude/x', 'sha' => str_repeat('b', 40), 'message' => 'bbbbbbbb canlıda.', 'checked_at' => '2026-10-06T10:15:00Z']));
        $this->actingAs($this->admin)->get(route('operator.settings.releases'))->assertDontSee('Geçmeyen testlerin ayrıntısı');
    }

    public function test_a_status_with_a_cut_letter_or_control_characters_still_reads_as_its_state(): void
    {
        // A failed test log in the message: colour codes and a Turkish letter cut in half at the byte limit.
        File::put(AutoDeployStatus::path(), '{"state":"tests_failed","branch":"claude/x","sha":"'.str_repeat('a', 40).'","message":"aaaaaaaa testleri geçmedi '."\x1b[31mFAIL\x1b[0m \xC4".'","checked_at":"2026-10-06T11:30:00Z","live":""}');
        $status = AutoDeployStatus::current();
        $this->assertSame('tests_failed', $status['state']);
        $this->assertStringContainsString('testleri geçmedi', $status['message']);

        File::put(AutoDeployStatus::path(), '{"state":"tests_failed","message":"kırık \\'."\n".'"}');
        $this->assertSame('tests_failed', AutoDeployStatus::current()['state'], 'never "not installed" over a real check');
    }

    public function test_deploy_now_asks_the_minute_cron_for_a_check_and_a_paused_deploy_refuses_it(): void
    {
        File::delete(AutoDeployStatus::requestPath());
        File::put(AutoDeployStatus::path(), json_encode(['state' => 'idle', 'branch' => '', 'sha' => str_repeat('a', 40), 'message' => 'Yeni commit yok.', 'checked_at' => now()->toIso8601String()]));
        MoxdopServer::tool(DeployNow::class, ['reason' => 'WhatsApp düzeltmesi'])->assertOk()->assertSee('En geç 1 dakika');
        $this->assertFileExists(AutoDeployStatus::requestPath());
        $this->assertNotNull(AutoDeployStatus::requested());

        File::delete(AutoDeployStatus::requestPath());
        File::put(AutoDeployStatus::path(), json_encode(['state' => 'paused', 'branch' => '', 'sha' => '', 'message' => '', 'checked_at' => now()->toIso8601String()]));
        MoxdopServer::tool(DeployNow::class, ['reason' => 'deneme'])->assertHasErrors();
        $this->assertFileDoesNotExist(AutoDeployStatus::requestPath());
    }

    public function test_the_minute_cron_checks_only_on_the_quarter_hour_or_when_a_deploy_now_request_waits(): void
    {
        $this->repo = sys_get_temp_dir().'/moxdop-autodeploy-'.uniqid();
        $app = $this->repo.'/app';
        $this->shell(['git', 'init', '--quiet', $app]);
        File::ensureDirectoryExists($app.'/deploy/staging');
        File::copy(base_path('deploy/staging/auto-deploy.sh'), $app.'/deploy/staging/auto-deploy.sh');
        File::put($app.'/.gitignore', "storage/\n");
        $this->shell(['git', '-C', $app, '-c', 'user.email=t@t', '-c', 'user.name=t', 'add', '.']);
        $this->shell(['git', '-C', $app, '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '--quiet', '-m', 'live']);
        $env = ['MOXDOP_AUTODEPLOY_BRANCHES' => 'none', 'MOXDOP_WEB_USER' => get_current_user(), 'MOXDOP_AUTODEPLOY_LOCK' => $this->repo.'/auto-deploy.lock',
            'MOXDOP_AUTODEPLOY_CRON_FILE' => $this->repo.'/cron', 'MOXDOP_AUTODEPLOY_MINUTE' => '07'];
        $run = function () use ($app, &$env): void {
            (new Process(['bash', $app.'/deploy/staging/auto-deploy.sh', '--cron'], $app, $env))->mustRun();
        };

        $run();
        $this->assertFileDoesNotExist($app.'/storage/app/auto-deploy.json', 'minute 07 without a request: nothing checked');

        File::put($app.'/storage/app/auto-deploy.now', '{}');
        $run();
        $this->assertSame('idle', json_decode((string) file_get_contents($app.'/storage/app/auto-deploy.json'), true)['state']);
        $this->assertFileDoesNotExist($app.'/storage/app/auto-deploy.now', 'the request is taken');

        File::delete($app.'/storage/app/auto-deploy.json');
        $env['MOXDOP_AUTODEPLOY_MINUTE'] = '45';
        $run();
        $this->assertFileExists($app.'/storage/app/auto-deploy.json', 'the quarter hour still checks');

        // A check that hangs (no answer from the network) holds the lock: a fresh hold waits, a stale one is taken back.
        $holder = new Process(['setsid', 'flock', $this->repo.'/auto-deploy.lock', 'sleep', '120']);
        $holder->start();
        for ($i = 0; $i < 50 && ! is_file($this->repo.'/auto-deploy.lock'); $i++) {
            usleep(100_000);
        }
        usleep(300_000);
        File::put($this->repo.'/auto-deploy.lock.holder', $holder->getPid().' '.time());
        File::delete($app.'/storage/app/auto-deploy.json');
        $run();
        $this->assertFileDoesNotExist($app.'/storage/app/auto-deploy.json', 'a running check is not disturbed');
        $this->assertTrue($holder->isRunning());

        File::put($this->repo.'/auto-deploy.lock.holder', $holder->getPid().' '.(time() - 4 * 3600));
        $run();
        $this->assertFileExists($app.'/storage/app/auto-deploy.json', 'the stuck check was ended and this one ran');
        for ($i = 0; $i < 50 && $holder->isRunning(); $i++) {
            usleep(100_000);
        }
        $this->assertFalse($holder->isRunning(), 'the stuck holder was ended');
    }

    /** @param  list<string>  $command */
    private function shell(array $command): string
    {
        return (new Process($command))->mustRun()->getOutput();
    }
}
