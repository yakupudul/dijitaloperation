<?php

namespace Tests\Feature\Social;

use App\Livewire\Operator\Social\SocialPage;
use App\Models\AgencySetting;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\LinkedInApi;
use App\Services\Social\LinkedInConnector;
use App\Services\Social\SocialPlanner;
use App\Services\Social\SocialPublisher;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * LinkedIn şirket sayfasına otomatik paylaşım (yakup 2026-11-13): the LinkedIn app is saved on Hesaplar, "LinkedIn'i
 * bağla" stores a page admin's token with the Moximu organization, approved posts with a LinkedIn text then go out
 * through the Posts API; without a usable token they keep waiting as "copy and open".
 */
final class LinkedInPublishTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-13 08:00', 'Europe/Istanbul'));
        $this->seed(RoleAndPermissionSeeder::class);
        Storage::fake('public');
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(20, 25);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function linkedin(array $extra = []): SocialAccount
    {
        return SocialAccount::query()->forceCreate($extra + [
            'platform' => 'linkedin', 'external_id' => 'company/moximu', 'name' => 'Moximu', 'access_token' => 'li-token',
            'token_expires_at' => now()->addDays(50), 'meta' => ['url' => 'https://www.linkedin.com/company/moximu/', 'org_urn' => 'urn:li:organization:42'],
        ]);
    }

    private function approvedPost(string $kind, int $files, ?string $linkedin): SocialPost
    {
        $media = [];
        for ($i = 0; $i < $files; $i++) {
            $media[] = $kind === 'reels' ? ['bytes' => "\0\0\0\x18ftypmp42video", 'name' => 'v.mp4'] : ['bytes' => $this->png(), 'name' => 'g'.$i.'.png'];
        }
        $post = app(SocialPlanner::class)->create([
            'kind' => $kind, 'caption' => 'Instagram metni', 'linkedin_text' => $linkedin, 'publish_at' => '2026-11-13 11:30',
        ], $media);
        $post->update(['pinned' => true]);
        app(SocialPlanner::class)->approve($post, $this->admin->id);

        return $post->fresh();
    }

    public function test_little_text_escapes_reserved_characters_and_keeps_hashtags(): void
    {
        $this->assertSame(
            'Klinikler \\(2026\\) için 3 adım - \\@moximu {hashtag|\\#|klinikpazarlama} \\*not\\*',
            LinkedInApi::littleText('Klinikler (2026) için 3 adım - @moximu #klinikpazarlama *not*'),
        );
        $this->assertSame('Sağlık \[rehber\] {hashtag|\#|dişhekimi}', LinkedInApi::littleText('Sağlık [rehber] #dişhekimi'));
    }

    public function test_connected_page_receives_image_and_carousel_posts(): void
    {
        $account = $this->linkedin();
        $posts = [];
        Http::fake(function (Request $request) use (&$posts) {
            $url = $request->url();
            if (str_contains($url, '/rest/images?action=initializeUpload')) {
                static $n = 0;
                $n++;

                return Http::response(['value' => ['uploadUrl' => 'https://upload.linkedin.test/img'.$n, 'image' => 'urn:li:image:I'.$n]]);
            }
            if (str_starts_with($url, 'https://upload.linkedin.test/')) {
                return Http::response('', 201);
            }
            if (str_ends_with($url, '/rest/posts')) {
                $posts[] = $request->data();

                return Http::response('', 201, ['x-restli-id' => 'urn:li:share:'.count($posts)]);
            }

            return Http::response(['message' => 'unexpected '.$url], 400);
        });

        $single = $this->approvedPost('image', 1, 'Tek görsel (deneme) #moximu');
        $carousel = $this->approvedPost('carousel', 3, 'Üç görsel');
        $this->assertNotNull($single->targets->firstWhere('social_account_id', $account->id));
        $this->travelTo(CarbonImmutable::parse('2026-11-13 11:31', 'Europe/Istanbul'));
        app(SocialPublisher::class)->run();

        $target = $single->targets()->where('social_account_id', $account->id)->first();
        $this->assertSame('published', $target->status, (string) $target->error);
        $this->assertStringStartsWith('https://www.linkedin.com/feed/update/urn:li:share:', (string) $target->permalink);
        $one = collect($posts)->first(fn (array $p): bool => isset($p['content']['media']));
        $many = collect($posts)->first(fn (array $p): bool => isset($p['content']['multiImage']));
        $this->assertSame('urn:li:organization:42', $one['author']);
        $this->assertSame('Tek görsel \(deneme\) {hashtag|\#|moximu}', $one['commentary']);
        $this->assertStringStartsWith('urn:li:image:I', $one['content']['media']['id']);
        $this->assertCount(3, $many['content']['multiImage']['images']);
        $this->assertSame('published', $carousel->fresh()->status);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'api.linkedin.com') && $r->hasHeader('X-Restli-Protocol-Version', '2.0.0') && $r->hasHeader('LinkedIn-Version'));
    }

    public function test_video_waits_until_linkedin_finished_processing(): void
    {
        $account = $this->linkedin();
        $status = 'PROCESSING';
        Http::fake(function (Request $request) use (&$status) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/rest/videos?action=initializeUpload') => Http::response(['value' => ['video' => 'urn:li:video:V1', 'uploadToken' => 't',
                    'uploadInstructions' => [['uploadUrl' => 'https://upload.linkedin.test/v1', 'firstByte' => 0, 'lastByte' => 16]]]]),
                str_starts_with($url, 'https://upload.linkedin.test/') => Http::response('', 200, ['ETag' => '"etag1"']),
                str_contains($url, '/rest/videos?action=finalizeUpload') => Http::response('', 200),
                str_contains($url, '/rest/videos/') => Http::response(['status' => $status]),
                str_ends_with($url, '/rest/posts') => Http::response('', 201, ['x-restli-id' => 'urn:li:ugcPost:9']),
                default => Http::response(['message' => 'unexpected '.$url], 400),
            };
        });
        $post = $this->approvedPost('reels', 1, 'Video metni');
        $this->travelTo(CarbonImmutable::parse('2026-11-13 11:31', 'Europe/Istanbul'));
        app(SocialPublisher::class)->run();
        $target = $post->targets()->where('social_account_id', $account->id)->first();
        $this->assertSame('processing', $target->status, (string) $target->error);
        $this->assertSame('urn:li:video:V1', $target->container_id);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'finalizeUpload') && $r['finalizeUploadRequest']['uploadedPartIds'] === ['etag1']);

        $status = 'AVAILABLE';
        app(SocialPublisher::class)->run();
        $this->assertSame('published', $target->fresh()->status);
        Http::assertSentCount(6);
    }

    public function test_without_a_token_linkedin_stays_copy_and_open_and_an_ending_token_is_renewed(): void
    {
        $manual = SocialAccount::query()->forceCreate(['platform' => 'linkedin', 'external_id' => 'company/moximu', 'name' => 'Moximu', 'meta' => ['url' => 'https://www.linkedin.com/company/moximu/']]);
        $this->assertTrue($manual->isManual());
        $manual->forceFill(['access_token' => 'old', 'refresh_token' => 'r1', 'token_expires_at' => now()->addDays(2), 'meta' => $manual->meta + ['org_urn' => 'urn:li:organization:42']])->save();
        $this->assertFalse($manual->fresh()->isManual());
        $manual->forceFill(['refresh_token' => null, 'token_expires_at' => now()->subDay()])->save();
        $this->assertTrue($manual->fresh()->isManual(), 'an ended token without refresh falls back to copy and open');

        AgencySetting::query()->forceCreate(['social_linkedin_client_id' => 'abc12345', 'social_linkedin_client_secret' => 'secret-xyz']);
        $manual->forceFill(['access_token' => 'old', 'refresh_token' => 'r1', 'token_expires_at' => now()->addDays(2)])->save();
        Http::fake(['www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'new', 'expires_in' => 5184000, 'refresh_token' => 'r2'])]);
        $this->assertSame('new', app(LinkedInApi::class)->freshToken($manual->fresh()));
        $manual->refresh();
        $this->assertSame('new', $manual->access_token);
        $this->assertSame('r2', $manual->refresh_token);
        $this->assertTrue($manual->token_expires_at->isAfter(now()->addDays(59)));
    }

    public function test_hesaplar_saves_the_app_and_the_login_picks_the_moximu_page(): void
    {
        Livewire::actingAs($this->admin)->withQueryParams(['sekme' => 'hesaplar'])->test(SocialPage::class)
            ->assertSee('Community Management API')
            ->set('linkedinClientId', '77abcdefgh12')
            ->set('linkedinClientSecret', 'WPL_AP1.secret')
            ->call('saveLinkedinApp')
            ->assertHasNoErrors()
            ->assertSee('LinkedIn’i bağla');
        $this->assertSame('WPL_AP1.secret', AgencySetting::query()->first()->social_linkedin_client_secret);

        $redirect = $this->actingAs($this->admin)->get(route('integrations.linkedin.authorize'));
        $location = (string) $redirect->headers->get('Location');
        $this->assertStringStartsWith('https://www.linkedin.com/oauth/v2/authorization?', $location);
        $this->assertStringContainsString('w_organization_social', urldecode($location));
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        Http::fake([
            'www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'tok', 'expires_in' => 5184000, 'scope' => 'w_organization_social']),
            'api.linkedin.com/rest/organizationAcls*' => Http::response(['elements' => [
                ['organization' => 'urn:li:organization:7', 'role' => 'ADMINISTRATOR'],
                ['organization' => 'urn:li:organization:42', 'role' => 'ADMINISTRATOR'],
            ]]),
            'api.linkedin.com/rest/organizations/7' => Http::response(['localizedName' => 'Başka Klinik', 'vanityName' => 'baska-klinik']),
            'api.linkedin.com/rest/organizations/42' => Http::response(['localizedName' => 'Moximu', 'vanityName' => 'moximu']),
        ]);
        $this->actingAs($this->admin)->get(route('integrations.linkedin.callback', ['code' => 'c1', 'state' => $query['state']]))
            ->assertRedirect(route('operator.social', ['sekme' => 'hesaplar']));

        $account = SocialAccount::query()->where('platform', 'linkedin')->sole();
        $this->assertSame('urn:li:organization:42', $account->meta['org_urn']);
        $this->assertSame('tok', $account->access_token);
        $this->assertFalse($account->isManual());
        $this->assertTrue(LinkedInConnector::lastAttempt()['ok']);
        $this->assertFalse(Cache::has('social_linkedin_oauth:'.hash('sha256', $query['state'])));
    }
}
