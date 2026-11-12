<?php

namespace Tests\Feature\Social;

use App\Livewire\Operator\Social\SocialPage;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\SocialPosts;
use App\Models\AgencySetting;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Social\SocialGraph;
use App\Services\Social\SocialMetaConnector;
use App\Services\Social\SocialPlanner;
use App\Services\Social\SocialPublisher;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ADR-083 Sosyal medya: posts are drafts until the Admin approves them; at their time Instagram (image, carousel, Reels
 * with processing, story) and Facebook receive them; LinkedIn waits as "copy and open"; Claude can only add drafts.
 */
final class SocialMediaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SocialAccount $instagram;

    private SocialAccount $facebook;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 08:00', 'Europe/Istanbul'));
        $this->seed(RoleAndPermissionSeeder::class);
        Storage::fake('public');
        config(['moxdop.meta.app_id' => '123', 'moxdop.meta.app_secret' => 'sec']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->facebook = SocialAccount::query()->create(['platform' => 'facebook', 'external_id' => 'PAGE1', 'name' => 'Moximu', 'access_token' => 'page-token', 'page_id' => 'PAGE1']);
        $this->instagram = SocialAccount::query()->create(['platform' => 'instagram', 'external_id' => 'IG1', 'username' => 'moximucom', 'access_token' => 'page-token', 'page_id' => 'PAGE1']);
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(20, 25);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /** @param  list<string>|null  $platforms */
    private function makePost(string $kind, int $files = 1, ?string $linkedin = null, ?array $platforms = null): SocialPost
    {
        $media = [];
        for ($i = 0; $i < $files; $i++) {
            $media[] = ['bytes' => $kind === 'reels' ? "\0\0\0\x18ftypmp42video" : $this->png(), 'name' => $kind === 'reels' ? 'v.mp4' : 'g'.$i.'.png'];
        }

        $post = app(SocialPlanner::class)->create([
            'kind' => $kind, 'caption' => 'Sağlık reklamında yazılamayan beş ifade.', 'linkedin_text' => $linkedin,
            'publish_at' => '2026-11-09 11:30', 'platforms' => $platforms,
        ], $media);
        // These tests are about a post going out at its own time: a fixed date, outside the daily line.
        $post->update(['pinned' => true]);

        return $post;
    }

    public function test_drafts_are_never_published_and_images_become_jpeg(): void
    {
        Http::fake();
        $post = $this->makePost('image');

        $this->assertSame('draft', $post->status);
        $this->assertStringEndsWith('.jpg', $post->media[0]['path']);
        $this->assertCount(2, $post->targets);

        $this->travel(4)->hours();
        app(SocialPublisher::class)->run();
        Http::assertNothingSent();
        $this->assertSame('draft', $post->fresh()->status);
    }

    public function test_approved_instagram_image_and_facebook_photo_go_out_at_their_time(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/IG1/media_publish') => Http::response(['id' => 'M1']),
                str_contains($url, '/IG1/media') => Http::response(['id' => 'C1']),
                str_contains($url, '/C1') => Http::response(['status_code' => 'FINISHED']),
                str_contains($url, '/M1') => Http::response(['permalink' => 'https://www.instagram.com/p/x/']),
                str_contains($url, '/PAGE1/photos') => Http::response(['id' => 'P1', 'post_id' => 'PAGE1_P1']),
                default => Http::response(['error' => ['message' => 'unexpected '.$url]], 400),
            };
        });
        $post = $this->makePost('image');
        app(SocialPlanner::class)->approve($post, $this->admin->id);

        app(SocialPublisher::class)->run();
        $this->assertSame('approved', $post->fresh()->status, 'not before its time');

        $this->travelTo(CarbonImmutable::parse('2026-11-09 11:31', 'Europe/Istanbul'));
        app(SocialPublisher::class)->run();

        $post->refresh();
        $this->assertSame('published', $post->status);
        $ig = $post->targets->firstWhere('social_account_id', $this->instagram->id);
        $this->assertSame('M1', $ig->external_id);
        $this->assertSame('https://www.instagram.com/p/x/', $ig->permalink);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/IG1/media') && str_contains((string) ($r['image_url'] ?? ''), '.jpg')
            && isset($r['appsecret_proof']) && $r->hasHeader('Authorization', 'Bearer page-token'));
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/PAGE1/photos') && ($r['message'] ?? '') !== '');
    }

    public function test_reels_wait_while_instagram_processes_the_video(): void
    {
        $status = 'IN_PROGRESS';
        Http::fake(function (Request $request) use (&$status) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/IG1/media_publish') => Http::response(['id' => 'R1']),
                str_contains($url, '/IG1/media') => Http::response(['id' => 'C9']),
                str_contains($url, '/C9') => Http::response(['status_code' => $status]),
                str_contains($url, '/R1') => Http::response(['permalink' => 'https://www.instagram.com/reel/r/']),
                default => Http::response(['error' => ['message' => 'x']], 400),
            };
        });
        $post = $this->makePost('reels', 1, null, ['instagram']);
        app(SocialPlanner::class)->approve($post, $this->admin->id);
        $this->travelTo(CarbonImmutable::parse('2026-11-09 11:35', 'Europe/Istanbul'));

        app(SocialPublisher::class)->run();
        $target = $post->targets()->first();
        $this->assertSame('processing', $target->status);
        $this->assertSame('C9', $target->container_id);
        Http::assertSent(fn (Request $r): bool => ($r['media_type'] ?? null) === 'REELS' && ! isset($r['cover_url']));

        $status = 'FINISHED';
        app(SocialPublisher::class)->run();
        $this->assertSame('published', $target->fresh()->status);
        Http::assertSentCount(5); // create, status, status, publish, permalink
    }

    public function test_carousel_creates_children_then_one_container(): void
    {
        Http::fake(function (Request $request) {
            static $n = 0;
            $url = $request->url();
            if (str_contains($url, '/IG1/media_publish')) {
                return Http::response(['id' => 'K1']);
            }
            if (str_contains($url, '/IG1/media')) {
                $n++;

                return Http::response(['id' => 'CH'.$n]);
            }

            return Http::response(['status_code' => 'FINISHED', 'permalink' => 'https://www.instagram.com/p/k/']);
        });
        $post = $this->makePost('carousel', 3, null, ['instagram']);
        app(SocialPlanner::class)->approve($post, $this->admin->id);
        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00', 'Europe/Istanbul'));
        app(SocialPublisher::class)->run();

        Http::assertSent(fn (Request $r): bool => ($r['media_type'] ?? null) === 'CAROUSEL' && $r['children'] === 'CH1,CH2,CH3');
        $this->assertSame('published', $post->fresh()->status);
    }

    public function test_linkedin_waits_as_copy_and_open_and_permanent_errors_are_not_retried(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Bu izin verilmedi.', 'code' => 10]], 403)]);
        $linkedin = SocialAccount::query()->create(['platform' => 'linkedin', 'external_id' => 'company/moximu', 'name' => 'Moximu', 'meta' => ['url' => 'https://www.linkedin.com/company/moximu/']]);
        $story = $this->makePost('story');
        $this->assertCount(2, $story->targets, 'a story never goes to LinkedIn');

        $post = $this->makePost('image', 1, 'LinkedIn metni');
        $this->assertCount(3, $post->targets);
        app(SocialPlanner::class)->approve($post, $this->admin->id);
        $this->travelTo(CarbonImmutable::parse('2026-11-09 11:31', 'Europe/Istanbul'));
        app(SocialPublisher::class)->run();
        app(SocialPublisher::class)->run();

        $post->refresh();
        $this->assertSame('manual', $post->targets->firstWhere('social_account_id', $linkedin->id)->status);
        $ig = $post->targets->firstWhere('social_account_id', $this->instagram->id);
        $this->assertSame('failed', $ig->status);
        $this->assertSame(SocialPostTarget::MAX_ATTEMPTS, $ig->attempts);
        $this->assertSame('Bu izin verilmedi.', $ig->error);
        $this->assertSame('partial', $post->status);

        Livewire::actingAs($this->admin)->withQueryParams(['sekme' => 'plan'])->test(SocialPage::class)
            ->assertSee('LinkedIn’de paylaşmanız bekleniyor')
            ->call('markDone', $post->targets->firstWhere('social_account_id', $linkedin->id)->id)
            ->call('retry', $post->id);
        $this->assertSame('done', $post->targets()->where('social_account_id', $linkedin->id)->value('status'));
        $this->assertSame('pending', $ig->fresh()->status);
    }

    public function test_reels_with_an_image_after_the_video_sends_it_as_the_cover(): void
    {
        Http::fake(['*/IG1/media' => Http::response(['id' => 'C9']), '*' => Http::response(['status_code' => 'IN_PROGRESS'])]);
        $post = app(SocialPlanner::class)->create(['kind' => 'reels', 'caption' => 'x', 'publish_at' => '2026-11-09 11:30', 'platforms' => ['instagram']], [
            ['bytes' => "\0\0\0\x18ftypmp42video", 'name' => 'v.mp4'], ['bytes' => $this->png(), 'name' => 'kapak.png'],
        ]);
        $post->update(['pinned' => true]);
        app(SocialPlanner::class)->approve($post, $this->admin->id);
        $this->travelTo(CarbonImmutable::parse('2026-11-09 11:35', 'Europe/Istanbul'));

        app(SocialPublisher::class)->run();

        Http::assertSent(fn (Request $r): bool => ($r['media_type'] ?? null) === 'REELS' && str_ends_with((string) ($r['cover_url'] ?? ''), '.jpg') && str_ends_with((string) $r['video_url'], '.mp4'));
    }

    public function test_week_is_approved_with_one_click_and_unapproved_drafts_expire(): void
    {
        Http::fake();
        $a = $this->makePost('image');
        $b = $this->makePost('story');
        $later = app(SocialPlanner::class)->create(['kind' => 'image', 'caption' => 'x', 'publish_at' => '2026-11-20 11:30'], [['bytes' => $this->png(), 'name' => 'a.png']]);

        Livewire::actingAs($this->admin)->withQueryParams(['sekme' => 'plan'])->test(SocialPage::class)
            ->assertSee('Haftayı onayla (2)')
            ->call('approveWeek', '2026-11-09')
            ->call('setTab', 'hesaplar')->assertSee('Meta’yı yeniden bağla')->assertSee('@moximucom')
            ->call('setTab', 'yeni')->assertSee('Plana ekle')
            ->call('setTab', 'gecmis')->assertSee('Henüz yayınlanan gönderi yok.');
        $this->get(route('operator.social'))->assertOk()->assertSee('Sosyal medya');
        $this->assertSame(['approved', 'approved', 'draft'], [$a->fresh()->status, $b->fresh()->status, $later->fresh()->status]);

        $this->travelTo(CarbonImmutable::parse('2026-11-22 12:00', 'Europe/Istanbul'));
        Http::fake(['*' => Http::response(['error' => ['message' => 'x', 'code' => 10]], 403)]);
        app(SocialPublisher::class)->run();
        $this->assertSame('expired', $later->fresh()->status);
    }

    public function test_upload_form_validates_files(): void
    {
        // Livewire's test uploads arrive empty, so only the form rules are checked here; storing is covered above.
        Livewire::actingAs($this->admin)->test(SocialPage::class)
            ->set('newKind', 'carousel')
            ->set('newTime', '2026-11-11T11:30')
            ->call('createPost')
            ->assertHasErrors(['newFiles']);
    }

    public function test_non_admin_cannot_approve(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(Roles::TEAM_MEMBER);
        $post = $this->makePost('image');
        Livewire::actingAs($viewer)->test(SocialPage::class)->call('approve', $post->id)->assertForbidden();
        $this->assertSame('draft', $post->fresh()->status);
    }

    public function test_claude_adds_only_drafts_through_mcp(): void
    {
        MoxdopServer::tool(SocialPosts::class, [
            'action' => 'draft', 'kind' => 'image', 'pillar' => 'rehber', 'title' => 'Mevzuat', 'caption' => 'Metin',
            'publish_at' => '2026-11-10 11:30', 'media' => [['base64' => base64_encode($this->png()), 'name' => 'a.png']],
        ])->assertOk()->assertSee('onayını bekliyor');

        $post = SocialPost::query()->latest('id')->first();
        $this->assertSame('draft', $post->status);
        $this->assertSame('claude', $post->created_by);
        $this->assertSame('2026-11-10 08:30', $post->publish_at->utc()->format('Y-m-d H:i'));

        MoxdopServer::tool(SocialPosts::class, ['action' => 'list'])->assertOk()->assertSee('Mevzuat');
    }

    public function test_social_login_stores_page_and_instagram_and_callback_is_shared(): void
    {
        SocialAccount::query()->delete();
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'user-token']),
            '*/me/accounts*' => Http::response(['data' => [[
                'id' => 'PG', 'name' => 'Moximu', 'access_token' => 'pg-token',
                'instagram_business_account' => ['id' => 'IGX', 'username' => 'moximucom'],
            ]]]),
        ]);
        $this->actingAs($this->admin);
        $redirect = $this->get(route('integrations.meta.social.authorize'));
        $redirect->assertRedirect();
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertStringContainsString('instagram_content_publish', $query['scope']);
        $this->assertTrue(SocialMetaConnector::ownsState($query['state']));

        $this->get(route('integrations.meta.callback', ['code' => 'abc', 'state' => $query['state']]))
            ->assertRedirect(route('operator.social', ['sekme' => 'hesaplar']));

        $ig = SocialAccount::query()->where('platform', 'instagram')->first();
        $this->assertSame('moximucom', $ig->username);
        $this->assertSame('pg-token', $ig->access_token);
        $this->assertSame('PG', $ig->page_id);
        $this->assertSame(1, SocialAccount::query()->where('platform', 'facebook')->count());
        $this->assertFalse(SocialMetaConnector::ownsState($query['state']));
    }

    public function test_social_login_with_config_forces_code_and_refuses_whatsapp_config(): void
    {
        CoreIntegration::factory()->create(['provider' => 'whatsapp', 'config' => ['signup_config_id' => '1757572378897162']]);
        $setting = AgencySetting::query()->first() ?? new AgencySetting;
        $setting->forceFill(['social_meta_config_id' => '1757572378897162'])->save();
        $this->actingAs($this->admin);
        $this->get(route('integrations.meta.social.authorize'))->assertRedirect();
        $this->assertArrayHasKey('error', app(SocialMetaConnector::class)->begin($this->admin->id));

        $setting->forceFill(['social_meta_config_id' => '1075374242150295'])->save();
        $url = app(SocialMetaConnector::class)->begin($this->admin->id)['url'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('1075374242150295', $query['config_id']);
        $this->assertSame('true', $query['override_default_response_type']);
        $this->assertArrayNotHasKey('scope', $query);
    }

    public function test_social_login_reports_missing_page_permissions(): void
    {
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'user-token']),
            '*/me/permissions*' => Http::response(['data' => [['permission' => 'whatsapp_business_messaging', 'status' => 'granted']]]),
        ]);
        $this->actingAs($this->admin);
        $redirect = $this->get(route('integrations.meta.social.authorize'));
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $result = app(SocialMetaConnector::class)->handleCallback('abc', $query['state'], null, $this->admin->id);
        $this->assertStringContainsString('pages_show_list', $result['error'] ?? '');
    }

    public function test_social_can_use_its_own_meta_app(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(SocialPage::class)
            ->set('socialAppId', '1376608000853003')->set('socialAppSecret', str_repeat('a1', 16))
            ->call('saveSocialApp')->assertHasNoErrors()->assertSet('socialAppSecret', '');

        $url = app(SocialMetaConnector::class)->begin($this->admin->id)['url'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('1376608000853003', $query['client_id']);
        $this->assertSame(str_repeat('a1', 16), app(SocialGraph::class)->appSecret());
        $this->assertArrayNotHasKey('social_meta_app_secret', AgencySetting::query()->first()->toArray());

        Livewire::test(SocialPage::class)->set('socialAppId', '')->call('saveSocialApp');
        $this->assertNull(SocialGraph::ownApp());
    }

    public function test_social_uses_the_whatsapp_app_from_integrations(): void
    {
        $row = CoreIntegration::factory()->create(['provider' => 'whatsapp', 'config' => ['app_id' => '1376608000853003']]);
        $row->providerCredential()->create(['credential_type' => CoreIntegrationCredential::TYPE_PROVIDER, 'encrypted_payload' => ['app_secret' => 'wa-secret']]);

        $this->assertSame(['id' => '1376608000853003', 'secret' => 'wa-secret'], SocialGraph::ownApp());
        $url = app(SocialMetaConnector::class)->begin($this->admin->id)['url'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('1376608000853003', $query['client_id']);
    }

    public function test_meta_login_result_is_shown_on_hesaplar_and_kept_as_last_attempt(): void
    {
        SocialAccount::query()->delete();
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'user-token']),
            '*/me/accounts*' => Http::response(['data' => [['id' => 'PG', 'name' => 'Moximu', 'access_token' => 'pg-token']]]),
        ]);
        $this->actingAs($this->admin);
        $redirect = $this->get(route('integrations.meta.social.authorize'));
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->get(route('integrations.meta.callback', ['code' => 'abc', 'state' => $query['state']]));

        $this->assertTrue(SocialMetaConnector::lastAttempt()['ok']);
        Livewire::withQueryParams(['sekme' => 'hesaplar'])->test(SocialPage::class)
            ->assertSet('messageTone', 'success')
            ->assertSee('Bağlandı: Facebook: Moximu')
            ->assertSee('Son deneme')
            // Facebook came without Instagram: step 1 says how to link the Instagram account to the Page.
            ->assertSeeHtml('data-ig-missing')
            ->assertSeeHtml('data-meta-connect');
    }

    public function test_unknown_tab_falls_back_to_plan_and_integrations_hub_links_own_channels(): void
    {
        $this->actingAs($this->admin);
        Livewire::withQueryParams(['sekme' => 'x'])->test(SocialPage::class)->assertSet('tab', 'instagram');

        $this->get(route('operator.integrations'))->assertOk()
            ->assertSee('Moximu hesapları')
            ->assertSee('Instagram ve Facebook')
            ->assertSee(route('operator.social', ['sekme' => 'hesaplar']), false);
    }

    public function test_meta_login_switches_on_only_moximu_and_keeps_customer_pages_off(): void
    {
        SocialAccount::query()->delete();
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'user-token']),
            '*/me/accounts*' => Http::response(['data' => [
                ['id' => 'P1', 'name' => 'Avrupadent', 'access_token' => 't1', 'instagram_business_account' => ['id' => 'I1', 'username' => 'avrupadent']],
                ['id' => 'P2', 'name' => 'Moximu - Web Site Tasarım', 'access_token' => 't2', 'instagram_business_account' => ['id' => 'I2', 'username' => 'moximucom']],
            ]]),
        ]);
        $result = app(SocialMetaConnector::class)->storePages('user-token');

        $this->assertSame(['Facebook: Moximu - Web Site Tasarım', 'Instagram: @moximucom', '(diğer 1 sayfa kapalı olarak listede)'], $result['accounts']);
        $this->assertEqualsCanonicalizing(['P2', 'I2'], SocialAccount::query()->where('enabled', true)->pluck('external_id')->all());
        $this->assertSame(2, SocialAccount::query()->where('enabled', false)->count());

        // A page the operator switched on by hand stays on after the next login.
        SocialAccount::query()->where('external_id', 'P1')->update(['enabled' => true]);
        app(SocialMetaConnector::class)->storePages('user-token');
        $this->assertTrue((bool) SocialAccount::query()->where('external_id', 'P1')->value('enabled'));

        $this->actingAs($this->admin);
        Livewire::withQueryParams(['sekme' => 'hesaplar'])->test(SocialPage::class)->assertSeeHtml('data-off-accounts')->assertSee('Kapalı hesaplar (1)');
    }

    public function test_instagram_view_shows_ready_and_published_posts_and_publishes_or_takes_down(): void
    {
        Http::fake([
            '*/IG1/media*' => Http::response(['data' => [
                ['id' => '1789', 'permalink' => 'https://www.instagram.com/p/abc/', 'caption' => 'Bizden', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example/a.jpg', 'timestamp' => '2026-11-08T10:00:00+0000', 'like_count' => 4, 'comments_count' => 1],
                ['id' => '2001', 'permalink' => 'https://www.instagram.com/p/tel/', 'caption' => 'Telefondan paylaşılan', 'media_type' => 'VIDEO', 'thumbnail_url' => 'https://cdn.example/t.jpg', 'timestamp' => '2026-11-07T10:00:00+0000', 'like_count' => 9, 'comments_count' => 2],
            ]]),
            '*' => Http::response(['success' => true]),
        ]);
        $ready = $this->makePost('image');
        $out = $this->makePost('image');
        $out->update(['status' => SocialPost::PUBLISHED, 'publish_at' => now()->subDay()]);
        $out->targets()->update(['status' => SocialPostTarget::PUBLISHED, 'external_id' => '1789', 'permalink' => 'https://www.instagram.com/p/abc/']);
        $fbTarget = $out->targets()->whereHas('account', fn ($q) => $q->where('platform', 'facebook'))->first();

        $page = Livewire::actingAs($this->admin)->test(SocialPage::class)->assertSet('tab', 'instagram')
            ->assertSeeHtml('data-social-tile="'.$ready->id.'"')->assertSeeHtml('data-social-tile="'.$out->id.'"')->assertSee('Onay bekliyor')
            ->call('openPost', $ready->id)->assertSeeHtml('data-social-publish-now')
            ->call('publishNow', $ready->id);
        $this->assertSame('approved', $ready->fresh()->status);
        $this->assertTrue($ready->fresh()->publish_at->lessThanOrEqualTo(now()));

        $page->assertSeeHtml('data-social-tile="ig-2001"')->assertDontSeeHtml('data-social-tile="ig-1789"')
            ->call('openIgPost', '2001')->assertSee('Telefondan paylaşılan')->call('closePost');
        $page->call('openPost', $out->id)->assertSee('Instagram\'da aç', false)->call('takeDown', $out->id)->assertSee('Instagram tarafında');
        $this->assertNotNull($out->fresh()->removed_at);
        if ($fbTarget !== null) {
            Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), '/1789'));
        }
        Livewire::actingAs($this->admin)->test(SocialPage::class)->assertDontSeeHtml('data-social-tile="'.$out->id.'"');
    }
}
