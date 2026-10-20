<?php

namespace Tests\Feature\Website;

use App\Jobs\ExecuteExternalWriteJob;
use App\Jobs\Site\RunSiteOperationJob;
use App\Models\AiTask;
use App\Models\ContentSiteState;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\User;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Site\BrandlessSites;
use App\Services\Site\SiteOperations;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * yakup, 2026-10-20: "Markaya bağlı olmayan hiçbir şey için herhangi bir şey yapılmasın". A website without a brand is
 * only built; no site operation, SEO / content write or IndexNow runs for it until it is given a brand.
 */
final class BrandlessSitesTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $brandless;

    private DigitalAsset $serviced;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brandless = DigitalAsset::factory()->create(['type' => 'website', 'brand_id' => null, 'domain' => 'demo.example.com', 'primary_url' => 'https://demo.example.com/']);
        $this->serviced = DigitalAsset::factory()->create(['type' => 'website', 'domain' => 'brand.example.com', 'primary_url' => 'https://brand.example.com/']);
    }

    public function test_only_websites_without_a_brand_are_left_alone(): void
    {
        $this->assertFalse(BrandlessSites::serviced($this->brandless));
        $this->assertFalse(BrandlessSites::serviced((int) $this->brandless->id));
        $this->assertTrue(BrandlessSites::serviced($this->serviced));
        $this->assertTrue(BrandlessSites::serviced((int) $this->serviced->id));
        $this->assertFalse(BrandlessSites::serviced(999999));
    }

    public function test_site_operations_are_not_queued_for_a_brandless_site(): void
    {
        Queue::fake();

        SiteOperations::dispatch((int) $this->brandless->id, SiteOperations::WEEKLY_CONTENT);
        SiteOperations::dispatch((int) $this->brandless->id, SiteOperations::CONTENT_AUTOPILOT);
        $this->assertSame(0, SiteOperations::dispatchUrlAnalysis((int) $this->brandless->id, [1, 2, 3]));

        Queue::assertNotPushed(RunSiteOperationJob::class);
        $status = SiteOperations::status((int) $this->brandless->id, SiteOperations::WEEKLY_CONTENT);
        $this->assertSame(BrandlessSites::SKIPPED, $status['status']);
        $this->assertSame('markaya bağlı değil: otomatik iş yapılmaz', SiteOperations::line($status));

        SiteOperations::dispatch((int) $this->serviced->id, SiteOperations::WEEKLY_CONTENT);
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->siteId === (int) $this->serviced->id);
    }

    public function test_an_operation_queued_before_the_rule_does_nothing_when_it_runs(): void
    {
        (new RunSiteOperationJob((int) $this->brandless->id, SiteOperations::WEEKLY_CONTENT))->handle(app(SiteOperations::class));

        $this->assertSame(BrandlessSites::SKIPPED, SiteOperations::status((int) $this->brandless->id, SiteOperations::WEEKLY_CONTENT)['status']);
        $this->assertSame(0, AiTask::query()->count());
        $this->assertSame(BrandlessSites::SKIPPED, app(SiteOperations::class)->run($this->brandless, SiteOperations::CATEGORIZE)['status']);
    }

    public function test_no_seo_or_content_write_is_requested_for_a_brandless_site(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        Queue::fake();

        try {
            app(ExternalWriteService::class)->requestSiteFixes($admin, $this->brandless, [['type' => 'seo_title', 'object_id' => 5, 'reference' => 'r', 'value' => 'Yeni']]);
            $this->fail('A brandless site must not get a site fix.');
        } catch (ValidationException $exception) {
            $this->assertSame(BrandlessSites::REASON, $exception->errors()['write'][0]);
        }

        $this->assertSame(0, ExternalWriteAction::query()->count());
        Queue::assertNotPushed(ExecuteExternalWriteJob::class);
    }

    public function test_a_queued_write_for_a_brandless_site_is_not_sent_but_the_build_is(): void
    {
        $fix = $this->write($this->brandless, ExternalWriteAction::ACTION_SITE_FIX);
        app(ExternalWriteService::class)->execute($fix);

        $fix->refresh();
        $this->assertSame('failed', $fix->status);
        $this->assertStringContainsString('markaya bağlı değil', (string) $fix->error);

        $this->assertTrue(BrandlessSites::writeAllowed($this->write($this->brandless, ExternalWriteAction::ACTION_SITE_BUILD), $this->brandless));
        $this->assertTrue(BrandlessSites::writeAllowed($this->write($this->brandless, ExternalWriteAction::ACTION_CONNECTOR_UPDATE), $this->brandless));
        $this->assertTrue(BrandlessSites::writeAllowed($this->write($this->serviced, ExternalWriteAction::ACTION_SITE_FIX), $this->serviced));
        $this->assertFalse(BrandlessSites::writeAllowed($this->write($this->brandless, ExternalWriteAction::ACTION_ARTICLE_DRAFTS), $this->brandless));
    }

    public function test_waiting_work_of_brandless_sites_stops_once(): void
    {
        $article = $this->write($this->brandless, ExternalWriteAction::ACTION_ARTICLE_DRAFTS);
        $scheduled = $this->write($this->brandless, ExternalWriteAction::ACTION_POST_DATE, 'scheduled');
        $build = $this->write($this->brandless, ExternalWriteAction::ACTION_SITE_BUILD);
        $other = $this->write($this->serviced, ExternalWriteAction::ACTION_SITE_FIX);
        ContentSiteState::for($this->brandless)->fill(['backfill' => ContentSiteState::BACKFILL_ELIGIBLE])->save();
        ContentSiteState::for($this->serviced)->fill(['backfill' => ContentSiteState::BACKFILL_ELIGIBLE])->save();
        $task = $this->task(new RunSiteOperationJob((int) $this->brandless->id, SiteOperations::WEEKLY_CONTENT));
        $keep = $this->task(new RunSiteOperationJob((int) $this->serviced->id, SiteOperations::WEEKLY_CONTENT));

        $this->artisan('moxdop:sites:stop-brandless')
            ->expectsOutput('Markasız sitelerde 2 bekleyen yazma iptal edildi, 1 geriye dönük doldurma kapandı, 1 AI işi kapandı.')
            ->assertSuccessful();

        $this->assertSame(['failed', 'failed', 'queued', 'queued'], [$article->fresh()->status, $scheduled->fresh()->status, $build->fresh()->status, $other->fresh()->status]);
        $this->assertSame(ContentSiteState::BACKFILL_OFF, ContentSiteState::for($this->brandless)->backfill);
        $this->assertSame(ContentSiteState::BACKFILL_ELIGIBLE, ContentSiteState::for($this->serviced)->backfill);
        $this->assertSame(AiTask::CONSUMED, $task->fresh()->status);
        $this->assertSame(AiTask::PENDING, $keep->fresh()->status);

        $this->artisan('moxdop:sites:stop-brandless')
            ->expectsOutput('Markasız sitelerde 0 bekleyen yazma iptal edildi, 0 geriye dönük doldurma kapandı, 0 AI işi kapandı.');
    }

    public function test_the_connector_turns_index_now_off_when_moxdop_says_the_site_has_no_brand(): void
    {
        $root = base_path('connectors/wordpress/moxdop-connector/includes/');
        $indexNow = (string) file_get_contents($root.'class-moxdop-connector-indexnow.php');
        $commands = (string) file_get_contents($root.'class-moxdop-connector-commands.php');

        $this->assertStringContainsString("get_option(self::SERVICED_OPTION, '1') === '1'", $indexNow);
        $this->assertStringContainsString("array_key_exists('serviced', \$data)", $commands);
    }

    private function write(DigitalAsset $site, string $action, string $status = 'queued'): ExternalWriteAction
    {
        return ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_WORDPRESS, 'action' => $action, 'digital_asset_id' => $site->id, 'brand_id' => $site->brand_id,
            'status' => $status, 'request_payload' => [], 'requested_by' => User::factory()->create()->id,
        ]);
    }

    private function task(RunSiteOperationJob $job): AiTask
    {
        return AiTask::query()->create([
            'operation' => 'site.weekly_content', 'brand_id' => null, 'subject' => 'site', 'resume_key' => hash('sha256', (string) $job->siteId), 'sequence' => 1,
            'input_hash' => hash('sha256', (string) $job->siteId), 'status' => AiTask::PENDING, 'instructions' => 'x', 'input' => 'x', 'output_schema' => [],
            'resume' => serialize($job),
        ]);
    }
}
