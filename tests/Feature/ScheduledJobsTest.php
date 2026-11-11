<?php

namespace Tests\Feature;

use App\Livewire\Operator\Settings\ScheduledJobsPage;
use App\Models\User;
use App\Services\Operations\ScheduledJobs;
use App\Services\Operations\UsageLedger;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** Zamanlanmış işler (Bağlantı Düzeni 2.4): the real schedule on one screen; a job switched off does not run. */
final class ScheduledJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_job_switched_off_on_the_panel_is_skipped_by_the_scheduler(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        Cache::flush();

        $jobs = app(ScheduledJobs::class);
        $all = collect($jobs->all())->keyBy('key');
        $this->assertGreaterThan(50, $all->count());
        $this->assertTrue($all->has('moxdop:social:inbox'));
        $this->assertSame('10 dakikada bir', $all['moxdop:social:inbox']['cadence']);
        $this->assertTrue($all['moxdop:backup']['vital']);
        $this->assertSame('her gün 03:30', ScheduledJobs::cadence('30 3 * * *'));
        $this->assertSame('her pazartesi 09:00', ScheduledJobs::cadence('0 9 * * 1'));

        app(UsageLedger::class)->record(UsageLedger::JOB, 'moxdop:social:inbox', 90_000, true);
        $page = Livewire::test(ScheduledJobsPage::class)->assertSee('Zamanlanmış işler')->assertSee('social-inbox')->assertSee('1 hata')
            ->call('setJob', 'moxdop:backup', false)->assertSee('kapatılamaz')
            ->call('setJob', 'moxdop:social:inbox', false)->assertSee('İş kapatıldı');
        $this->assertSame(['moxdop:social:inbox'], ScheduledJobs::off());
        $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => UsageLedger::jobKey($e) === 'moxdop:social:inbox');
        $this->assertTrue(ScheduledJobs::isOff($event));
        $this->assertFalse($event->filtersPass(app()), 'the scheduler skips it');
        $page->set('show', 'kapali')->assertSee('social-inbox')->assertDontSee('social-publish')
            ->call('setJob', 'moxdop:social:inbox', true);
        $this->assertSame([], ScheduledJobs::off());
        $this->assertTrue($event->filtersPass(app()));

        $this->actingAs(User::factory()->create(['is_active' => true]));
        Livewire::test(ScheduledJobsPage::class)->assertForbidden();
    }
}
