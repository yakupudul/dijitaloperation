<?php

namespace Tests\Feature\ExternalWrites;

use App\Jobs\ExecuteExternalWriteJob;
use App\Models\CoreConnection;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Services\Integrations\WordPress\WordPressConnectorCommands;
use App\Services\Site\ContentPlanner;
use App\Services\Work\ContentAutopilot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Site\SiteTestCase;

/**
 * Avrupadent, 2026-10-14: the site fetches its work itself and was not asking. Its 18 approved articles and ~1000 site
 * fixes all ended as failed, yet the articles stood as "Taslak gönderildi" while WordPress had none of them. A write for
 * such a site now waits for it; a draft write that does fail returns its article to "send".
 */
final class PullSiteDraftWaitTest extends SiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        CoreConnection::factory()->create(['digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'plugin_version' => '1.16.0', 'rest_transport' => 'pull',
                'snapshot_url' => 'https://panorama.com.tr/wp-json/moxdop/v1/snapshot']]);
    }

    /** @param  array<string, mixed>  $action */
    private function idea(string $key = 'idea', array $action = []): Suggestion
    {
        return Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => md5($key), 'material_hash' => md5($key),
            'title' => 'İmplant sonrası ilk hafta', 'reason' => 'Kümenin sayfası yok.', 'priority' => 1, 'evidence' => [], 'action_type' => 'content',
            'action' => ['site_id' => $this->site->id, 'kind' => 'new', 'page_type' => 'blog', 'article' => ['title' => 'İmplant sonrası ilk hafta', 'html' => '<p>x</p>', 'language' => 'tr'],
                'translations' => ['en' => ['title' => 'The first week', 'html' => '<p>x</p>', 'language' => 'en']],
                'autopilot' => ['date' => now()->toDateString(), 'review' => ['verdict' => 'ok']], ...$action],
            'status' => Suggestion::APPROVED, 'target_type' => 'site', 'target_id' => $this->site->id, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function write(Suggestion $idea, array $attributes = []): ExternalWriteAction
    {
        return ExternalWriteAction::query()->create([...['channel' => ExternalWriteAction::CHANNEL_WORDPRESS, 'action' => ExternalWriteAction::ACTION_ARTICLE_DRAFTS,
            'digital_asset_id' => $this->site->id, 'status' => 'queued', 'requested_by' => $this->admin->id,
            'request_payload' => ['reference' => 'suggestion-'.$idea->id, 'languages' => ['tr', 'en'], 'drafts' => []]], ...$attributes]);
    }

    public function test_a_write_for_a_site_that_is_not_asking_waits_instead_of_failing(): void
    {
        $idea = $this->idea();
        $write = $this->write($idea);
        $idea->forceFill(['action' => [...$idea->action, 'article_write_id' => $write->id, 'sent_languages' => ['tr', 'en']]])->save();

        app()->call([new ExecuteExternalWriteJob($write->id), 'handle']);

        $write->refresh();
        $this->assertSame('queued', $write->status);
        $this->assertStringStartsWith('Site işi almayı bekliyor: site son 15 dakikada iş sormadı', (string) $write->error);
        $this->assertNull($write->started_at);
        Queue::assertPushed(ExecuteExternalWriteJob::class, fn (ExecuteExternalWriteJob $job): bool => $job->actionId === $write->id && $job->delay !== null);
        $this->assertSame($write->id, $idea->fresh()->action['article_write_id'], 'still on its way');
    }

    public function test_a_site_with_a_full_hand_of_waiting_work_gets_no_more_until_it_takes_some(): void
    {
        $connection = CoreConnection::query()->sole();
        DB::table('website_connector_delivery')->insert(['connection_id' => $connection->id, 'pull_seen_at' => now()]);
        foreach (range(1, 6) as $i) {
            DB::table('website_connector_commands')->insert(['connection_id' => $connection->id, 'method' => 'POST', 'route' => '/moxdop/v1/fixes', 'status' => 'pending', 'created_at' => now()]);
        }
        $write = $this->write($this->idea());

        app()->call([new ExecuteExternalWriteJob($write->id), 'handle']);

        $this->assertSame('queued', $write->refresh()->status);
        $this->assertStringContainsString('alınmayı bekleyen 6 iş var', (string) $write->error);
    }

    public function test_after_a_day_of_waiting_the_article_returns_to_send_with_the_reason(): void
    {
        $idea = $this->idea();
        $write = $this->write($idea, ['result' => ['waiting_since' => now()->subHours(25)->toIso8601String(), 'waits' => 140]]);
        $idea->forceFill(['action' => [...$idea->action, 'article_write_id' => $write->id, 'sent_languages' => ['tr', 'en']]])->save();
        $this->assertCount(0, app(ContentAutopilot::class)->ready());

        app()->call([new ExecuteExternalWriteJob($write->id), 'handle']);

        $this->assertSame('failed', $write->refresh()->status);
        $this->assertStringStartsWith('Gönderilmedi: site 24 saattir işleri almadı', (string) $write->error);
        $action = $idea->fresh()->action;
        $this->assertArrayNotHasKey('article_write_id', $action);
        $this->assertSame([], $action['sent_languages']);
        $this->assertSame($write->id, $action['send_failed']['write_id']);
        $this->assertSame([$idea->id], app(ContentAutopilot::class)->ready()->pluck('id')->all(), 'back on the Günlük gönderim strip');
    }

    public function test_a_translation_that_did_not_get_there_can_be_sent_again(): void
    {
        $idea = $this->idea();
        $write = $this->write($idea, ['status' => 'partial', 'finished_at' => now(), 'result' => ['status' => 'partial', 'posts' => [
            ['language' => 'tr', 'ok' => true, 'post_id' => 51], ['language' => 'en', 'ok' => false, 'error' => 'Polylang kapalı'],
        ]]]);
        $idea->forceFill(['action' => [...$idea->action, 'article_write_id' => $write->id, 'sent_languages' => ['tr', 'en']]])->save();

        app(ContentPlanner::class)->draftFailed($write);

        $action = $idea->fresh()->action;
        $this->assertSame($write->id, $action['article_write_id'], 'the source reached WordPress');
        $this->assertSame(['tr'], $action['sent_languages'], 'EN can be sent again');
        $this->assertSame('Polylang kapalı', $action['send_failed']['error']);
    }

    public function test_the_failed_writes_of_before_are_released_once(): void
    {
        $idea = $this->idea();
        $write = $this->write($idea, ['status' => 'failed', 'finished_at' => now()->subDays(5), 'error' => 'Gönderilmedi: site son 30 dakikada 3 yazmaya yanıt vermedi.']);
        $idea->forceFill(['action' => [...$idea->action, 'article_write_id' => $write->id, 'sent_languages' => ['tr', 'en']]])->save();
        $sent = $this->idea('sent');
        $ok = $this->write($sent, ['status' => 'succeeded', 'result' => ['posts' => [['language' => 'tr', 'ok' => true, 'post_id' => 9]]]]);
        $sent->forceFill(['action' => [...$sent->action, 'article_write_id' => $ok->id, 'sent_languages' => ['tr']]])->save();

        $this->artisan('moxdop:content:release-failed-drafts')->expectsOutput('1 yazı yeniden gönderilebilir.')->assertSuccessful();

        $this->assertArrayNotHasKey('article_write_id', $idea->fresh()->action);
        $this->assertStringContainsString('yanıt vermedi', $idea->fresh()->action['send_failed']['error']);
        $this->assertSame($ok->id, $sent->fresh()->action['article_write_id'], 'an article that reached WordPress stays sent');
    }

    public function test_a_site_that_asks_gets_its_waiting_writes_at_once_and_learns_more_is_on_its_way(): void
    {
        $write = $this->write($this->idea());
        app()->call([new ExecuteExternalWriteJob($write->id), 'handle']); // not asking: waits with a delayed try
        $firstTry = (string) $write->refresh()->result['wait_id'];
        $connection = CoreConnection::query()->sole();

        app(WordPressConnectorCommands::class)->exchange($connection, 'client', 'secret', [], true, []);

        $woken = (string) $write->refresh()->result['wait_id'];
        $this->assertNotSame($firstTry, $woken);
        Queue::assertPushed(ExecuteExternalWriteJob::class, fn (ExecuteExternalWriteJob $job): bool => $job->waitId === $woken && $job->delay === null);
        $this->assertSame(1, WordPressConnectorCommands::waiting($connection), 'the plugin keeps asking while this is above zero');
        $this->assertSame(['https://panorama.com.tr/wp-admin/admin-ajax.php?action=moxdop_tick'], WordPressConnectorCommands::wakeUrls(),
            'MoxDOP screens ping the site while its work waits');

        app()->call([new ExecuteExternalWriteJob($write->id, false, $firstTry), 'handle']);
        $this->assertSame('queued', $write->refresh()->status, 'the earlier delayed try does nothing');
        $this->assertSame($woken, (string) $write->result['wait_id']);
    }
}
