<?php

namespace Tests\Feature\Social;

use App\Livewire\Operator\Social\SocialPage;
use App\Models\AgencySetting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\SocialPlanner;
use App\Services\Social\SocialPublisher;
use App\Services\Social\ThreadsApi;
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
 * Threads (yakup 2026-11-13): the Threads app is saved on Hesaplar, "Threads'i bağla" stores a 60-day token, and a post
 * that carries a Threads text goes out there as a short text post. Posts without that text never reach Threads.
 */
final class ThreadsPublishTest extends TestCase
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

    public function test_app_is_saved_and_sign_in_stores_a_long_lived_token(): void
    {
        Livewire::actingAs($this->admin)->withQueryParams(['sekme' => 'hesaplar'])->test(SocialPage::class)
            ->set('threadsAppId', 'abc')->set('threadsAppSecret', 'threads-secret')->call('saveThreadsApp')->assertHasErrors('threadsAppId')
            ->set('threadsAppId', '1234567890')->call('saveThreadsApp')->assertHasNoErrors()
            ->assertSeeHtml('data-threads-connect');
        $this->assertSame('threads-secret', AgencySetting::query()->first()->social_threads_app_secret);
        $this->assertNotSame('threads-secret', AgencySetting::query()->toBase()->value('social_threads_app_secret'), 'kept encrypted');

        Http::fake([
            'graph.threads.net/oauth/access_token' => Http::response(['access_token' => 'short', 'user_id' => 77]),
            'graph.threads.net/access_token*' => Http::response(['access_token' => 'long-token', 'expires_in' => 5184000]),
            'graph.threads.net/v1.0/me*' => Http::response(['id' => '77', 'username' => 'moximucom', 'name' => 'Moximu']),
        ]);
        $begin = app(ThreadsApi::class)->begin($this->admin->id);
        parse_str((string) parse_url($begin['url'], PHP_URL_QUERY), $query);
        $this->assertSame('threads_basic,threads_content_publish', $query['scope']);

        $this->actingAs($this->admin)->get('/integrations/threads/callback?code=abc%23_&state='.$query['state'])->assertRedirect();
        $account = SocialAccount::query()->where('platform', 'threads')->firstOrFail();
        $this->assertSame('long-token', $account->access_token);
        $this->assertSame('moximucom', $account->username);
        $this->assertTrue($account->token_expires_at->isAfter(now()->addDays(50)));
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/oauth/access_token') && $r['code'] === 'abc' && $r['client_id'] === '1234567890');
    }

    public function test_only_posts_with_a_threads_text_go_to_threads_as_a_short_text(): void
    {
        SocialAccount::query()->create(['platform' => 'threads', 'external_id' => '77', 'username' => 'moximucom', 'access_token' => 'long-token', 'token_expires_at' => now()->addDays(40), 'enabled' => true]);
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/77/threads_publish') => Http::response(['id' => 'T1']),
                str_contains($url, '/77/threads') => Http::response(['id' => 'C1']),
                str_contains($url, '/T1') => Http::response(['permalink' => 'https://www.threads.net/@moximucom/post/x']),
                default => Http::response(['error' => ['message' => 'x']], 400),
            };
        });
        $planner = app(SocialPlanner::class);
        $plain = $planner->create(['kind' => 'image', 'caption' => 'Yalnız Instagram metni.', 'publish_at' => '2026-11-13 09:00'], [['bytes' => $this->png(), 'name' => 'a.png']]);
        $this->assertSame(0, $plain->targets()->count(), 'no Threads text, no Threads row');

        $long = str_repeat('Hasta kliniği telefonda arıyor ve telefonda karar veriyor. ', 12);
        $post = $planner->create([
            'kind' => 'image', 'caption' => 'x', 'threads_text' => $long."\n\n".'https://moximu.com/rehber/ornek/', 'publish_at' => '2026-11-13 09:00',
        ], [['bytes' => $this->png(), 'name' => 'b.png']]);
        $post->update(['pinned' => true]);
        $planner->approve($post, $this->admin->id);
        $this->travelTo(CarbonImmutable::parse('2026-11-13 09:05', 'Europe/Istanbul'));

        app(SocialPublisher::class)->run();
        $target = $post->targets()->firstOrFail();
        $this->assertSame('published', $target->status);
        $this->assertSame('https://www.threads.net/@moximucom/post/x', $target->permalink);
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/77/threads') && $r['media_type'] === 'TEXT'
            && mb_strlen((string) $r['text']) <= ThreadsApi::MAX_TEXT && str_ends_with((string) $r['text'], 'https://moximu.com/rehber/ornek/'));
    }
}
