<?php

namespace Tests\Feature\Social;

use App\Livewire\Operator\Social\SocialPage;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\SocialTrendWatch;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sosyal › Trendler (yakup 2026-11-13): other professional accounts are read through the official API and a post
 * that got at least twice that account's usual likes and comments in the last three weeks is shown as a hit.
 */
final class SocialTrendWatchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-13 08:00', 'Europe/Istanbul'));
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        SocialAccount::query()->create(['platform' => 'instagram', 'external_id' => 'IG1', 'username' => 'moximucom', 'access_token' => 't', 'page_id' => 'P1']);
    }

    private function fakeGraph(): void
    {
        $media = [];
        foreach ([10, 12, 9, 11, 10, 8] as $i => $likes) {
            $media[] = ['permalink' => 'https://www.instagram.com/p/n'.$i.'/', 'caption' => 'sıradan', 'media_type' => 'IMAGE', 'media_product_type' => 'FEED', 'timestamp' => '2026-11-0'.($i + 1).'T10:00:00+0000', 'like_count' => $likes, 'comments_count' => 0];
        }
        $media[] = ['permalink' => 'https://www.instagram.com/reel/hit/', 'caption' => 'Tutan video', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS', 'timestamp' => '2026-11-10T10:00:00+0000', 'like_count' => 50, 'comments_count' => 5];
        $media[] = ['permalink' => 'https://www.instagram.com/reel/eski/', 'caption' => 'Eski tutan', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS', 'timestamp' => '2026-09-01T10:00:00+0000', 'like_count' => 90, 'comments_count' => 0];
        Http::fake(function (Request $request) use ($media) {
            if (str_contains(urldecode($request->url()), 'username(kapali)')) {
                return Http::response(['error' => ['message' => 'Invalid user id', 'code' => 110]], 400);
            }

            return Http::response(['business_discovery' => ['username' => 'ornekhesap', 'followers_count' => 4200, 'media' => ['data' => $media]], 'id' => 'IG1']);
        });
    }

    public function test_names_are_cleaned_and_a_post_far_above_the_account_median_is_a_hit(): void
    {
        $watch = app(SocialTrendWatch::class);
        $this->assertSame(['ornekhesap', 'kapali'], $watch->save(['@OrnekHesap', 'https://www.instagram.com/kapali/?hl=tr', 'ornekhesap', 'iki kelime', 5]));
        $this->assertSame(['ornekhesap', 'kapali'], $watch->accounts());
        $this->assertSame([], $watch->reading()['hits']);
        Http::assertNothingSent();

        $this->fakeGraph();
        $reading = $watch->reading(true);

        $this->assertCount(1, $reading['hits']);
        $this->assertSame('https://www.instagram.com/reel/hit/', $reading['hits'][0]['permalink']);
        $this->assertSame('reels', $reading['hits'][0]['type']);
        $this->assertSame(5.0, $reading['hits'][0]['times']);
        $this->assertSame([['account' => 'ornekhesap', 'followers' => 4200, 'posts' => 8, 'median' => 11]], $reading['accounts']);
        $this->assertArrayHasKey('kapali', $reading['errors']);

        // The reading is kept; a second look does not call Instagram again.
        Http::fake(fn () => $this->fail('okuma saklanmalıydı'));
        $this->assertCount(1, $watch->reading()['hits']);
    }

    public function test_admin_saves_the_list_and_reads_on_the_trends_tab(): void
    {
        $this->fakeGraph();
        Livewire::actingAs($this->admin)->test(SocialPage::class)
            ->call('setTab', 'trendler')
            ->set('watchlist', "@ornekhesap\nkapali")
            ->call('saveWatchlist')
            ->call('readTrends')
            ->assertSee('Tutan video')
            ->assertSee('ortalamasının 5 katı')
            ->assertSee('kapali');
        $this->assertSame(['ornekhesap', 'kapali'], app(SocialTrendWatch::class)->accounts());
    }
}
