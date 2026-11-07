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

        return app(SocialPlanner::class)->create([
            'kind' => $kind, 'caption' => 'Sağlık reklamında yazılamayan beş ifade.', 'linkedin_text' => $linkedin,
            'publish_at' => '2026-11-09 11:30', 'platforms' => $platforms,
        ], $media);
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
        Http::assertSent(fn (Request $r): bool => ($r['media_type'] ?? null) === 'REELS');

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

        Livewire::actingAs($this->admin)->test(SocialPage::class)
            ->assertSee('LinkedIn’de paylaşmanız bekleniyor')
            ->call('markDone', $post->targets->firstWhere('social_account_id', $linkedin->id)->id)
            ->call('retry', $post->id);
        $this->assertSame('done', $post->targets()->where('social_account_id', $linkedin->id)->value('status'));
        $this->assertSame('pending', $ig->fresh()->status);
    }

    public function test_week_is_approved_with_one_click_and_unapproved_drafts_expire(): void
    {
        Http::fake();
        $a = $this->makePost('image');
        $b = $this->makePost('story');
        $later = app(SocialPlanner::class)->create(['kind' => 'image', 'caption' => 'x', 'publish_at' => '2026-11-20 11:30'], [['bytes' => $this->png(), 'name' => 'a.png']]);

        Livewire::actingAs($this->admin)->test(SocialPage::class)
            ->assertSee('Haftayı onayla (2)')
            ->call('approveWeek', '2026-11-09')
            ->call('setTab', 'hesaplar')->assertSee('Meta’yı bağla')->assertSee('@moximucom')
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
}
