<?php

namespace Tests\Feature\Operations;

use App\Jobs\Operations\RunScreenChecksJob;
use App\Livewire\Operator\Settings\ImprovementsPage;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\ListChanges;
use App\Mcp\Tools\ProposeChange;
use App\Mcp\Tools\ScreenChecks;
use App\Mcp\Tools\UpdateChange;
use App\Models\ScreenCheck;
use App\Models\SystemChange;
use App\Models\User;
use App\Services\Operations\ReleaseInfo;
use App\Services\Operations\ScreenChecker;
use App\Support\Roles;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Site\SiteTestCase;

/**
 * Geliştirme havuzu: Claude proposes (deduplicated) → the operator approves / rejects → Claude codes it and writes the
 * commit + deploy commands → "Deploy tamamlandı" → Claude verifies. Sayfa taraması renders the operator screens.
 */
final class ImprovementPoolTest extends SiteTestCase
{
    public function test_a_finding_goes_from_proposal_to_verified_through_the_operator_and_claude(): void
    {
        Queue::fake();
        $proposal = ['kind' => 'bug', 'title' => 'Web sitesi projeksiyonu 3 denemede düşüyor', 'detail' => 'RebuildWebsiteProjectionJob zaman aşımı; ağır kuyruğa alınmalı.',
            'evidence' => ['MaxAttemptsExceededException ×31'], 'fingerprint' => 'MaxAttempts|RebuildWebsiteProjectionJob'];
        MoxdopServer::tool(ProposeChange::class, $proposal)->assertOk()->assertSee('havuza eklendi');
        MoxdopServer::tool(ProposeChange::class, $proposal)->assertOk()->assertSee('zaten havuzda');
        $change = SystemChange::query()->sole();
        $this->assertSame(SystemChange::PROPOSED, $change->status);

        MoxdopServer::tool(UpdateChange::class, ['id' => $change->id, 'step' => 'start'])->assertHasErrors();
        Livewire::test(ImprovementsPage::class)
            ->assertSee('Web sitesi projeksiyonu 3 denemede düşüyor')
            ->set('notes.'.$change->id, 'Önce ağır kuyruk dene.')
            ->call('approve', $change->id)
            ->assertSee('Onaylandı');
        $this->assertSame(SystemChange::APPROVED, $change->fresh()->status);
        MoxdopServer::tool(ListChanges::class)->assertOk()->assertSee('Önce ağır kuyruk dene.');

        MoxdopServer::tool(UpdateChange::class, ['id' => $change->id, 'step' => 'start'])->assertOk();
        MoxdopServer::tool(UpdateChange::class, ['id' => $change->id, 'step' => 'ready', 'commit' => 'nothex'])->assertHasErrors();
        MoxdopServer::tool(UpdateChange::class, ['id' => $change->id, 'step' => 'ready', 'commit' => 'abc1234def',
            'deploy_commands' => "git fetch origin claude/project-thread-e5yimf\ngit checkout abc1234def\nbash deploy/staging/deploy.sh", 'note' => 'Kuyruk heavy yapıldı.'])->assertOk();
        $this->assertSame(SystemChange::READY, $change->fresh()->status);

        Livewire::test(ImprovementsPage::class, ['tab' => 'deploy'])
            ->assertSee('bash deploy/staging/deploy.sh')
            ->assertSee('Deploy tamamlandı')
            ->call('deployed', 'abc1234def')
            ->assertSee('1 değişiklik canlıda olarak işaretlendi');
        $this->assertSame(SystemChange::DEPLOYED, $change->fresh()->status);
        Queue::assertPushed(RunScreenChecksJob::class);

        MoxdopServer::tool(ListChanges::class)->assertOk()->assertSee('"status":"deployed"', false);
        MoxdopServer::tool(UpdateChange::class, ['id' => $change->id, 'step' => 'verified', 'note' => 'Canlı sürüm abc1234; hata 24 saattir yok.'])->assertOk();
        $this->assertSame(SystemChange::VERIFIED, $change->fresh()->status);

        // A rejected finding is never proposed again.
        $other = SystemChange::query()->create(['kind' => 'design', 'title' => 'Buton rengi', 'detail' => 'Gri buton yerine mavi.', 'fingerprint' => hash('sha256', 'design|buton rengi'), 'status' => SystemChange::PROPOSED]);
        Livewire::test(ImprovementsPage::class)->call('reject', $other->id);
        MoxdopServer::tool(ProposeChange::class, ['kind' => 'design', 'title' => 'Buton rengi', 'detail' => 'Gri buton yerine mavi olsun.'])->assertSee('Reddedildi');
    }

    public function test_the_operator_writes_a_request_that_is_approved_at_once_and_only_admins_open_the_pool(): void
    {
        Livewire::test(ImprovementsPage::class)
            ->set('writing', true)
            ->set('newTitle', 'Marka listesine kurulum sütunu')
            ->set('newDetail', 'Markalar listesinde Kurulum x/y görünsün.')
            ->call('saveRequest')
            ->assertSee('İstek eklendi');
        $this->assertSame(SystemChange::APPROVED, SystemChange::query()->sole()->status);
        $this->assertSame('operator', SystemChange::query()->sole()->source);

        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($operator)->get(route('operator.settings.improvements'))->assertForbidden();
    }

