<?php

namespace Tests\Feature\Work;

use App\Jobs\ExecuteExternalWriteJob;
use App\Livewire\Operator\Work\WorkPage;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\Site\ArticleImagePicker;
use App\Services\Site\ContentPosts;
use App\Services\Work\ContentBoard;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use MoxDop\Website\Discovery\PublicUrlSafety;
use Tests\Feature\Site\SiteTestCase;

/**
 * yakup, 2026-10-12: the article's image from the site's own media, Taslakta / Yayında read from the site, and the
 * post's date (scheduled draft, re-dated published article) as an admin click that can be undone.
 */
final class ContentFlowTest extends SiteTestCase
{
    private function connection(string $version = '1.14.0'): CoreConnection
    {
        $connection = CoreConnection::factory()->create(['digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://panorama.com.tr/wp-json/moxdop/v1/snapshot', 'plugin_version' => $version]]);
        CoreConnectionCredential::factory()->create(['connection_id' => $connection->id, 'encrypted_payload' => ['client_id' => 'client-1', 'shared_secret' => self::SECRET]]);

        return $connection;
    }

    private const string SECRET = 'sssssssssssssssssssssssssssssssssssssssssss';

    /**
     * The site answers signed, like the connector does.
     *
     * @param  callable(string, string, ?array<string, mixed>): array<string, mixed>  $answer  method, url, body => data
     */
    private function site(callable $answer): void
    {
        $this->app->instance(WordPressConnectorClient::class, new WordPressConnectorClient(new WordPressConnectorCanonicalJson, new PublicUrlSafety(fn (string $host): array => ['93.184.216.34'])));
        Http::fake(function (Request $request) use ($answer) {
            $nonce = $request->header(WordPressConnectorClient::HEADER_NONCE)[0] ?? '';
            $data = $answer($request->method(), $request->url(), json_decode($request->body(), true));
            $time = now()->timestamp;
            $signature = hash_hmac('sha256', implode("\n", [(string) $time, $nonce, hash('sha256', (new WordPressConnectorCanonicalJson)->encode($data))]), self::SECRET);

            return Http::response(['data' => $data, 'meta' => ['server_time' => $time, 'request_nonce' => $nonce, 'signature' => $signature]]);
        });
    }

    /** @param  array<string, mixed>  $extra */
    private function snapshot(string $type, string $id, string $title, string $url, array $meta = [], array $extra = []): void
    {
        DB::table('website_cms_object_snapshot')->insert(array_merge([
            'digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'object_type' => $type, 'object_id' => $id, 'status' => $type === 'attachment' ? 'inherit' : 'publish',
            'permalink' => $url, 'title' => $title, 'slug' => basename($url), 'metadata' => json_encode($meta), 'observed_at' => now(), 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $type.$id),
        ], $extra));
    }

    private function written(string $title = 'İmplant sonrası ilk hafta', array $action = []): Suggestion
    {
        return Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => md5($title), 'material_hash' => md5($title),
            'title' => $title, 'reason' => 'Kümenin sayfası yok.', 'priority' => 1, 'evidence' => [], 'action_type' => 'content',
            'action' => array_merge(['site_id' => $this->site->id, 'kind' => 'new', 'page_type' => 'blog', 'language' => 'tr',
                'article' => ['title' => $title, 'slug' => 'implant-sonrasi', 'html' => '<h2>İlk gün</h2><p>Soğuk uygulama yapılır.</p>', 'reference' => 'moxdop-'.md5($title),
                    'meta_title' => $title, 'meta_description' => 'İlk hafta.', 'excerpt' => 'Rehber.', 'language' => 'tr']], $action),
            'status' => Suggestion::APPROVED, 'target_type' => 'site', 'target_id' => $this->site->id, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    /** A sent article: its WordPress draft write finished with the post id. */
    private function sent(int $postId, string $title = 'İmplant sonrası ilk hafta'): Suggestion
    {
        $idea = $this->written($title);
        $write = ExternalWriteAction::query()->create(['channel' => 'wordpress', 'action' => ExternalWriteAction::ACTION_ARTICLE_DRAFTS, 'digital_asset_id' => $this->site->id,
            'brand_id' => $this->brand->id, 'status' => 'succeeded', 'request_payload' => ['reference' => 'r'.$postId], 'requested_by' => $this->admin->id,
            'result' => ['posts' => [['language' => 'tr', 'ok' => true, 'post_id' => $postId]], 'post_id' => $postId]]);
        $idea->forceFill(['action' => array_merge($idea->action, ['article_write_id' => $write->id, 'sent_languages' => ['tr']]), 'resolved_at' => now()])->save();

        return $idea;
    }

    public function test_the_article_image_comes_from_the_site_media_and_goes_as_the_featured_image(): void
    {
        Queue::fake();
        $this->connection();
        $this->snapshot('page', '10', 'Diş İmplantı', 'https://panorama.com.tr/implant/', [], ['featured_media_id' => '3']);
        $this->snapshot('attachment', '1', 'implant-sonrasi-bakim', 'https://panorama.com.tr/wp-content/uploads/implant-sonrasi-bakim.jpg', ['mime_type' => 'image/jpeg', 'width' => 1200, 'height' => 800, 'alt_text' => 'İmplant sonrası bakım']);
        $this->snapshot('attachment', '2', 'logo', 'https://panorama.com.tr/wp-content/uploads/implant-logo.png', ['mime_type' => 'image/png', 'width' => 1200, 'height' => 800]);
        $this->snapshot('attachment', '3', 'DSC_0042', 'https://panorama.com.tr/wp-content/uploads/DSC_0042.jpg', ['mime_type' => 'image/jpeg', 'width' => 1600, 'height' => 900]);
        $this->snapshot('attachment', '4', 'kucuk-implant', 'https://panorama.com.tr/wp-content/uploads/kucuk-implant.jpg', ['mime_type' => 'image/jpeg', 'width' => 200, 'height' => 200]);
        $idea = $this->written();

        $choices = app(ArticleImagePicker::class)->candidates($idea, $this->site);
        $this->assertSame([1, 3], array_column($choices, 'media_id'), 'its own words first, then the implant page\'s image; no logo, no tiny image');
        $this->assertStringContainsString('Diş İmplantı', $choices[1]['why']);

        Livewire::test(WorkPage::class)->call('setStep', 'okunacak')->call('read', $idea->id)->assertSeeHtml('data-article-image="'.$idea->id.'"')
            ->assertSeeHtml('implant-sonrasi-bakim.jpg')->assertSeeHtml('data-image-choice="3"')
            ->call('pickImage', $idea->id, 3)->assertSee('Yazının görseli seçildi.');
        $this->assertSame(3, $idea->fresh()->action['image']['media_id']);

        Livewire::test(WorkPage::class)->call('read', $idea->id)->call('sendContent', $idea->id);
        $write = ExternalWriteAction::query()->where('action', ExternalWriteAction::ACTION_ARTICLE_DRAFTS)->sole();
        $this->assertSame(3, $write->request_payload['drafts'][0]['draft']['featured_media_id']);

        $other = $this->written('Zirkonyum kaplama ömrü');
        Livewire::test(WorkPage::class)->call('pickImage', $other->id)->assertSee('Yazı görselsiz gidecek.');
        app(ContentBoard::class)->send((int) $other->id, $this->admin);
        $this->assertArrayNotHasKey('featured_media_id', ExternalWriteAction::query()->where('request_payload->reference', 'moxdop-'.md5('Zirkonyum kaplama ömrü'))->sole()->request_payload['drafts'][0]['draft']);
    }

    public function test_without_a_matching_image_the_nearest_page_image_is_offered(): void
    {
        $this->snapshot('page', '10', 'Diş İmplantı Tedavisi', 'https://panorama.com.tr/implant/', [], ['featured_media_id' => '3']);
        $this->snapshot('page', '11', 'Hakkımızda', 'https://panorama.com.tr/hakkimizda/', [], ['featured_media_id' => '5']);
        $this->snapshot('attachment', '3', 'DSC_0042', 'https://panorama.com.tr/wp-content/uploads/DSC_0042.jpg', ['mime_type' => 'image/jpeg', 'width' => 1600, 'height' => 900]);
        $this->snapshot('attachment', '5', 'IMG_9', 'https://panorama.com.tr/wp-content/uploads/IMG_9.jpg', ['mime_type' => 'image/jpeg', 'width' => 1600, 'height' => 900]);

        $choices = app(ArticleImagePicker::class)->candidates($this->written('İmplant tedavisinde ağrı'), $this->site);
        $this->assertSame([3], array_column($choices, 'media_id'), 'the image of the page about the subject');
        $this->assertStringContainsString('Diş İmplantı Tedavisi', $choices[0]['why']);

        $choices = app(ArticleImagePicker::class)->candidates($this->written('Ağız kokusu neden olur'), $this->site);
        $this->assertEqualsCanonicalizing([3, 5], array_column($choices, 'media_id'), 'nothing names the subject: the site\'s page images');
        $this->assertStringStartsWith('sitenin görseli', $choices[0]['why']);
    }

    public function test_sent_articles_are_split_into_taslakta_and_yayinda_from_the_site_with_search_console_numbers(): void
    {
        $draft = $this->sent(501, 'İmplant sonrası ilk hafta');
        $live = $this->sent(502, 'Zirkonyum kaplama ömrü');
        ContentPosts::remember((int) $this->site->id, 501, ['status' => 'future', 'date_gmt' => '2026-10-20T07:00:00+00:00']);
        $this->snapshot('post', '502', 'Zirkonyum kaplama ömrü', 'https://panorama.com.tr/zirkonyum-kaplama-omru/', [], ['published_at' => now()->subDays(40)]);
        $this->insertFacts('gsc_page_daily', [
            'digital_asset_id' => null, 'external_resource_id' => $this->gsc->id, 'site_url' => 'sc-domain:panorama.com.tr', 'search_type' => 'web',
            'reporting_date' => now()->subDays(5)->toDateString(), 'page' => 'https://panorama.com.tr/zirkonyum-kaplama-omru/', 'clicks' => 12, 'impressions' => 340,
            'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', 'z'),
        ]);

        $queue = app(ContentBoard::class)->queue();
        $this->assertSame([1, 1], [$queue['counts']['taslakta'], $queue['counts']['yayinda']]);
        $this->assertSame('Yayın planlandı · 20.10.2026 10:00', $queue['groups']['taslakta'][0]['items'][0]['post_line']);
        $item = $queue['groups']['yayinda'][0]['items'][0];
        $this->assertSame([$live->id, 'https://panorama.com.tr/zirkonyum-kaplama-omru/', 12, 340], [$item['id'], $item['link'], $item['metrics']['clicks30'], $item['metrics']['impressions90']]);

        Livewire::test(WorkPage::class)->call('setStep', 'yayinda')->assertSeeHtml('data-post-metrics="'.$live->id.'"')->assertSee('Sitede aç')
            ->assertSee('Tarihi değiştir')->assertSee('Yalnız tarihi yenilemek Google');
        Livewire::test(WorkPage::class)->call('setStep', 'taslakta')->assertSeeHtml('data-post="'.$draft->id.'"')->assertSee('Planı değiştir');
    }

    public function test_a_draft_is_scheduled_and_a_published_article_re_dated_by_admin_click_and_both_undo(): void
    {
        $this->connection();
        $draft = $this->sent(501);
        $live = $this->sent(502, 'Zirkonyum kaplama ömrü');
        ContentPosts::remember((int) $this->site->id, 502, ['status' => 'publish', 'date_gmt' => '2026-09-01T07:00:00+00:00', 'link' => 'https://panorama.com.tr/z/']);
        $calls = [];
        $this->site(function (string $method, string $url, ?array $body) use (&$calls): array {
            $postId = (int) preg_replace('~^.*/posts/(\d+)/date$~', '$1', $url);
            $calls[] = [$postId, $body['post_date'], $body['schedule']];

            return ['schema_version' => 1, 'post_id' => $postId, 'status' => $body['schedule'] ? 'future' : ($postId === 502 ? 'publish' : 'draft'), 'date_gmt' => $body['post_date'],
                'previous' => $postId === 502 ? ['status' => 'publish', 'date_gmt' => '2026-09-01T07:00:00+00:00'] : ['status' => 'draft', 'date_gmt' => null]];
        });
        Queue::fake();

        $page = Livewire::test(WorkPage::class)->call('setStep', 'taslakta')->set('dates.'.$draft->id, now('Europe/Istanbul')->addDays(3)->format('Y-m-d\T10:00'))
            ->call('changeContentDate', $draft->id)->assertSee('yayınlanmak üzere planlanıyor');
        Livewire::test(WorkPage::class)->set('dates.'.$live->id, now('Europe/Istanbul')->addDay()->format('Y-m-d'))->call('changeContentDate', $live->id)
            ->assertSee('Yayındaki yazıya ileri bir tarih verilemez.');
        Livewire::test(WorkPage::class)->set('dates.'.$live->id, '2026-08-15')->call('changeContentDate', $live->id)->assertSee('olarak değiştiriliyor');
        Queue::assertPushed(ExecuteExternalWriteJob::class, 2);

        $writes = ExternalWriteAction::query()->where('action', ExternalWriteAction::ACTION_POST_DATE)->orderBy('id')->get();
        $this->assertSame([true, false], [$writes[0]->request_payload['schedule'], $writes[1]->request_payload['schedule']]);
        $this->assertSame(['tr' => 501], $writes[0]->request_payload['posts']);
        $service = app(ExternalWriteService::class);
        $writes->each(fn (ExternalWriteAction $w) => $service->execute($w));
        $this->assertSame(['succeeded', 'succeeded'], $writes->map(fn (ExternalWriteAction $w): string => $w->fresh()->status)->all());
        $this->assertSame('future', app(ContentPosts::class)->states((int) $this->site->id, [501])[501]['status'], 'the board shows the new state at once');

        $writes->each(fn (ExternalWriteAction $w) => $service->executeUndo($w->fresh()));
        $this->assertSame(['undone', 'undone'], $writes->map(fn (ExternalWriteAction $w): string => $w->fresh()->status)->all());
        $this->assertSame([501, false], [$calls[2][0], $calls[2][2]], 'the scheduled draft goes back to being a draft');
        $this->assertSame([502, '2026-09-01T07:00:00+00:00', false], $calls[3], 'the published article gets its old date back');
    }

    public function test_the_date_needs_connector_1_14(): void
    {
        $this->connection('1.13.0');
        $draft = $this->sent(501);

        Livewire::test(WorkPage::class)->set('dates.'.$draft->id, now('Europe/Istanbul')->addDays(3)->format('Y-m-d\T10:00'))->call('changeContentDate', $draft->id)
            ->assertSee('en az 1.14.0 gerekli');
        $this->assertSame(0, ExternalWriteAction::query()->where('action', ExternalWriteAction::ACTION_POST_DATE)->count());
    }

    public function test_the_hourly_read_asks_the_site_where_its_posts_stand(): void
    {
        $this->connection();
        $this->sent(501);
        $asked = [];
        $this->site(function (string $method, string $url) use (&$asked): array {
            $asked[] = $url;

            return ['schema_version' => 1, 'posts' => [['post_id' => 501, 'status' => 'publish', 'date_gmt' => '2026-10-01T07:00:00+00:00', 'link' => 'https://panorama.com.tr/i/']]];
        });

        $this->assertSame(1, app(ContentPosts::class)->sync($this->site));
        $this->assertSame('https://panorama.com.tr/wp-json/moxdop/v1/posts/status?ids=501', urldecode($asked[0]));
        $state = app(ContentPosts::class)->states((int) $this->site->id, [501])[501];
        $this->assertSame(['publish', 'https://panorama.com.tr/i/', '01.10.2026 10:00'], [$state['status'], $state['link'], $state['date']->format('d.m.Y H:i')]);
        $this->artisan('moxdop:content:post-status')->assertSuccessful();
    }

    public function test_the_connector_only_touches_posts_moxdop_made(): void
    {
        $controller = (string) file_get_contents(base_path('connectors/wordpress/moxdop-connector/includes/class-moxdop-connector-rest-controller.php'));
        $posts = (string) file_get_contents(base_path('connectors/wordpress/moxdop-connector/includes/class-moxdop-connector-posts.php'));
        $dates = substr($posts, (int) strpos($posts, 'public function change_post_date'), 3000);
        $this->assertStringContainsString('get_post_meta($post_id, \'_moxdop_created\', true) !== \'1\'', $dates);
        $this->assertStringContainsString('MoxDOP_Connector_Drafts::scheduling_allowed()', $dates, 'scheduling follows the site\'s own switch');
        $this->assertStringContainsString('MoxDOP_Connector_Fixes::content_allowed()', $dates, 'a published post is re-dated only with content updates on');
        $this->assertStringContainsString('A published post cannot get a future date.', $dates);
        $this->assertStringContainsString("'/posts/status'", $posts);
        $this->assertStringContainsString('(new MoxDOP_Connector_Posts($this->auth))->register_routes(self::NAMESPACE)', $controller);
        $this->assertStringContainsString('class-moxdop-connector-posts.php', (string) file_get_contents(base_path('connectors/wordpress/moxdop-connector/moxdop-connector.php')));
        $this->assertStringContainsString('wp_attachment_is_image($featured)', $controller, 'only an image already in the media library');
    }
}
