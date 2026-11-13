<?php

namespace Tests\Feature\Social;

use App\Livewire\Operator\Social\SocialPage;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\SocialPlanner;
use App\Services\Social\SocialSchedule;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sosyal paylaşım düzeni (yakup 2026-11-12): approved posts line up and take the chosen weekdays one a day, in the
 * order the Admin set; the standard is every day at 12:30; a post with a fixed date stays on its own day.
 */
final class SocialScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Pazartesi 9 Kasım 2026, 08:00.
        $this->travelTo(CarbonImmutable::parse('2026-11-09 08:00', 'Europe/Istanbul'));
        $this->seed(RoleAndPermissionSeeder::class);
        Storage::fake('public');
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        SocialAccount::query()->create(['platform' => 'instagram', 'external_id' => 'IG1', 'username' => 'moximucom', 'access_token' => 't', 'page_id' => 'P1']);
    }

    private function draft(string $at = '2026-12-20 09:00', string $kind = 'image'): SocialPost
    {
        $img = imagecreatetruecolor(20, 25);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();

        return app(SocialPlanner::class)->create(['kind' => $kind, 'caption' => 'x', 'publish_at' => $at], [$kind === 'reels' ? ['bytes' => "\0\0\0\x18ftypmp42video", 'name' => 'v.mp4'] : ['bytes' => $png, 'name' => 'a.png']]);
    }

    private function at(SocialPost $post): string
    {
        return $post->fresh()->publish_at->timezone('Europe/Istanbul')->format('Y-m-d H:i');
    }

    public function test_standard_plan_is_every_day_once_and_approved_posts_go_out_in_approval_order(): void
    {
        $this->assertSame(array_fill(1, 7, '12:30'), app(SocialSchedule::class)->days());
        [$a, $b, $c] = [$this->draft(), $this->draft('2026-11-30 10:00'), $this->draft()];

        foreach ([$a, $b, $c] as $post) {
            app(SocialPlanner::class)->approve($post, $this->admin->id);
        }

        $this->assertSame(['2026-11-09 12:30', '2026-11-10 12:30', '2026-11-11 12:30'], [$this->at($a), $this->at($b), $this->at($c)]);
        $this->assertSame('2026-12-20 09:00', $this->at($this->draft()), 'a draft keeps its proposed time until approved');
    }

    public function test_chosen_days_and_hours_are_used_and_saving_moves_the_waiting_posts(): void
    {
        [$a, $b, $c] = [$this->draft(), $this->draft(), $this->draft()];
        foreach ([$a, $b, $c] as $post) {
            app(SocialPlanner::class)->approve($post, $this->admin->id);
        }

        Livewire::actingAs($this->admin)->test(SocialPage::class)->call('setTab', 'plan')
            ->assertSeeHtml('data-social-schedule')->assertSee('Çarşamba')
            ->set('planDays', [1 => false, 2 => true, 3 => false, 4 => true, 5 => false, 6 => false, 7 => false])
            ->set('planTimes.2', '09:15')->set('planTimes.4', '18:00')
            ->call('saveSchedule')->assertSee('Paylaşım düzeni kaydedildi');

        $this->assertSame([2 => '09:15', 4 => '18:00'], app(SocialSchedule::class)->days());
        $this->assertSame(['2026-11-10 09:15', '2026-11-12 18:00', '2026-11-17 09:15'], [$this->at($a), $this->at($b), $this->at($c)]);
    }

    public function test_order_can_be_changed_and_a_fixed_date_keeps_its_day_for_itself(): void
    {
        [$a, $b, $c] = [$this->draft(), $this->draft(), $this->draft()];
        $fixed = $this->draft('2026-11-10 20:00');
        $fixed->update(['pinned' => true]);
        app(SocialPlanner::class)->approve($fixed, $this->admin->id);
        foreach ([$a, $b, $c] as $post) {
            app(SocialPlanner::class)->approve($post, $this->admin->id);
        }
        $this->assertSame('2026-11-10 20:00', $this->at($fixed));
        $this->assertSame(['2026-11-09 12:30', '2026-11-11 12:30', '2026-11-12 12:30'], [$this->at($a), $this->at($b), $this->at($c)], 'the 10th belongs to the fixed post');

        $page = Livewire::actingAs($this->admin)->test(SocialPage::class)->call('setTab', 'plan')->assertSee('Sırada 1.')->assertSee('Sabit tarih');
        $page->call('move', $c->id, -1);
        $this->assertSame(['2026-11-09 12:30', '2026-11-12 12:30', '2026-11-11 12:30'], [$this->at($a), $this->at($b), $this->at($c)]);

        // Onayı geri alınan gönderinin günü boş kalmaz; sabitlenen gönderi sıradan çıkar.
        $page->call('unapprove', $a->id);
        $this->assertSame(['2026-11-09 12:30', '2026-11-11 12:30'], [$this->at($c), $this->at($b)]);
        $page->call('pin', $b->id, true);
        $this->assertSame([$c->id], app(SocialSchedule::class)->line()->modelKeys());
        $page->call('pin', $b->id, false);
        $this->assertSame([$c->id, $b->id], app(SocialSchedule::class)->line()->modelKeys());
    }

    public function test_a_day_whose_hour_has_passed_is_skipped_and_only_admin_changes_the_plan(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09 13:00', 'Europe/Istanbul'));
        $a = $this->draft();
        app(SocialPlanner::class)->approve($a, $this->admin->id);
        $this->assertSame('2026-11-10 12:30', $this->at($a));

        $viewer = User::factory()->create(['is_active' => true]);
        Livewire::actingAs($viewer)->test(SocialPage::class)->call('saveSchedule')->assertForbidden();
    }

    public function test_stories_have_their_own_line_two_a_day_and_never_take_a_post_day(): void
    {
        $planner = app(SocialPlanner::class);
        $post = $this->draft();
        $stories = [$this->draft('2026-12-20 09:00', 'story'), $this->draft('2026-12-20 09:00', 'story'), $this->draft('2026-12-20 09:00', 'story')];
        foreach ([...$stories, $post] as $p) {
            $planner->approve($p, $this->admin->id);
        }

        // Monday 08:00: stories at 09:00 and 18:00 today, the third tomorrow morning; the post keeps today 12:30.
        $this->assertSame(['2026-11-09 09:00', '2026-11-09 18:00', '2026-11-10 09:00'], array_map(fn (SocialPost $s): string => $this->at($s), $stories));
        $this->assertSame('2026-11-09 12:30', $this->at($post));

        // The Admin sets three story hours: the line takes them at once.
        Livewire::actingAs($this->admin)->test(SocialPage::class)
            ->assertSet('storyTimes', ['09:00', '18:00', ''])
            ->set('storyTimes', ['10:00', '15:00', '21:00'])
            ->call('saveSchedule');
        $this->assertSame(['10:00', '15:00', '21:00'], app(SocialSchedule::class)->storyTimes());
        $this->assertSame(['2026-11-09 10:00', '2026-11-09 15:00', '2026-11-09 21:00'], array_map(fn (SocialPost $s): string => $this->at($s), $stories));
        $this->assertSame('2026-11-09 12:30', $this->at($post));
    }

    public function test_videos_get_their_own_daily_line_once_a_video_hour_is_set(): void
    {
        $schedule = app(SocialSchedule::class);
        [$image, $reel] = [$this->draft(), $this->draft('2026-12-20 09:00', 'reels')];
        foreach ([$image, $reel] as $post) {
            app(SocialPlanner::class)->approve($post, $this->admin->id);
            $schedule->enqueue($post->fresh());
        }
        // No video hour: the video waits behind the image in the one post line.
        $this->assertSame('2026-11-09 12:30', $this->at($image));
        $this->assertSame('2026-11-10 12:30', $this->at($reel));

        $schedule->save(array_fill(1, 7, '12:30'), null, ['19:00']);
        $second = $this->draft('2026-12-21 09:00', 'reels');
        app(SocialPlanner::class)->approve($second, $this->admin->id);
        $schedule->enqueue($second->fresh());

        $this->assertSame(['19:00'], $schedule->reelTimes());
        $this->assertSame('2026-11-09 12:30', $this->at($image));
        $this->assertSame('2026-11-09 19:00', $this->at($reel));
        $this->assertSame('2026-11-10 19:00', $this->at($second));
        $this->assertSame([$image->id], $schedule->line()->modelKeys());
        $this->assertSame([$reel->id, $second->id], $schedule->line('reels')->modelKeys());

        Livewire::actingAs($this->admin)->test(SocialPage::class)->call('setTab', 'plan')
            ->assertSet('reelTimes', ['19:00', ''])->assertSee('Video sırasında 1.');
    }

    public function test_a_blog_post_with_its_fixed_hour_does_not_take_the_days_post_slot(): void
    {
        $img = imagecreatetruecolor(20, 25);
        ob_start();
        imagepng($img);
        $blog = app(SocialPlanner::class)->create(['kind' => 'image', 'caption' => 'x', 'publish_at' => '2026-11-09 10:30', 'created_by' => 'blog'], [['bytes' => (string) ob_get_clean(), 'name' => 'b.png']]);
        $blog->update(['pinned' => true]);
        app(SocialPlanner::class)->approve($blog->fresh(), $this->admin->id);
        $post = $this->draft();
        app(SocialPlanner::class)->approve($post, $this->admin->id);

        $this->assertSame('2026-11-09 10:30', $this->at($blog));
        $this->assertSame('2026-11-09 12:30', $this->at($post));
    }
}
