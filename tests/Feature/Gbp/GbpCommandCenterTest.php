<?php

namespace Tests\Feature\Gbp;

use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\ContentCalendarItem;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Services\CommandCenter\CommandCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Komuta merkezi Business Profile items: late replies, no recent post, reviews Google refuses, profile gaps. */
final class GbpCommandCenterTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    private CoreExternalResource $resource;

    private int $runId;

    protected function setUp(): void
    {
        parent::setUp();
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas']);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'name' => 'Atlas Çankaya']);
        $this->resource = CoreExternalResource::factory()->create(['provider' => 'google', 'resource_type' => 'google_business_profile', 'external_id' => 'locations/22', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        $binding = CoreAssetBinding::factory()->create(['digital_asset_id' => $this->asset->id, 'external_resource_id' => $this->resource->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $this->runId = (int) Run::query()->create(['digital_asset_id' => $this->asset->id, 'core_asset_binding_id' => $binding->id, 'module_id' => 'google-business-profile', 'status' => 'completed',
            'started_at' => now(), 'finished_at' => now(), 'metadata' => ['datasets' => ['gbp_reviews' => ['status' => 'available', 'rows' => 1]]]])->id;
    }

    private function review(string $id, string $stars, int $hoursAgo, bool $replied = false): void
    {
        DB::table('gbp_reviews')->insert(['external_resource_id' => $this->resource->id, 'location_name' => 'locations/22', 'run_id' => $this->runId, 'review_id' => $id,
            'star_rating' => $stars, 'comment' => 'x', 'create_time' => now()->subHours($hoursAgo), 'review_reply' => $replied ? json_encode(['comment' => 'ok']) : null,
            'raw_payload' => '{}', 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function snapshot(array $overrides = []): void
    {
        DB::table('gbp_location_snapshots')->insert($overrides + ['digital_asset_id' => $this->asset->id, 'external_resource_id' => $this->resource->id, 'run_id' => $this->runId,
            'location_name' => 'locations/22', 'title' => 'Atlas', 'primary_category' => 'Diş kliniği', 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, array<string, mixed>> */
    private function items(): array
    {
        return app(CommandCenter::class)->items()->where('source', 'gbp')->keyBy('key')->all();
    }

    public function test_reviews_waiting_more_than_48_hours_are_listed_and_link_to_the_reviews_tab(): void
    {
        $this->review('late', 'FOUR', 72);
        $this->review('fresh', 'FIVE', 5);
        $this->review('answered', 'FIVE', 100, true);

        $item = $this->items()['gbp:reviews-'.$this->asset->id];
        $this->assertSame('1 yorum 48 saatten uzun süredir yanıt bekliyor', $item['title']);
        $this->assertSame('gbp:reviews_unanswered', $item['topic']);
        $this->assertSame('Yanıt bekleyen yorumlar', $item['topic_label']);
        $this->assertStringContainsString('tab=reviews', (string) $item['url']);
    }

    public function test_an_open_low_rating_alert_covers_the_late_reply_item(): void
    {
        $this->review('late-bad', 'ONE', 72);
        AssetAlert::query()->create(['digital_asset_id' => $this->asset->id, 'brand_id' => $this->asset->brand_id, 'alert_key' => 'bad_review_unanswered', 'kind' => 'bad_review_unanswered', 'severity' => 'high',
            'title' => 'Yanıtsız düşük puanlı yorum', 'message' => 'x', 'first_detected_at' => now(), 'last_detected_at' => now()]);

        $this->assertArrayNotHasKey('gbp:reviews-'.$this->asset->id, $this->items());
        $this->assertContains('bad_review_unanswered', app(CommandCenter::class)->items()->pluck('rule')->all());
    }

    public function test_no_post_for_two_weeks_is_a_reminder_until_one_is_planned(): void
    {
        $this->snapshot();
        DB::table('gbp_posts')->insert(['external_resource_id' => $this->resource->id, 'run_id' => $this->runId, 'location_name' => 'locations/22', 'post_name' => 'p1',
            'summary' => 'Eski', 'create_time' => now()->subDays(20), 'raw_payload' => '{}', 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $item = $this->items()['gbp:posts-'.$this->asset->id];
        $this->assertSame('20 gündür gönderi yok', $item['title']);
        $this->assertSame('Profil gönderisi yok', $item['topic_label']);

        ContentCalendarItem::query()->create(['brand_id' => $this->asset->brand_id, 'digital_asset_id' => $this->asset->id, 'channel' => 'gbp_post', 'title' => 'Plan',
            'status' => 'approved', 'scheduled_for' => now()->addDays(2)]);
        app(CommandCenter::class)->refresh();
        $this->assertArrayNotHasKey('gbp:posts-'.$this->asset->id, $this->items());
    }

    public function test_reviews_google_refuses_become_an_item_with_the_turkish_reason(): void
    {
        Run::query()->create(['digital_asset_id' => $this->asset->id, 'module_id' => 'google-business-profile', 'status' => 'partial', 'started_at' => now(), 'finished_at' => now(),
            'metadata' => ['datasets' => ['gbp_reviews' => ['status' => 'unavailable', 'reason' => 'gbp_reviews provider request failed with HTTP 403. PERMISSION_DENIED']]]]);

        $item = $this->items()['gbp:reviews-access-'.$this->asset->id];
        $this->assertSame('Yorumlar toplanamıyor', $item['title']);
        $this->assertStringContainsString('API onayı gerekli', (string) $item['detail']);
    }

    public function test_profile_gaps_are_listed_only_when_the_advisor_has_no_open_item_for_them(): void
    {
        $this->snapshot();
        $item = $this->items()['gbp:profile-'.$this->asset->id];
        $this->assertStringStartsWith('Profil eksikleri (', $item['title']);
        $this->assertStringContainsString('tab=profile', (string) $item['url']);

        $plan = AdvisorPlan::query()->create(['channel' => 'google_business_profile', 'brand_id' => $this->asset->brand_id, 'customer_id' => $this->asset->brand->customer_id,
            'digital_asset_id' => $this->asset->id, 'status' => 'completed', 'completed_at' => now()]);
        AdvisorItem::query()->create(['channel' => 'google_business_profile', 'brand_id' => $this->asset->brand_id, 'digital_asset_id' => $this->asset->id, 'item_key' => 'gaps',
            'category' => 'profile', 'rule_id' => 'profile-gaps', 'severity' => 'medium', 'title' => 'Profil eksikleri (3)', 'status' => 'open',
            'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id]);
        app(CommandCenter::class)->refresh();
        $this->assertArrayNotHasKey('gbp:profile-'.$this->asset->id, $this->items());
    }

    public function test_passive_customer_profiles_are_left_out(): void
    {
        $this->review('late', 'FOUR', 72);
        $this->asset->brand->customer->forceFill(['status' => 'inactive'])->save();

        $this->assertSame([], $this->items());
    }
}
