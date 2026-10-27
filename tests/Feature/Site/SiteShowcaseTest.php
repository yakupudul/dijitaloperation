<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\SiteQaAnswerAgent;
use App\Jobs\Site\AnswerSiteQuestionJob;
use App\Jobs\Site\PlanSiteQuestionsJob;
use App\Jobs\Site\SyncSiteShowcaseJob;
use App\Livewire\Operator\Portfolio\BrandSettings;
use App\Models\CoreAssetBinding;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\SiteQuestion;
use App\Models\Suggestion;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\Repair\RepairAutopilot;
use App\Services\Repair\RepairDesk;
use App\Services\Site\Showcase\SiteQuestions;
use App\Services\Site\Showcase\SiteShowcase;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use MoxDop\Website\Discovery\PublicUrlSafety;

/**
 * Sorular ve yorumlar (yakup, 2026-10-27): the brand switch sends the Google reviews and the approved questions of the
 * assigned pages to the brand's WordPress sites; a visitor's question is answered by AI and waits on the Onarım masası.
 */
final class SiteShowcaseTest extends SiteTestCase
{
    private const string SECRET = 'showcase-test-secret-0123456789abcdefghijk';

    private const string INSTALLATION = '0b7f2a1e-4c11-4e3c-9d55-7f0f4b0b2c10';

    private CoreConnection $connection;

