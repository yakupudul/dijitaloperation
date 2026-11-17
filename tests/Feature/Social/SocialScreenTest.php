<?php

namespace Tests\Feature\Social;

use App\Livewire\Operator\Social\SocialPage;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\SocialPosts;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\SocialThread;
use App\Models\User;
use App\Services\Social\SocialPlanner;
use App\Services\Social\SocialScreen;
use App\Services\Social\SocialThumbs;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sosyal screen redesign (yakup 2026-11-17): five bounded tabs with the channel as a filter, light cover pictures in
 * every list (the video loads only in the side panel), a list of 25 per page and the Threads text next to the others.
 */
final class SocialScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // A Tuesday; the week is 9–15 November.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 13:00', 'Europe/Istanbul'));
        // The panel runs in Turkish; dates on the screen are written out.
        app()->setLocale('tr');
        Carbon::setLocale('tr');
        $this->seed(RoleAndPermissionSeeder::class);
        Storage::fake('public');
        Http::fake();
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        SocialAccount::query()->create(['platform' => 'facebook', 'external_id' => 'PAGE1', 'name' => 'Moximu', 'access_token' => 't', 'page_id' => 'PAGE1']);
        SocialAccount::query()->create(['platform' => 'instagram', 'external_id' => 'IG1', 'username' => 'moximucom', 'access_token' => 't', 'page_id' => 'PAGE1']);
        SocialAccount::query()->create(['platform' => 'threads', 'external_id' => 'TH1', 'username' => 'moximucom', 'access_token' => 't', 'token_expires_at' => now()->addDays(50)]);
    }

    private function png(int $width = 20): string
    {
        $img = imagecreatetruecolor($width, (int) ($width * 1.25));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /** @param  array<string, mixed>  $with */
    private function make(string $kind, string $at, array $with = []): SocialPost
    {
        $files = $kind === 'reels'
            ? [['bytes' => "\0\0\0\x18ftypmp42video", 'name' => 'v.mp4']] + (($with['cover'] ?? false) ? [1 => ['bytes' => $this->png(800), 'name' => 'kapak.png']] : [])
            : [['bytes' => $this->png(800), 'name' => 'g.png']];
        unset($with['cover']);

        return app(SocialPlanner::class)->create(['kind' => $kind, 'caption' => 'Metin', 'publish_at' => $at] + $with, array_values($files));
    }

    public function test_today_shows_the_day_in_time_order_with_what_waits_and_the_stock(): void
    {
        $morning = $this->make('story', '2026-11-10 09:00', ['title' => 'Sabah hikâyesi']);
        $evening = $this->make('reels', '2026-11-10 19:00', ['title' => 'Akşam videosu']);
        $thursday = $this->make('image', '2026-11-12 12:30', ['title' => 'Perşembe gönderisi']);
        app(SocialPlanner::class)->approve($evening, $this->admin->id);
        $evening->update(['pinned' => true, 'publish_at' => CarbonImmutable::parse('2026-11-10 19:00', 'Europe/Istanbul')]);
        $morning->update(['status' => SocialPost::PUBLISHED]);
        $morning->targets()->update(['status' => SocialPostTarget::PUBLISHED, 'published_at' => now()]);

        $html = Livewire::actingAs($this->admin)->test(SocialPage::class)->assertSet('tab', 'bugun')
            ->assertSee('Bugün, 10 Kasım Salı')->assertSee('2 paylaşım: 1 çıktı, 1 sırada')
            ->assertSeeInOrder(['Sabah hikâyesi', 'Şimdi 13:00', 'Akşam videosu'])
            ->assertDontSeeHtml('data-social-post="'.$thursday->id.'"')
            ->assertSee('Bir gönderi onayınızı bekliyor')
            ->assertSee('Hazır içerik kaç gün yeter')->assertSeeHtml('data-stock="Instagram gönderi"')
            ->html();
        // The list never loads a video: a small cover or a mark stands in for it.
        $this->assertStringNotContainsString('<video', $html);

        $stock = collect(app(SocialScreen::class)->stock())->keyBy('label');
        $this->assertSame(2, $stock['Instagram gönderi']['days']);
        $this->assertTrue($stock['Instagram gönderi']['low']);
        $this->assertSame(0, $stock['Threads']['days']);
    }

    public function test_waiting_box_opens_the_draft_in_the_side_panel(): void
    {
        $draft = $this->make('image', '2026-11-12 12:30', ['title' => 'Onay bekleyen']);

        Livewire::actingAs($this->admin)->test(SocialPage::class)
            ->call('jump', 'gonderiler', 'onay', $draft->id)
            ->assertSet('tab', 'gonderiler')->assertSet('status', 'onay')->assertSet('openedId', $draft->id)
            ->assertSeeHtml('data-social-post-modal="'.$draft->id.'"')->assertSeeHtml('data-social-publish-now')
            ->call('approve', $draft->id)
            ->call('closePost')->assertDontSeeHtml('data-social-post-modal');
        $this->assertSame(SocialPost::APPROVED, $draft->fresh()->status);
    }

    public function test_calendar_shows_one_week_and_moves_by_week_and_month(): void
    {
        $this->make('image', '2026-11-11 12:30', ['title' => 'Bu hafta']);
        $this->make('story', '2026-11-11 09:00', ['title' => 'Haftanın hikâyesi']);
        $this->make('image', '2026-11-18 12:30', ['title' => 'Gelecek hafta']);

        $page = Livewire::actingAs($this->admin)->withQueryParams(['sekme' => 'takvim'])->test(SocialPage::class)
            ->assertSee('9 – 15 Kasım 2026')->assertSee('Bu hafta')->assertSee('Haftanın hikâyesi')->assertDontSee('Gelecek hafta')
            ->assertSee('Haftayı onayla (2)')
            ->set('stories', false)->assertDontSee('Haftanın hikâyesi')->set('stories', true)
            ->call('shift', 1)->assertSet('week', '2026-11-16')->assertSee('Gelecek hafta')
            ->call('shift', 0)->assertSet('week', '')
            ->set('calendar', 'ay')->assertSee('Kasım 2026')->assertSeeHtml('data-month-day="2026-11-18"')->assertDontSee('Gelecek hafta');
        $page->call('shift', 1)->assertSee('Aralık 2026');
        $page->call('pickDay', '2026-11-18')->assertSet('calendar', 'hafta')->assertSet('week', '2026-11-16')->assertSee('Gelecek hafta');
    }

    public function test_list_pages_by_25_and_filters_by_state_kind_channel_and_search(): void
    {
        foreach (range(1, 27) as $i) {
            $this->make('image', '2026-11-'.str_pad((string) (10 + ($i % 15)), 2, '0', STR_PAD_LEFT).' 12:30', ['title' => 'Gönderi '.$i]);
        }
        $video = $this->make('reels', '2026-11-20 19:00', ['title' => 'Aranan video', 'threads_text' => 'Threads için kısa metin']);
        $video->update(['status' => SocialPost::APPROVED]);

        $page = Livewire::actingAs($this->admin)->withQueryParams(['sekme' => 'gonderiler'])->test(SocialPage::class)
            ->assertSet('status', 'sirada')->set('status', '')
            ->assertSee('1–25 / 28 gönderi')->assertSee('1 / 2')
            ->call('turn', 2)->assertSee('26–28 / 28 gönderi')
            ->set('status', 'sirada')->assertSet('pageNo', 1)->assertSee('Aranan video')->assertDontSee('Gönderi 3')
            ->set('status', '')->set('kind', 'reels')->assertSee('1–1 / 1 gönderi')
            ->set('kind', '')->set('search', 'aranan')->assertSee('1–1 / 1 gönderi')
            ->set('search', '')->call('setChannel', 'threads')->assertSee('1–1 / 1 gönderi')->assertSee('Aranan video');
        $this->assertSame(25, substr_count($page->call('setChannel', '')->html(), 'data-social-post="'));

        $counts = app(SocialScreen::class)->page('', '', '', '', 1)['counts'];
        $this->assertSame(['' => 28, 'onay' => 27, 'sirada' => 1], [...array_intersect_key($counts, ['' => 1]), 'onay' => $counts['onay'], 'sirada' => $counts['sirada']]);
    }

    public function test_cover_pictures_are_small_jpgs_and_a_video_uses_its_cover_image(): void
    {
        $image = $this->make('image', '2026-11-12 12:30');
        $withCover = $this->make('reels', '2026-11-12 19:00', ['cover' => true]);
        $bare = $this->make('reels', '2026-11-13 19:00');
        $thumbs = app(SocialThumbs::class);

        $cover = $thumbs->cover($image);
        $this->assertStringContainsString('social/thumbs/'.$image->id.'-', (string) $cover['url']);
        $file = collect(Storage::disk('public')->files('social/thumbs'))->first(fn (string $f): bool => str_contains($f, '/'.$image->id.'-'));
        $this->assertSame(SocialThumbs::WIDTH, getimagesizefromstring((string) Storage::disk('public')->get($file))[0]);

        $this->assertStringContainsString('social/thumbs/'.$withCover->id.'-', (string) $thumbs->cover($withCover)['url']);
        $this->assertTrue($thumbs->cover($withCover)['video']);
        // No cover image and no usable frame: the tile shows a mark, never the video file.
        $this->assertNull($thumbs->cover($bare)['url']);

        $html = Livewire::actingAs($this->admin)->withQueryParams(['sekme' => 'instagram'])->test(SocialPage::class)->html();
        $this->assertStringNotContainsString('<video', $html);
        $this->assertStringContainsString('social/thumbs/'.$image->id.'-', $html);
    }

    public function test_threads_text_is_edited_in_the_side_panel_and_given_by_claude(): void
    {
        $post = $this->make('image', '2026-11-12 12:30', ['title' => 'Üç metin']);
        $this->assertFalse($post->targets->contains(fn ($t) => $t->account->platform === 'threads'));

        Livewire::actingAs($this->admin)->withQueryParams(['sekme' => 'gonderiler'])->test(SocialPage::class)
            ->call('openPost', $post->id)->assertSee('Bu gönderi Threads için hazırlanmadı.')
            ->call('edit', $post->id)->assertSeeHtml('data-social-edit')
            ->set('editThreads', 'Hasta Google’a ne yazıyor, siz biliyor musunuz?')
            ->call('saveEdit')->assertSee('Kaydedildi.')
            ->assertSee('Hasta Google’a ne yazıyor, siz biliyor musunuz?');
        $post = $post->fresh(['targets.account']);
        $this->assertSame('Hasta Google’a ne yazıyor, siz biliyor musunuz?', $post->threads_text);
        $this->assertTrue($post->targets->contains(fn ($t) => $t->account->platform === 'threads'));

        MoxdopServer::actingAs($this->admin)->tool(SocialPosts::class, ['action' => 'update', 'id' => $post->id, 'threads_text' => 'Claude’un Threads metni'])->assertOk();
        $this->assertSame('Claude’un Threads metni', $post->fresh()->threads_text);
    }

    public function test_conversations_list_waiting_comments_and_results_count_what_went_out(): void
    {
        $instagram = SocialAccount::query()->where('platform', 'instagram')->first();
        SocialThread::query()->create(['social_account_id' => $instagram->id, 'kind' => SocialThread::COMMENT, 'external_id' => 'c1', 'author_name' => 'drayse', 'last_text' => 'Fiyat alabilir miyim?', 'last_direction' => 'in', 'last_at' => now(), 'last_in_at' => now(), 'status' => SocialThread::OPEN]);
        SocialThread::query()->create(['social_account_id' => $instagram->id, 'kind' => SocialThread::DM, 'external_id' => 'd1', 'author_name' => 'ozelmesaj', 'last_text' => 'Merhaba', 'last_direction' => 'in', 'last_at' => now(), 'last_in_at' => now(), 'status' => SocialThread::OPEN]);
        $out = $this->make('reels', '2026-11-09 19:00', ['title' => 'Ölçülen video', 'cover' => true]);
        $out->update(['status' => SocialPost::PUBLISHED]);
        $out->targets()->update(['status' => SocialPostTarget::PUBLISHED, 'published_at' => now()->subDay()]);
        $out->targets()->where('social_account_id', $instagram->id)->update(['insights' => json_encode(['reach' => 1240, 'views' => 1500, 'ig_reels_avg_watch_time' => 2800]), 'insights_at' => now()]);

        Livewire::actingAs($this->admin)->withQueryParams(['sekme' => 'konusmalar'])->test(SocialPage::class)
            ->assertSee('drayse')->assertSee('Fiyat alabilir miyim?')->assertDontSee('ozelmesaj')->assertSee('1 mesaj cevap bekliyor')
            ->call('setTab', 'sonuclar')->assertSee('Son 7 gün')->assertSee('1.240')->assertSee('2,8 sn')->assertSee('Ölçülen video')
            ->assertSee('Ölçüm bu kanalda henüz okunmuyor')
            ->set('period', 28)->assertSee('Son 28 gün');
    }

    public function test_settings_keep_the_schedule_and_fold_finished_setup_steps(): void
    {
        Livewire::actingAs($this->admin)->withQueryParams(['sekme' => 'ayarlar'])->test(SocialPage::class)
            ->assertSeeHtml('data-social-schedule')->assertDontSeeHtml('data-accounts')
            ->set('section', 'hesaplar')->assertSeeHtml('data-accounts')->assertSeeHtml('data-setup-steps')
            ->assertSee('Kurulum adımları ve yeniden bağlama (tamamlandı)')->assertSeeHtml('data-youtube');
    }

    public function test_text_post_goes_only_to_threads_and_linkedin_and_keeps_its_time(): void
    {
        $post = app(SocialPlanner::class)->create(['kind' => 'text', 'title' => 'Sabah gözlemi', 'threads_text' => 'Hasta aramayı çoğu zaman gece yapıyor.', 'publish_at' => '2026-11-11 09:15'], []);
        $this->assertSame(['threads'], $post->targets->map(fn ($t) => $t->account->platform)->all());
        $this->assertTrue($post->pinned);
        $this->assertSame([], $post->mediaItems());

        app(SocialPlanner::class)->approve($post, $this->admin->id);
        app(SocialPlanner::class)->pin($post->fresh(), false);
        $this->assertTrue($post->fresh()->pinned);
        $this->assertSame('2026-11-11 09:15', $post->fresh()->publish_at->timezone('Europe/Istanbul')->format('Y-m-d H:i'));

        $this->expectExceptionMessage('Threads ya da LinkedIn metni gerekir');
        try {
            Livewire::actingAs($this->admin)->withQueryParams(['sekme' => 'instagram'])->test(SocialPage::class)->assertDontSeeHtml('data-social-tile="'.$post->id.'"');
            Livewire::actingAs($this->admin)->withQueryParams(['sekme' => 'gonderiler'])->test(SocialPage::class)
                ->assertSee('Sabah gözlemi')->set('kind', 'text')->assertSee('1–1 / 1 gönderi')
                ->call('openPost', $post->id)->assertSee('Yalnız metin')->assertSee('Hasta aramayı çoğu zaman gece yapıyor.');
            MoxdopServer::actingAs($this->admin)->tool(SocialPosts::class, ['action' => 'draft', 'kind' => 'text', 'threads_text' => 'Claude’un metni', 'publish_at' => '2026-11-12 13:30'])->assertOk();
            $this->assertSame(2, SocialPost::query()->where('kind', 'text')->count());
        } finally {
            app(SocialPlanner::class)->create(['kind' => 'text', 'publish_at' => '2026-11-11 09:15'], []);
        }
    }
}
