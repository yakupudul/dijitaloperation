<?php

namespace Tests\Feature\Social;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Services\Social\SocialInsights;
use App\Services\Social\SocialPublisher;
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

    public function test_instagram_captions_keep_only_the_first_five_hashtags(): void
    {
        $this->assertSame("Başlık\n\nMetin #a burada.\n\n#b #c #d #e", SocialPublisher::igCaption("Başlık\n\nMetin #a burada.\n\n#b #c #d #e #f #g"));
        $this->assertSame('x #a #b', SocialPublisher::igCaption('x #a #b'));
    }
}