    /** @var array{0: int, 1: int}|null the Business Profile asset and its Google resource */
    private ?array $location = null;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->connection = CoreConnection::factory()->create([
            'digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://panorama.com.tr/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.28.0', 'installation_id' => self::INSTALLATION],
        ]);
        CoreConnectionCredential::factory()->create(['connection_id' => $this->connection->id, 'encrypted_payload' => ['client_id' => 'client-1', 'shared_secret' => self::SECRET]]);
    }

    public function test_a_question_asked_on_the_site_is_stored_without_personal_data_only_while_the_brand_switch_is_on(): void
    {
        $this->send([$this->asked(31, 'İmplant ağrılı mı?')])->assertOk();
        $this->assertSame(0, SiteQuestion::query()->count(), 'switch off: nothing is stored');

        $this->brand->forceFill(['showcase_enabled' => true])->save();
        $this->send([$this->asked(32, 'İmplant ağrılı mı?'), $this->asked(32, 'İmplant ağrılı mı?')])->assertOk();

        $question = SiteQuestion::query()->sole();
        $this->assertSame(['post:42', 'site', 'new', 32], [$question->target, $question->source, $question->status, $question->local_id]);
        $this->assertSame('İmplant ağrılı mı?', $question->question);
        Queue::assertPushed(AnswerSiteQuestionJob::class, 1);

        $this->site->forceFill(['brand_id' => null])->save();
        $this->send([$this->asked(33, 'Fiyat?')])->assertOk();
        $this->assertSame(1, SiteQuestion::query()->count(), 'a brandless site gets no automatic work');
    }

    public function test_the_answer_waits_on_the_desk_can_be_edited_and_is_sent_to_the_site_once_approved(): void
    {
        $this->enableAi();
        $this->brand->forceFill(['showcase_enabled' => true])->save();
        $this->page('/implant/', 'Ankara İmplant Tedavisi', ['wp_post_id' => 42]);
        SiteQaAnswerAgent::fake([['verdict' => 'publish', 'question' => 'İmplant tedavisi ağrılı mı?',
            'answer' => 'İşlem lokal anestezi altında yapıldığı için genellikle ağrı hissedilmez. Sonrasındaki hassasiyet birkaç gün sürer.', 'link_url' => 'https://evil.example/']]);
        $question = $this->question();

        app(SiteQuestions::class)->answer((int) $question->id);

        $question->refresh();
        $this->assertSame(SiteQuestion::READY, $question->status);
        $this->assertNull($question->link_url, 'a link that is not one of the site\'s own articles is dropped');
        $desk = app(RepairDesk::class);
        $row = $desk->rows()->firstWhere('id', $question->suggestion_id);
        $this->assertSame([RepairDesk::SITE_QA, RepairDesk::MEDIUM], [$row['kind'], $row['risk']]);
        $this->assertSame('Soru: İmplant tedavisi ağrılı mı?', $row['after'][0]);
        $this->assertTrue(RepairAutopilot::eligible($row), 'checked by AI at night and part of the daily send');

        $desk->edit((int) $question->suggestion_id, 'answer', 'Lokal anestezi altında yapılır; işlem sırasında ağrı hissedilmez.');
        $desk->approve([(int) $question->suggestion_id], $this->admin);

        $question->refresh();
        $this->assertSame(SiteQuestion::PUBLISHED, $question->status);
        $this->assertSame('Lokal anestezi altında yapılır; işlem sırasında ağrı hissedilmez.', $question->answer);
        $this->assertSame(Suggestion::APPLIED, Suggestion::query()->find($question->suggestion_id)->status);
        Queue::assertPushed(SyncSiteShowcaseJob::class, fn (SyncSiteShowcaseJob $job): bool => $job->siteId === $this->site->id);
    }

    public function test_a_private_or_spam_question_never_reaches_the_desk_and_a_rejected_one_never_shows(): void
    {
        $this->enableAi();
        $this->brand->forceFill(['showcase_enabled' => true])->save();
        SiteQaAnswerAgent::fake([['verdict' => 'private', 'question' => 'Beni arar mısınız?', 'answer' => '', 'link_url' => ''],
            ['verdict' => 'publish', 'question' => 'Kaç seans sürer?', 'answer' => 'Çoğu hastada iki ya da üç seansta tamamlanır; muayenede netleşir.', 'link_url' => '']]);
        $private = $this->question(41, 'Beni arar mısınız?');
        $public = $this->question(42, 'Kaç seans?');

        app(SiteQuestions::class)->answer((int) $private->id);
        app(SiteQuestions::class)->answer((int) $public->id);

        $this->assertSame(SiteQuestion::PRIVATE, $private->refresh()->status);
        $this->assertSame(1, Suggestion::query()->where('action_type', SiteQuestions::TYPE)->count());
        app(RepairDesk::class)->reject([(int) $public->refresh()->suggestion_id], $this->admin);
        $this->assertSame(SiteQuestion::HIDDEN, $public->refresh()->status);
    }

    public function test_sync_sends_reviews_and_published_questions_and_switching_off_removes_them(): void
    {
        $this->brand->forceFill(['showcase_enabled' => true])->save();
        $this->review('FIVE', 'Çok ilgili bir ekip, Ayşe Hanım\'a teşekkürler.', 'Ayşe Yılmaz');
        $this->review('FIVE', 'Fiyat 15.000 TL idi, uygun.', 'Mehmet K');
        $this->review('THREE', 'Fena değil.', 'Ali Veli');
        $this->review('FOUR', 'Güzel.', 'Google kullanıcısı', hidden: true);
        $published = $this->question(51, 'Ağrılı mı?');
        $published->forceFill(['status' => SiteQuestion::PUBLISHED, 'answer' => 'Lokal anestezi ile ağrı hissedilmez.', 'published_at' => now()])->save();
        $this->question(52, 'Bekleyen?');

        $this->app->instance(WordPressConnectorClient::class, new WordPressConnectorClient(new WordPressConnectorCanonicalJson, new PublicUrlSafety(fn (string $host): array => ['93.184.216.34'])));
        $sent = [];
        Http::fake(function (Request $request) use (&$sent) {
            $nonce = $request->header(WordPressConnectorClient::HEADER_NONCE)[0] ?? '';
            if ($request->method() === 'POST') {
                $sent[] = json_decode($request->body(), true);
                $data = ['schema_version' => 1, 'ok' => true];
            } else {
                $data = ['schema_version' => 1, 'targets' => [['key' => 'post:42', 'title' => 'İmplant', 'url' => 'https://panorama.com.tr/implant/'], ['key' => 'bad']]];
            }
            $time = now()->timestamp;
            $signature = hash_hmac('sha256', implode("\n", [(string) $time, $nonce, hash('sha256', (new WordPressConnectorCanonicalJson)->encode($data))]), self::SECRET);

            return Http::response(['data' => $data, 'meta' => ['server_time' => $time, 'request_nonce' => $nonce, 'signature' => $signature]]);
        });

        $result = app(SiteShowcase::class)->sync($this->site->id);

        $this->assertTrue($result['sent']);
        $this->assertCount(1, $result['targets'], 'only valid page keys');
        $this->assertTrue($sent[0]['enabled']);
        $this->assertSame(['Ayşe Y.'], array_column($sent[0]['reviews']['items'], 'name'), 'no price, no 3 stars, no hidden; the name shortened');
        $this->assertSame(4.8, $sent[0]['reviews']['rating']);
        $qa = $sent[0]['qa'];
        $this->assertSame(['Ağrılı mı?'], array_column($qa['post:42'], 'q'), 'only published questions');

        (new SyncSiteShowcaseJob($this->site->id))->handle(app(SiteShowcase::class), app(SiteQuestions::class));
        Queue::assertPushed(PlanSiteQuestionsJob::class, fn (PlanSiteQuestionsJob $job): bool => $job->target['key'] === 'post:42');

        $this->brand->forceFill(['showcase_enabled' => false])->save();
        app(SiteShowcase::class)->sync($this->site->id);
        $this->assertSame(['enabled' => false], end($sent));
    }

    public function test_a_review_is_shown_only_without_price_contact_or_link(): void
    {
        $this->assertTrue(SiteShowcase::showable('Harika bir deneyimdi, herkese tavsiye ederim.'));
        $this->assertTrue(SiteShowcase::showable('3 implant yaptırdım, 2 haftada bitti.'));
        foreach (['Toplam 20.000 TL ödedim', 'Bana 0532 123 45 67 ile ulaşın', 'ayse@example.com', 'www.rakip.com', '€500 daha ucuz'] as $text) {
            $this->assertFalse(SiteShowcase::showable($text), $text);
        }
        $this->assertSame('Ayşe Y.', SiteShowcase::shortName('Ayşe Yılmaz'));
        $this->assertSame('Ayşe', SiteShowcase::shortName('Ayşe'));
        $this->assertSame('Google kullanıcısı', SiteShowcase::shortName(''));
    }

    public function test_the_brand_switch_in_brand_settings_sends_to_every_updated_site(): void
    {
        $old = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'eski.com', 'domain' => 'eski.com']);
        CoreConnection::factory()->create(['digital_asset_id' => $old->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://eski.com/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.27.0']]);

        Livewire::test(BrandSettings::class, ['brandId' => $this->brand->id])
            ->assertSee('Sitede yorum ve soru göster')->assertSee('1.28.0 sürümüne güncelle')
            ->call('toggleShowcase')->assertSee('Açık');

        $this->assertTrue($this->brand->refresh()->showcase_enabled);
        Queue::assertPushed(SyncSiteShowcaseJob::class, 1);
        Queue::assertPushed(SyncSiteShowcaseJob::class, fn (SyncSiteShowcaseJob $job): bool => $job->siteId === $this->site->id);
    }

    public function test_the_plugin_keeps_names_on_the_site_and_cleans_up_on_uninstall(): void
    {
        $root = base_path('connectors/wordpress/moxdop-connector');
        $showcase = (string) file_get_contents($root.'/includes/class-moxdop-connector-showcase.php');
        $events = (string) file_get_contents($root.'/includes/class-moxdop-connector-events.php');
        $plugin = (string) file_get_contents($root.'/moxdop-connector.php');

        $this->assertStringContainsString("register_uninstall_hook(__FILE__, ['MoxDOP_Connector_Showcase', 'uninstall'])", $plugin);
        $this->assertStringContainsString('public static function question(', $events);
        $this->assertStringNotContainsString('email', Str::between($events, 'public static function question(', "\n    }\n"), 'no contact in the event');
        $this->assertStringContainsString("'moxdop_sorular'", $showcase);
        $this->assertStringContainsString("'moxdop_yorumlar'", $showcase);
        $this->assertStringContainsString('wp_mail(', $showcase, 'notifications go through the site\'s own mail / SMTP');
    }

    private function question(int $localId = 31, string $text = 'İmplant ağrılı mı?'): SiteQuestion
    {
        return SiteQuestion::query()->create(['brand_id' => $this->brand->id, 'website_asset_id' => $this->site->id, 'target' => 'post:42', 'target_title' => 'Ankara İmplant Tedavisi',
            'target_url' => 'https://panorama.com.tr/implant/', 'source' => SiteQuestion::SOURCE_SITE, 'local_id' => $localId, 'question' => $text, 'status' => SiteQuestion::NEW, 'asked_at' => now()]);
    }

    private function review(string $stars, string $text, string $name, bool $hidden = false): void
    {
        if ($this->location === null) {
            $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'name' => 'Panorama Çankaya']);
            $resource = CoreExternalResource::factory()->create(['provider' => 'google', 'resource_type' => 'google_business_profile', 'external_id' => 'locations/'.$asset->id, 'status' => CoreExternalResource::STATUS_AVAILABLE]);
            CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
            DB::table('gbp_location_snapshots')->insert(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'run_id' => 1, 'location_name' => 'locations/'.$asset->id,
                'title' => 'Panorama Çankaya', 'maps_uri' => 'https://maps.google.com/?cid=1', 'average_rating' => 4.8, 'total_review_count' => 120, 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->location = [$asset->id, $resource->id];
        }
        DB::table('gbp_reviews')->insert(['digital_asset_id' => null, 'external_resource_id' => $this->location[1], 'run_id' => 1, 'location_name' => 'locations/1', 'review_id' => (string) Str::uuid(),
            'star_rating' => $stars, 'comment' => $text, 'reviewer' => json_encode(['displayName' => $name]), 'site_hidden' => $hidden, 'create_time' => now()->subDay(),
            'raw_payload' => '{}', 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function asked(int $localId, string $text): array
    {
        return ['event_id' => (string) Str::uuid(), 'type' => 'qa.asked', 'occurred_at' => now()->toIso8601String(), 'object_type' => 'moxdop_question', 'object_id' => (string) $localId,
            'title' => 'Ankara İmplant Tedavisi', 'url' => 'https://panorama.com.tr/implant/', 'actor_id' => '0', 'actor_name' => '', 'origin' => 'wordpress_automation', 'fields' => ['question'],
            'target' => 'post:42', 'question' => $text];
    }

    /** @param  list<array<string, mixed>>  $events */
    private function send(array $events): TestResponse
    {
        $body = json_encode([
            'schema_version' => 1, 'installation_id' => self::INSTALLATION, 'plugin_version' => '1.28.0', 'events' => $events,
            'delivery' => ['pending' => 0, 'gap_at' => null, 'storage_ready' => true, 'wp_cron_disabled' => false],
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = (string) Str::uuid();
        $signature = hash_hmac('sha256', implode("\n", ['POST', '/api/connectors/wordpress/events', '', $timestamp, $nonce, hash('sha256', $body)]), self::SECRET);

        return $this->call('POST', '/api/connectors/wordpress/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_MOXDOP_INSTALLATION' => self::INSTALLATION, 'HTTP_X_MOXDOP_CLIENT' => 'client-1',
            'HTTP_X_MOXDOP_TIMESTAMP' => $timestamp, 'HTTP_X_MOXDOP_NONCE' => $nonce, 'HTTP_X_MOXDOP_SIGNATURE' => $signature,
        ], $body);
    }
}
