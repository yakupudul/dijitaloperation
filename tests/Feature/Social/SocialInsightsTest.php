<?php

namespace Tests\Feature\Social;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Services\Social\SocialInsights;
use App\Services\Social\SocialPublisher;
use App\Services\Social\SocialScreen;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Instagram ölçümü (yakup 2026-11-13): published Instagram posts keep reach, saves, sends, likes, comments and views. */
final class SocialInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_instagram_posts_are_measured_on_their_schedule(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-13 12:00'));
        $ig = SocialAccount::query()->create(['platform' => 'instagram', 'external_id' => 'IG1', 'username' => 'moximucom', 'access_token' => 't', 'page_id' => 'P1']);
        $fb = SocialAccount::query()->create(['platform' => 'facebook', 'external_id' => 'P1', 'name' => 'Moximu', 'access_token' => 't', 'page_id' => 'P1']);
        $target = function (SocialAccount $account, string $kind, string $id, string $at) {
            $post = SocialPost::query()->create(['kind' => $kind, 'caption' => 'x', 'status' => SocialPost::PUBLISHED, 'publish_at' => $at, 'media' => []]);

            return SocialPostTarget::query()->create(['social_post_id' => $post->id, 'social_account_id' => $account->id, 'status' => SocialPostTarget::PUBLISHED, 'external_id' => $id, 'published_at' => $at]);
        };
        $reel = $target($ig, 'reels', 'M1', '2026-11-12 18:00');
        $old = $target($ig, 'image', 'M2', '2026-09-01 12:00');
        $face = $target($fb, 'image', 'F1', '2026-11-12 18:00');
        $seen = [];
        Http::fake(function (Request $request) use (&$seen) {
            $seen[] = $request->url();

            return Http::response(['data' => [
                ['name' => 'reach', 'values' => [['value' => 1240]]], ['name' => 'saved', 'values' => [['value' => 31]]],
                ['name' => 'shares', 'values' => [['value' => 12]]], ['name' => 'views', 'values' => [['value' => 2100]]]]]);
        });

        $this->assertSame(['read' => 1, 'failed' => 0], app(SocialInsights::class)->run());
        $this->assertStringContainsString('/M1/insights', $seen[0]);
        $this->assertStringContainsString('ig_reels_avg_watch_time', urldecode($seen[0]));
        $this->assertSame(['reach' => 1240, 'saved' => 31, 'shares' => 12, 'views' => 2100], $reel->fresh()->insights);
        $this->assertSame('1.240 erişim · 2.100 izlenme · 31 kaydetme · 12 gönderme', SocialInsights::line($reel->fresh()));
        $this->assertNull($old->fresh()->insights);
        $this->assertNull($face->fresh()->insights);

        $this->travel(2)->hours();
        $this->assertSame(['read' => 0, 'failed' => 0], app(SocialInsights::class)->run(), 'not again within three hours');
        $this->travel(2)->hours();
        $this->assertSame(1, app(SocialInsights::class)->run()['read']);
    }

    public function test_linkedin_page_and_threads_posts_are_measured_too(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-18 14:00'));
        $li = SocialAccount::query()->forceCreate(['platform' => 'linkedin', 'external_id' => 'company/moximu', 'name' => 'Moximu', 'access_token' => 'li', 'token_expires_at' => now()->addDays(40), 'meta' => ['org_urn' => 'urn:li:organization:42']]);
        $th = SocialAccount::query()->forceCreate(['platform' => 'threads', 'external_id' => '77', 'username' => 'moximucom', 'access_token' => 'th', 'token_expires_at' => now()->addDays(40)]);
        $me = SocialAccount::query()->forceCreate(['platform' => 'linkedin_me', 'external_id' => 'person/A', 'name' => 'Yakup', 'access_token' => 'me', 'token_expires_at' => now()->addDays(40), 'meta' => ['person_urn' => 'urn:li:person:A']]);
        $target = function (SocialAccount $account, string $id) {
            $post = SocialPost::query()->create(['kind' => 'text', 'status' => SocialPost::PUBLISHED, 'publish_at' => '2026-11-18 13:30', 'media' => []]);

            return SocialPostTarget::query()->create(['social_post_id' => $post->id, 'social_account_id' => $account->id, 'status' => SocialPostTarget::PUBLISHED, 'external_id' => $id, 'published_at' => '2026-11-18 13:30']);
        };
        $share = $target($li, 'urn:li:share:9');
        $thread = $target($th, 'T9');
        $personal = $target($me, 'urn:li:share:10');
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());

            return match (true) {
                str_contains($url, '/socialActions/urn:li:share:9') => Http::response(['likesSummary' => ['totalLikes' => 7], 'commentsSummary' => ['aggregatedTotalComments' => 2]]),
                str_contains($url, 'organizationalEntityShareStatistics') && str_contains($url, 'shares=List(urn:li:share:9)') => Http::response(['elements' => [['totalShareStatistics' => ['impressionCount' => 310, 'uniqueImpressionsCount' => 190, 'clickCount' => 12, 'shareCount' => 1]]]]),
                str_contains($url, '/T9/insights') => Http::response(['data' => [
                    ['name' => 'views', 'values' => [['value' => 85]]], ['name' => 'likes', 'values' => [['value' => 4]]],
                    ['name' => 'replies', 'values' => [['value' => 3]]], ['name' => 'reposts', 'values' => [['value' => 1]]], ['name' => 'quotes', 'values' => [['value' => 0]]]]]),
                default => Http::response(['error' => ['message' => 'x']], 400),
            };
        });

        $this->assertSame(['read' => 2, 'failed' => 0], app(SocialInsights::class)->run());
        $this->assertSame(['likes' => 7, 'comments' => 2, 'views' => 310, 'reach' => 190, 'clicks' => 12, 'shares' => 1], $share->fresh()->insights);
        $this->assertSame(['views' => 85, 'likes' => 4, 'comments' => 3, 'shares' => 1, 'quotes' => 0], $thread->fresh()->insights);
        $this->assertNull($personal->fresh()->insights, 'LinkedIn gives no numbers for personal posts');
        $this->assertSame('85 izlenme · 1 gönderme · 4 beğeni · 3 yorum · 0 alıntı', SocialInsights::line($thread->fresh()));

        $channels = collect(app(SocialScreen::class)->results(7)['channels'])->keyBy('key');
        $this->assertSame(310, $channels['linkedin']['views']);
        $this->assertTrue($channels['threads']['measured']);
        $this->assertFalse($channels['linkedin_me']['measured']);
    }

    public function test_instagram_captions_keep_only_the_first_five_hashtags(): void
    {
        $this->assertSame("Başlık\n\nMetin #a burada.\n\n#b #c #d #e", SocialPublisher::igCaption("Başlık\n\nMetin #a burada.\n\n#b #c #d #e #f #g"));
        $this->assertSame('x #a #b', SocialPublisher::igCaption('x #a #b'));
    }
}
