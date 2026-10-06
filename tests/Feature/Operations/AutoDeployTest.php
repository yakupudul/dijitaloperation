<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operator\Settings\ImprovementsPage;
use App\Models\AgencySetting;
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
        File::delete(AutoDeployStatus::path());
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

        $env = ['MOXDOP_AUTODEPLOY_BRANCHES' => 'watched', 'MOXDOP_WEB_USER' => get_current_user()];
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
        $this->artisan('moxdop:auto-deploy:report', ['state' => 'tests_failed', '--branch' => 'claude/x', '--sha' => str_repeat('a', 40), '--reason' => 'Testler kırıldı.'])
            ->assertSuccessful();
        $sent = DB::table('push_notifications')->sole();
        $this->assertStringContainsString('Testler geçmedi', (string) $sent->title);
        $this->assertStringContainsString('dal: claude/x', (string) $sent->body);
        // A successful deploy is 'info': below the phone threshold, nothing is sent.
        $this->artisan('moxdop:auto-deploy:report', ['state' => 'deployed', '--sha' => str_repeat('b', 40), '--reason' => 'Yeni sürüm'])->assertSuccessful();
        $this->assertSame(1, DB::table('push_notifications')->count());
        $this->artisan('moxdop:auto-deploy:report', ['state' => 'nonsense'])->assertFailed();
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

    /** @param  list<string>  $command */
    private function shell(array $command): string
    {
        return (new Process($command))->mustRun()->getOutput();
    }
}
