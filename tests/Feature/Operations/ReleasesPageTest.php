<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operator\Settings\ReleasesPage;
use App\Models\User;
use App\Services\Operations\AutoDeployStatus;
use App\Services\Operations\ReleaseInfo;
use App\Services\Operations\ReleaseLog;
use App\Support\Roles;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\Feature\Site\SiteTestCase;

/** Ayarlar › Sürümler (yakup, 2026-10-06): what went live with which deploy and what is still waiting. */
final class ReleasesPageTest extends SiteTestCase
{
    protected function tearDown(): void
    {
        File::delete([ReleaseLog::historyPath(), ReleaseLog::pendingPath(), AutoDeployStatus::path(), ReleaseInfo::path()]);
        ReleaseInfo::forget();
        parent::tearDown();
    }

    public function test_the_page_shows_the_live_release_each_deploy_and_what_is_not_live_yet(): void
    {
        $live = str_repeat('a', 40);
        File::put(ReleaseInfo::path(), json_encode(['sha' => $live, 'deployed_at' => '2026-10-06T09:39:28Z', 'pool' => []]));
        ReleaseInfo::forget();
        File::put(ReleaseLog::historyPath(), json_encode(['sha' => str_repeat('b', 40), 'deployed_at' => '2026-10-05T08:00:00Z', 'commits' => [['sha' => str_repeat('b', 40), 'at' => '2026-10-05T07:00:00Z', 'subject' => 'Eski değişiklik']]])."\n"
            .json_encode(['sha' => $live, 'deployed_at' => '2026-10-06T09:39:28Z', 'commits' => [
                ['sha' => $live, 'at' => '2026-10-06T09:30:00Z', 'subject' => 'Yorumlar: AI ile taslak yaz tek tıkta 300 yoruma kadar ister'],
                ['sha' => str_repeat('c', 40), 'at' => '2026-10-06T09:00:00Z', 'subject' => 'Otomatik deploy'],
            ]])."\n{bozuk satır\n");
        File::put(ReleaseLog::pendingPath(), str_repeat('d', 40)."\tclaude/x\t2026-10-06T10:20:00+00:00\tYorumlar: hazır ama gönderilmemiş yanıtlar ayrı sayılır\n");
        File::put(AutoDeployStatus::path(), json_encode(['state' => 'testing', 'branch' => 'claude/x', 'sha' => str_repeat('d', 40), 'message' => 'dddddddd için testler çalışıyor.', 'checked_at' => '2026-10-06T10:30:00Z']));

        $this->get(route('operator.settings.releases'))->assertOk()->assertSeeLivewire(ReleasesPage::class);
        Livewire::test(ReleasesPage::class)
            ->assertSee('Canlı sürüm:')
            ->assertSeeInOrder(['aaaaaaaa', '06.10 12:39 deploy edildi'])
            ->assertSee('Otomatik deploy: Testler çalışıyor')
            ->assertSee('Henüz canlıda değil (1)')
            ->assertSee('Yorumlar: hazır ama gönderilmemiş yanıtlar ayrı sayılır')
            ->assertSeeInOrder(['Canlıya çıkanlar', '06.10 12:39', '2 değişiklik', 'Yorumlar: AI ile taslak yaz tek tıkta 300', '05.10 11:00', 'Eski değişiklik']);
    }

    public function test_without_auto_deploy_the_page_says_how_to_install_it_and_only_admins_open_it(): void
    {
        Livewire::test(ReleasesPage::class)->assertSee('Otomatik deploy sunucuda kurulu değil')->assertSee('Deploy geçmişi bir sonraki deploy');
        // Right after --install, before the first cron check.
        File::put(AutoDeployStatus::path(), json_encode(['state' => 'installed', 'branch' => '', 'sha' => '', 'message' => 'İlk kontrol en geç 15 dakika içinde.', 'checked_at' => '2026-10-06T11:14:00Z']));
        Livewire::test(ReleasesPage::class)->assertSee('Otomatik deploy: Kuruldu')->assertSee('İlk kontrol en geç 15 dakika içinde.')->assertDontSee('kurulu değil');

        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member)->get(route('operator.settings.releases'))->assertForbidden();
    }

    public function test_deploy_script_appends_the_commits_a_deploy_brought_live(): void
    {
        $script = (string) file_get_contents(base_path('deploy/staging/deploy.sh'));
        $start = strpos($script, 'php -r \'', (int) strpos($script, 'DEPLOYED_COMMITS='));
        $this->assertNotFalse($start);
        $snippet = substr($script, $start + 8, strpos($script, "' \"\${DEPLOYED_COMMITS}\"") - $start - 8);
        $dir = sys_get_temp_dir().'/moxdop-history-'.uniqid();
        File::ensureDirectoryExists($dir.'/storage/app');
        File::put($dir.'/commits.tsv', str_repeat('e', 40)."\t2026-10-06T10:00:00+00:00\tİlk iş\n".str_repeat('f', 40)."\t2026-10-06T09:00:00+00:00\tİkinci\tsekmeli\n");
        try {
            (new Process(['php', '-r', $snippet, 'commits.tsv', str_repeat('e', 40), '', '2026-10-06T10:05:00Z'], $dir))->mustRun();
            (new Process(['php', '-r', $snippet, 'commits.tsv', str_repeat('e', 40), '', '2026-10-06T10:06:00Z'], $dir))->mustRun();
            $lines = file($dir.'/storage/app/deploy-history.jsonl', FILE_IGNORE_NEW_LINES);
            $this->assertCount(2, $lines);
            $entry = json_decode($lines[1], true);
            $this->assertSame('2026-10-06T10:06:00Z', $entry['deployed_at']);
            $this->assertSame(['İlk iş', "İkinci\tsekmeli"], array_column($entry['commits'], 'subject'));
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