    public function test_cards_lead_with_why_it_matters_bulk_decisions_and_live_commits_move_stuck_changes(): void
    {
        Queue::fake();
        $make = fn (string $title, string $status, string $kind = 'bug'): SystemChange => SystemChange::query()->create(['kind' => $kind, 'title' => $title, 'status' => $status,
            'detail' => "Sorun: BrandDossier.php:274 tarihi yanlış alandan okuyor.\nNeden önemli: Verisi olan 20 hesapta 'veri yok' yazıyor.\nÖnerilen düzeltme: Olgu tablosundan okunsun.\nTest: BrandDossierTest.",
            'fingerprint' => hash('sha256', $title), 'decided_at' => $status === SystemChange::APPROVED ? now()->subDays(2) : null]);
        $a = $make('Dosya tarihleri', SystemChange::PROPOSED);
        $b = $make('Ağır kuyruk', SystemChange::PROPOSED, 'collection');
        $c = $make('Buton', SystemChange::PROPOSED, 'design');
        $stuck = $make('Kodlanmış ama durum yazılamamış', SystemChange::APPROVED);
        $this->assertSame(['problem' => 'BrandDossier.php:274 tarihi yanlış alandan okuyor.', 'why' => "Verisi olan 20 hesapta 'veri yok' yazıyor.",
            'fix' => 'Olgu tablosundan okunsun.', 'test' => 'BrandDossierTest.'], $a->sections());

        Livewire::test(ImprovementsPage::class)
            ->assertSeeInOrder(['Neden önemli:', 'Verisi olan 20 hesapta'])
            ->call('setKind', 'design')->assertSee('Buton')->assertDontSee('Ağır kuyruk')
            ->call('setKind', '')
            ->set('selected', [$a->id, $b->id])->call('approveSelected')->assertSee('2 öneri onaylandı')
            ->set('selected', [$c->id])->call('rejectSelected');
        $this->assertSame([SystemChange::APPROVED, SystemChange::APPROVED, SystemChange::REJECTED], [$a->fresh()->status, $b->fresh()->status, $c->fresh()->status]);
        Livewire::test(ImprovementsPage::class, ['tab' => 'claude'])->assertSee('2 gündür sırada');

        $storage = sys_get_temp_dir().'/moxdop-pool-'.uniqid();
        File::ensureDirectoryExists($storage.'/app');
        $this->app->useStoragePath($storage);
        try {
            File::put($storage.'/app/release.json', json_encode(['sha' => 'ec9a43e5d64ff6605dc8e30a644d09d4e85e1419', 'deployed_at' => '2026-10-06T08:00:00Z', 'pool' => [$stuck->id, $c->id]]));
            ReleaseInfo::forget();
            Livewire::test(ImprovementsPage::class, ['tab' => 'kontrol'])->assertSee('Kodlanmış ama durum yazılamamış');
            $this->assertSame(SystemChange::DEPLOYED, $stuck->fresh()->status, 'a live commit names it, so it waits for Claude’s check');
            $this->assertSame(SystemChange::REJECTED, $c->fresh()->status, 'only approved / in-progress / ready changes move');
            Queue::assertPushed(RunScreenChecksJob::class);
        } finally {
            File::deleteDirectory($storage);
            ReleaseInfo::forget();
        }
    }

    public function test_screen_check_renders_operator_screens_and_reports_errors_and_outline(): void
    {
        $checker = app(ScreenChecker::class);
        $paths = array_column($checker->screens(), 'path');
        $this->assertContains('/brands', $paths);
        $this->assertContains(route('operator.brand', $this->brand, false), $paths);

        $result = $checker->check('/brands', $this->admin);
        $this->assertSame(200, $result['status'], (string) $result['error']);
        $this->assertNull($result['error']);
        $this->assertStringContainsString('Panorama Ankara', (string) $result['outline'].' '.$this->get('/brands')->getContent());
        $this->assertGreaterThan(0, $result['queries']);

        $missing = $checker->check('/brands/999999', $this->admin);
        $this->assertSame(404, $missing['status']);

        $failed = $checker->run();
        $broken = ScreenCheck::query()->get()->filter(fn (ScreenCheck $check): bool => $check->failed())->map(fn (ScreenCheck $check): string => $check->path.' '.$check->status.' '.$check->error)->implode("\n");
        $this->assertSame(0, $failed, $broken);
        $this->assertGreaterThan(20, ScreenCheck::query()->count());

        ScreenCheck::query()->updateOrCreate(['path' => '/brands'], ['label' => 'Markalar', 'status' => 500, 'duration_ms' => 120, 'queries' => 12,
            'error' => 'TypeError: x @ app/Foo.php:10', 'outline' => '# Markalar', 'checked_at' => now()]);
        MoxdopServer::tool(ScreenChecks::class)->assertOk()->assertSee('app/Foo.php:10');
        MoxdopServer::tool(ScreenChecks::class, ['path' => '/brands'])->assertOk()->assertSee('# Markalar');
    }
}
