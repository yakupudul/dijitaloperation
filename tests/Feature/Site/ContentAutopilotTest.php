<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\ContentReviewAgent;
use App\Ai\Agents\Site\RepairReviewAgent;
use App\Jobs\ApproveRepairRowsJob;
use App\Jobs\ReviewRepairRowsJob;
use App\Jobs\SendDailyBatchJob;
use App\Jobs\Site\RunSiteOperationJob;
use App\Jobs\Site\TopUpContentPoolJob;
use App\Livewire\Operator\Repair\DailySendStrip;
use App\Models\AiTask;
use App\Models\ContentSiteState;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpQueuedPost;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Gbp\GbpPostReview;
use App\Services\Repair\RepairAutopilot;
use App\Services\Repair\RepairDesk;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteSuggestionTypes;
use App\Services\Work\ContentAutopilot;
use App\Services\Work\DailySend;
use App\Support\Ai\AiRouteKeys;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Günde 1 tık (yakup, 2026-10-14): the content otopilot dates every site's articles one every two days from its last
 * post (last 60 days at most), writes and AI-checks them; the next Business Profile posts and the desk fixes are AI
 * checked too. Nothing goes out by itself: one Admin click sends only what passed, off the web request.
 */
final class ContentAutopilotTest extends SiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'Europe/Istanbul'));
        $this->enableAi();
        // The back-dated fill runs only where it was kept on 2026-10-16 (ContentCycleTest covers the scoping).
        ContentSiteState::for($this->site)->forceFill(['backfill' => ContentSiteState::BACKFILL_ELIGIBLE])->save();
    }

    private function lastPost(string $date): void
    {
        DB::table('website_cms_object_snapshot')->insert(['digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'object_id' => (string) random_int(1, 99999), 'object_type' => 'post',
            'status' => 'publish', 'published_at' => $date.' 08:00:00', 'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', $date.random_int(1, PHP_INT_MAX)), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param  array<string, mixed>  $action */
    private function idea(string $title, array $action = [], string $status = Suggestion::OPEN): Suggestion
    {
        return Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => hash('sha256', $title),
            'material_hash' => hash('sha256', 'x'), 'title' => $title, 'reason' => 'Sorgu 120 gösterim aldı.', 'priority' => 2, 'action_type' => SiteSuggestionTypes::CONTENT,
            'status' => $status, 'action' => array_merge(['site_id' => $this->site->id, 'kind' => 'new'], $action), 'evidence' => [],
        ]);
    }

    /** @return array<string, mixed> */
    private static function article(string $title): array
    {
        return ['title' => $title, 'html' => '<h2>'.$title.'</h2><p>Tedavi süreci muayene ile başlar.</p>', 'language' => 'tr', 'reference' => 'suggestion-'.md5($title)];
    }

    public function test_dates_start_two_days_after_the_last_post_and_never_go_back_more_than_sixty_days(): void
    {
        $autopilot = app(ContentAutopilot::class);
        $this->assertSame('2026-08-15', $autopilot->openDates($this->site)[0], 'no post known: the last 60 days');
        $this->assertSame('2026-10-14', last($autopilot->openDates($this->site)), 'every second day up to today at most');

        $this->lastPost('2026-07-01');
        $this->assertSame('2026-08-15', $autopilot->openDates($this->site)[0], 'an old last post does not reach back further');

        $this->lastPost('2026-10-06');
        $this->assertSame(['2026-10-08', '2026-10-10', '2026-10-12', '2026-10-14'], $autopilot->openDates($this->site));

        $this->lastPost('2026-10-14');
        $this->assertSame([], $autopilot->openDates($this->site), 'posted today: the next date is two days later');
    }

    public function test_plan_dates_new_ideas_written_first_and_starts_the_writing(): void
    {
        Queue::fake();
        $this->lastPost('2026-10-08');
        $written = $this->idea('İmplant sonrası beslenme', ['article' => self::article('İmplant sonrası beslenme')], Suggestion::APPROVED);
        $open = $this->idea('Zirkonyum kaplama ne kadar dayanır');
        $update = $this->idea('İmplant sayfasını güçlendir', ['kind' => 'update']);
        $blocked = $this->idea('Garantili implant', ['article_blocked' => 'Sektör kuralı']);
        $approvedEmpty = $this->idea('Onaylı ama yazılmamış', [], Suggestion::APPROVED);

        $result = app(ContentAutopilot::class)->plan($this->site, $this->admin);

        $this->assertSame(['planned' => 2, 'open_dates' => 3], $result, '10-10, 10-12, 10-14 open; only two ideas can take a date');
        $this->assertSame('2026-10-10', $written->fresh()->action['autopilot']['date'], 'the written article takes the first date');
        $this->assertSame('2026-10-12', $open->fresh()->action['autopilot']['date']);
        $this->assertSame(Suggestion::APPROVED, $open->fresh()->status, 'the otopilot approves the title');
        foreach ([$update, $blocked, $approvedEmpty] as $idea) {
            $this->assertArrayNotHasKey('autopilot', $idea->fresh()->action);
        }
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::WRITE_ARTICLE && $job->params === ['suggestion_id' => $open->id]);
        Queue::assertNotPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => ($job->params['suggestion_id'] ?? null) === $written->id);
        Queue::assertPushed(TopUpContentPoolJob::class);

        $this->assertSame(['2026-10-14'], app(ContentAutopilot::class)->openDates($this->site), 'the next run continues from the last planned date');
    }

    public function test_review_passes_only_ok_articles_and_the_send_dates_the_drafts(): void
    {
        $passed = $this->idea('İmplant sonrası beslenme', ['article' => self::article('İmplant sonrası beslenme'), 'autopilot' => ['date' => '2026-10-12']], Suggestion::APPROVED);
        $wrong = $this->idea('Zirkonyum ömrü', ['article' => self::article('Zirkonyum ömrü'), 'autopilot' => ['date' => '2026-10-14']], Suggestion::APPROVED);
        $warned = $this->idea('Uyarılı yazı', ['article' => self::article('Uyarılı yazı'), 'article_warnings' => 'Yasaklı ifade: garanti', 'autopilot' => ['date' => '2026-10-10']], Suggestion::APPROVED);
        $future = $this->idea('Yarının yazısı', ['article' => self::article('Yarının yazısı'), 'autopilot' => ['date' => '2026-10-15']], Suggestion::APPROVED);
        $prompts = [];
        ContentReviewAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = $prompt;

            return ['items' => [['id' => 0, 'verdict' => str_contains($prompt, 'Zirkonyum') ? 'wrong' : 'ok', 'reason' => str_contains($prompt, 'Zirkonyum') ? 'Kaynakta olmayan süre veriyor.' : 'Uygun.']]];
        });

        $result = app(ContentAutopilot::class)->review($this->site);

        $this->assertSame('ready', $result['status']);
        $this->assertCount(3, $prompts, 'a sector warning never goes to AI; it is held at once');
        $this->assertSame('ok', $passed->fresh()->action['autopilot']['review']['verdict']);
        $this->assertSame('wrong', $wrong->fresh()->action['autopilot']['review']['verdict']);
        $this->assertSame('wrong', $warned->fresh()->action['autopilot']['review']['verdict']);
        $autopilot = app(ContentAutopilot::class);
        $this->assertSame([$passed->id], $autopilot->ready()->pluck('id')->all(), 'a future date waits for its day');
        $this->assertEqualsCanonicalizing([$wrong->id, $warned->id], $autopilot->held()->pluck('id')->all());
        $this->assertSame('ok', $future->fresh()->action['autopilot']['review']['verdict']);

        Queue::fake();
        $this->assertSame(['sent' => 0, 'failed' => []], $autopilot->sendReady($this->admin), 'no connector: the article is not tried');
        $summary = app(DailySend::class)->summary();
        $this->assertSame([0, [['site' => (string) $this->site->name, 'count' => 1]]], [$summary['content'], $summary['no_connector']], 'the strip says it once per site');
        Livewire::actingAs($this->admin)->test(DailySendStrip::class)->assertSeeHtml('data-daily-no-connector')->assertSee($this->site->name.' 1 yazı');
        CoreConnection::factory()->create(['digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://panorama.com.tr/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.5.0']]);
        Cache::put(DailySend::LAST_KEY, ['at' => now()->subDays(5)->toIso8601String(), 'content' => ['sent' => 0, 'failed' => ['A: Site yanıt vermedi.', 'B: Site yanıt vermedi.']], 'posts' => 0, 'repair' => ['applied' => 0, 'failed' => []]]);
        Livewire::actingAs($this->admin)->test(DailySendStrip::class)->assertSee('1 iş gönderilmeye hazır')->assertSee('Son gönderim 5 gün önce')
            ->assertSee('2 yazı: Site yanıt vermedi')->assertDontSeeHtml('data-daily-no-connector');
        $this->assertSame(['sent' => 1, 'failed' => []], $autopilot->sendReady($this->admin));
        $write = ExternalWriteAction::query()->sole();
        $this->assertSame(ExternalWriteAction::ACTION_ARTICLE_DRAFTS, $write->action);
        $draft = (array) $write->request_payload['drafts'][0]['draft'];
        $this->assertSame('2026-10-12 07:00:00', CarbonImmutable::parse((string) $draft['post_date'])->utc()->toDateTimeString(), 'dated to its plan day, 10:00 Istanbul');
        $this->assertFalse((bool) ($draft['schedule'] ?? false), 'a past date is not scheduled');
        $this->assertSame([], $autopilot->ready()->all(), 'a sent article is not sent again');
    }

    public function test_business_profile_posts_are_checked_and_only_passed_ones_are_approved(): void
    {
        $location = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'name' => 'Panorama Çankaya']);
        $post = fn (string $day, string $text): GbpQueuedPost => GbpQueuedPost::query()->create(['digital_asset_id' => $location->id, 'brand_id' => $this->brand->id,
            'angle' => 'what', 'publish_on' => $day, 'summary' => $text, 'status' => GbpQueuedPost::DRAFT]);
        $good = $post('2026-10-15', 'Çankaya şubemizde implant tedavisi muayene ile başlar.');
        $bad = $post('2026-10-16', 'Garantili implant için hemen arayın.');
        $later = $post('2026-10-25', 'Daha sonraki gönderi.');
        ContentReviewAgent::fake([['items' => [['id' => $good->id, 'verdict' => 'ok', 'reason' => 'Uygun.'], ['id' => $bad->id, 'verdict' => 'wrong', 'reason' => 'Garanti vaadi var.']]]]);

        $this->assertSame([$this->brand->id], GbpPostReview::brandsToCheck());
        $this->assertSame(['checked' => 2], app(GbpPostReview::class)->review($this->brand->id));
        $this->assertNull($later->fresh()->review, 'only the next days are checked');

        $this->assertSame(1, app(GbpPostReview::class)->approveReady($this->admin));
        $this->assertSame(GbpQueuedPost::APPROVED, $good->fresh()->status);
        $this->assertSame(GbpQueuedPost::DRAFT, $bad->fresh()->status);
        $this->assertSame('Garanti vaadi var.', app(GbpPostReview::class)->held()->sole()->review['reason']);
    }

    public function test_repair_check_skips_ads_and_seo_fields_without_traffic_and_the_click_queues_passed_rows(): void
    {
        Queue::fake();
        $location = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'name' => 'Panorama Çankaya']);
        $row = fn (string $title, string $proposed): Suggestion => Suggestion::query()->create(['brand_id' => $this->brand->id, 'channel' => 'gbp', 'decision_key' => 'gbp.description',
            'fingerprint' => hash('sha256', $title), 'material_hash' => hash('sha256', 'x'), 'title' => $title, 'reason' => 'Açıklama boş.', 'priority' => 2, 'status' => Suggestion::OPEN,
            'evidence' => [], 'action_type' => 'gbp_description', 'target_id' => $location->id, 'action' => ['current' => '', 'proposed' => $proposed]]);
        $good = $row('Açıklama ekle', 'Panorama Çankaya, Ankara Çankaya’da implant ve zirkonyum kaplama tedavileri sunan bir diş kliniğidir.');
        $bad = $row('Açıklama ekle 2', 'Türkiye’nin en iyi kliniği.');

        $this->assertFalse(RepairAutopilot::eligible(['kind' => RepairDesk::ADS_NEGATIVE, 'risk' => RepairDesk::LOW]), 'Google Ads never');
        $this->assertFalse(RepairAutopilot::eligible(['kind' => RepairDesk::REDIRECT, 'risk' => RepairDesk::MEDIUM]), '301s never');
        $this->assertFalse(RepairAutopilot::eligible(['kind' => RepairDesk::SITE_FIELDS, 'risk' => RepairDesk::LOW, 'editable' => ['seo_title'], 'impressions' => 0]), 'title only where Google shows the page');
        $this->assertTrue(RepairAutopilot::eligible(['kind' => RepairDesk::SITE_FIELDS, 'risk' => RepairDesk::LOW, 'editable' => ['seo_title'], 'impressions' => 40]));
        $this->assertFalse(RepairAutopilot::eligible(['kind' => RepairDesk::SITE_FIELDS, 'risk' => RepairDesk::LOW, 'editable' => ['meta_description'], 'impressions' => 40, 'clicks' => 0]), 'description only where the page brings clicks');

        RepairReviewAgent::fake([['items' => [['id' => $good->id, 'verdict' => 'ok', 'reason' => 'Uygun.'], ['id' => $bad->id, 'verdict' => 'wrong', 'reason' => 'Üstünlük iddiası.']]]]);
        $autopilot = app(RepairAutopilot::class);
        $this->assertSame(['checked' => 2, 'waiting' => 0], $autopilot->review());
        $this->assertSame(['checked' => 0, 'waiting' => 0], $autopilot->review(), 'a checked proposal is not read again');
        $this->assertSame([$good->id], $autopilot->checked('ok')->pluck('id')->all());

        $this->assertSame(1, $autopilot->approveReady($this->admin)['applied']);
        Queue::assertPushed(ApproveRepairRowsJob::class, fn (ApproveRepairRowsJob $job): bool => $job->ids === [$good->id]);

        // A changed proposal needs a new check.
        $bad->forceFill(['action' => array_merge($bad->action, ['proposed' => 'Panorama Çankaya diş kliniğidir.'])])->save();
        $this->assertSame([], $autopilot->checked('held')->pluck('id')->all());
    }

    public function test_when_claude_takes_all_the_nightly_repair_check_waits_for_claude_and_stores_its_verdicts(): void
    {
        config(['moxdop-mcp.token' => 'test-mcp-token', 'moxdop-mcp.takes_all' => true]);
        $location = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'name' => 'Panorama Çankaya']);
        $row = fn (string $title, string $proposed): Suggestion => Suggestion::query()->create(['brand_id' => $this->brand->id, 'channel' => 'gbp', 'decision_key' => 'gbp.description',
            'fingerprint' => hash('sha256', $title), 'material_hash' => hash('sha256', 'x'), 'title' => $title, 'reason' => 'Açıklama boş.', 'priority' => 2, 'status' => Suggestion::OPEN,
            'evidence' => [], 'action_type' => 'gbp_description', 'target_id' => $location->id, 'action' => ['current' => '', 'proposed' => $proposed]]);
        $good = $row('Açıklama ekle', 'Panorama Çankaya, Ankara Çankaya’da implant ve zirkonyum kaplama tedavileri sunan bir diş kliniğidir.');
        $bad = $row('Açıklama ekle 2', 'Türkiye’nin en iyi kliniği.');
        RepairReviewAgent::fake()->preventStrayPrompts();

        (new ReviewRepairRowsJob)->handle(app(RepairAutopilot::class), app(AiTaskQueue::class));

        $task = AiTask::query()->sole();
        $this->assertSame(AiRouteKeys::REPAIR_REVIEW, $task->operation);
        $this->assertNull(data_get($good->fresh()->action, RepairAutopilot::KEY), 'unchecked while Claude works');
        $this->assertSame(['checked' => 0, 'waiting' => 0], app(RepairAutopilot::class)->review(), 'outside the job no provider is used and nothing is checked');
        RepairReviewAgent::assertNeverPrompted();

        $this->assertSame([], app(AiTaskQueue::class)->submit($task, ['items' => [['id' => $good->id, 'verdict' => 'ok', 'reason' => 'Uygun.'],
            ['id' => $bad->id, 'verdict' => 'wrong', 'reason' => 'Üstünlük iddiası.']]]));

        $this->assertSame([$good->id], app(RepairAutopilot::class)->checked('ok')->pluck('id')->all());
        $this->assertSame('Üstünlük iddiası.', app(RepairAutopilot::class)->checked('held')->sole()['review']['reason']);
        $this->assertSame(1, AiTask::query()->count());
        RepairReviewAgent::assertNeverPrompted();
    }

    public function test_the_strip_shows_what_passed_and_only_the_admin_click_queues_the_send(): void
    {
        Queue::fake();
        $this->idea('İmplant sonrası beslenme', ['article' => self::article('İmplant sonrası beslenme'), 'autopilot' => ['date' => '2026-10-12', 'review' => ['verdict' => 'ok', 'reason' => 'Uygun.']]], Suggestion::APPROVED);
        $this->idea('Zirkonyum ömrü', ['article' => self::article('Zirkonyum ömrü'), 'autopilot' => ['date' => '2026-10-12', 'review' => ['verdict' => 'wrong', 'reason' => 'Kaynakta olmayan süre.']]], Suggestion::APPROVED);

        Livewire::test(DailySendStrip::class)->assertSee('1 yazı')->assertSee('1 iş kontrolde kaldı')
            ->set('showHeld', true)->assertSee('Kaynakta olmayan süre.')
            ->call('send')->assertSee('Gönderim başladı');
        Queue::assertPushed(SendDailyBatchJob::class, fn (SendDailyBatchJob $job): bool => $job->userId === $this->admin->id);

        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($operator);
        Cache::forget(DailySend::RUNNING_KEY);
        Livewire::test(DailySendStrip::class)->assertDontSeeHtml('data-daily-send-button')->call('send')->assertForbidden();
    }
}
