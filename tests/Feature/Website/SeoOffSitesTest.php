<?php

namespace Tests\Feature\Website;

use App\Jobs\Site\RunSiteOperationJob;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Site\BrandlessSites;
use App\Services\Site\SiteOperations;
use App\Services\Work\ContentAutopilot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * yakup, 2026-11-20: hesap.moximu.com (the store of moximu.com) gets no articles, randevu.drserapkorkmaz.com (Google Ads
 * landing pages) gets no SEO. A website with seo_off keeps its brand, but MoxDOP produces no SEO / content work for it.
 */
final class SeoOffSitesTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $off;

    private DigitalAsset $on;

    protected function setUp(): void
    {
        parent::setUp();
        $this->on = DigitalAsset::factory()->create(['type' => 'website', 'domain' => 'brand.example.com', 'primary_url' => 'https://brand.example.com/']);
        $this->off = DigitalAsset::factory()->create(['type' => 'website', 'brand_id' => $this->on->brand_id, 'domain' => 'shop.example.com', 'primary_url' => 'https://shop.example.com/']);
        $this->off->forceFill(['seo_off' => true])->save();
    }

    public function test_a_site_with_seo_off_keeps_its_brand_but_is_not_serviced(): void
    {
        $this->assertTrue(BrandlessSites::seoOff($this->off));
        $this->assertFalse(BrandlessSites::serviced($this->off));
        $this->assertFalse(BrandlessSites::serviced((int) $this->off->id));
        $this->assertTrue(BrandlessSites::branded($this->off), 'the build tools still work');
        $this->assertTrue(BrandlessSites::serviced($this->on));
        $this->assertSame(BrandlessSites::SEO_OFF_REASON, BrandlessSites::reason($this->off));
        $this->assertNotContains((int) $this->off->id, ContentAutopilot::sites()->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_no_site_operation_runs_and_no_content_write_is_sent(): void
    {
        Queue::fake();
        SiteOperations::dispatch((int) $this->off->id, SiteOperations::WEEKLY_CONTENT);
        Queue::assertNotPushed(RunSiteOperationJob::class);
        $status = SiteOperations::status((int) $this->off->id, SiteOperations::WEEKLY_CONTENT);
        $this->assertSame('içerik ve SEO kapalı: otomatik iş yapılmaz', SiteOperations::line($status));

        $article = $this->write(ExternalWriteAction::ACTION_ARTICLE_DRAFTS);
        app(ExternalWriteService::class)->execute($article);
        $this->assertSame('failed', $article->fresh()->status);
        $this->assertStringContainsString('içerik ve SEO kapalı', (string) $article->fresh()->error);
        $this->assertTrue(BrandlessSites::writeAllowed($this->write(ExternalWriteAction::ACTION_SITE_BUILD), $this->off));
    }

    public function test_search_rows_of_the_site_close_as_they_are_saved_and_others_stay(): void
    {
        $content = $this->suggestion('search', 'site', (int) $this->off->id);
        $other = $this->suggestion('search', 'site', (int) $this->on->id);
        $ads = $this->suggestion('google_ads', 'site', (int) $this->off->id);

        $this->assertSame(Suggestion::DISMISSED, $content->fresh()->status);
        $this->assertSame(BrandlessSites::SEO_OFF_REASON, data_get($content->fresh()->action, 'auto_closed'));
        $this->assertSame(Suggestion::OPEN, $other->fresh()->status);
        $this->assertSame(Suggestion::OPEN, $ads->fresh()->status);
    }

    public function test_waiting_work_stops_once(): void
    {
        $this->off->forceFill(['seo_off' => false])->save();
        $row = $this->suggestion('search', 'site', (int) $this->off->id);
        $fix = $this->write(ExternalWriteAction::ACTION_SITE_FIX);
        $this->assertSame(Suggestion::OPEN, $row->fresh()->status);
        $this->off->forceFill(['seo_off' => true])->save();

        $out = BrandlessSites::stopPending();

        $this->assertSame(1, $out['suggestions']);
        $this->assertSame(1, $out['writes']);
        $this->assertSame(Suggestion::DISMISSED, $row->fresh()->status);
        $this->assertSame('failed', $fix->fresh()->status);
    }

    private function suggestion(string $channel, string $targetType, int $targetId): Suggestion
    {
        return Suggestion::query()->create(['brand_id' => $this->on->brand_id, 'channel' => $channel, 'decision_key' => 'content.x', 'fingerprint' => Str::random(20),
            'material_hash' => 'x', 'title' => 'Yazı', 'reason' => 'r', 'priority' => 1, 'action_type' => 'content', 'target_type' => $targetType,
            'target_id' => $targetId, 'status' => Suggestion::OPEN, 'action' => [], 'evidence' => []]);
    }

    private function write(string $action): ExternalWriteAction
    {
        return ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_WORDPRESS, 'action' => $action, 'digital_asset_id' => $this->off->id, 'brand_id' => $this->off->brand_id,
            'status' => 'queued', 'request_payload' => [], 'requested_by' => User::factory()->create()->id,
        ]);
    }
}
