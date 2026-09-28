<?php

namespace Tests\Feature\ContentDelivery;

use App\Ai\Agents\Content\ContentLocalizerAgent;
use App\Ai\Agents\SiteFixes\PageWriterAgent;
use App\Livewire\Operator\Website\SiteFixesPanel;
use App\Models\Brand;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\ServiceCategory;
use App\Models\SiteFixItem;
use App\Models\User;
use App\Services\ContentDelivery\ArticleDraft;
use App\Services\ContentDelivery\ContentComplianceGate;
use App\Services\ContentDelivery\ContentDraftPublisher;
use App\Services\ContentDelivery\ContentLocalizer;
use App\Services\ContentDelivery\LanguageLinkMap;
use App\Services\ContentDelivery\WxrExporter;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\ExternalWrites\WordPressDraftWriter;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\SiteFixes\SiteFixAi;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use MoxDop\Website\Discovery\PublicUrlSafety;
use Tests\TestCase;

/** ADR-076: compliance gate, rich / multi-language WordPress drafts, AI localization and WXR export. */
final class ContentDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'ssssssssssssssssssssssssssssssssssssssssssss';

    private User $admin;

    private User $member;

    private Brand $brand;

    private DigitalAsset $site;

    private CoreConnection $connection;

    /** @var list<array{0: string, 1: string, 2: mixed}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->member = User::factory()->create(['is_active' => true]);
        $this->member->assignRole(Roles::TEAM_MEMBER);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas Diş']);
        $this->brand->sectors()->attach(ServiceCategory::query()->where('code', 'dental')->value('id'));
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => 'active', 'module_id' => 'website', 'name' => 'atlas.example', 'domain' => 'example.test']);
        $this->connection = CoreConnection::factory()->create([
            'digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://example.test/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.5.0'],
        ]);
        CoreConnectionCredential::factory()->create(['connection_id' => $this->connection->id, 'encrypted_payload' => ['client_id' => 'client-1', 'shared_secret' => self::SECRET]]);
        $this->app->instance(WordPressConnectorClient::class, new WordPressConnectorClient(new WordPressConnectorCanonicalJson, new PublicUrlSafety(fn (string $host): array => ['93.184.216.34'])));
    }

    public function test_payload_carries_the_full_article_and_stays_backward_compatible(): void
    {
        $article = new ArticleDraft(
            title: 'İmplant Tedavisi Süreci', html: '<h2>Süreç</h2><p>Metin</p>', reference: 'article-7', excerpt: 'Kısa özet',
            metaTitle: 'İmplant Tedavisi | Atlas', metaDescription: 'İmplant süreci hakkında bilgi.', focusKeyword: 'implant tedavisi',
            categories: [['name' => 'Tedaviler']], tags: [['name' => 'implant']], language: 'tr', postDate: CarbonImmutable::parse('2030-01-02 09:00', 'Europe/Istanbul'),
            translationKey: 'article-7',
        );
        $payload = WordPressDraftWriter::payload($article);

        $this->assertSame('İmplant Tedavisi Süreci', $payload['title']);
        $this->assertSame('<h2>Süreç</h2><p>Metin</p>', $payload['content_html'], 'full HTML body, old key kept for older plugins');
        $this->assertSame('implant-tedavisi-sureci', $payload['slug']);
        $this->assertSame(['title' => 'İmplant Tedavisi | Atlas', 'description' => 'İmplant süreci hakkında bilgi.', 'focus_keyword' => 'implant tedavisi'], $payload['seo']);
        $this->assertSame([['name' => 'Tedaviler']], $payload['categories']);
        $this->assertSame([['name' => 'implant']], $payload['tags']);
        $this->assertSame('tr', $payload['language']);
        $this->assertSame('2030-01-02T06:00:00+00:00', $payload['post_date']);
        $this->assertArrayNotHasKey('schedule', $payload, 'no schedule unless the operator chose it');
        $this->assertArrayNotHasKey('translation_of', $payload);
        $this->assertSame(['title', 'content_html', 'post_type', 'excerpt', 'reference'], array_slice(array_keys($payload), 0, 5));

        $scheduled = WordPressDraftWriter::payload($article->with(['schedule' => true]), 55);
        $this->assertTrue($scheduled['schedule']);
        $this->assertSame(55, $scheduled['translation_of']);
        $this->assertArrayNotHasKey('schedule', WordPressDraftWriter::payload($article->with(['schedule' => true, 'post_date' => '2020-01-01T00:00:00Z'])), 'past dates never schedule');

        $page = WordPressDraftWriter::payload($article->with(['post_type' => 'page', 'language' => null, 'meta_title' => '', 'meta_description' => '', 'focus_keyword' => '']));
        $this->assertArrayNotHasKey('categories', $page, 'pages have no categories');
        $this->assertArrayNotHasKey('seo', $page);
        $this->assertArrayNotHasKey('language', $page);
    }

    public function test_ai_page_breaking_sector_rules_is_blocked_until_rewritten_compliantly(): void
    {
        $this->enableAi();
        $this->fakeWordPress();
        $item = $this->newPageItem();
        PageWriterAgent::fake([
            ['title' => 'İzmir’in en iyi implant kliniği', 'html' => '<h2>Garantili implant</h2><p>Ağrısız ve hızlı tedavi.</p>', 'summary' => 'x'],
            ['title' => 'İzmir’de implant tedavisi', 'html' => '<h2>İmplant tedavisi nedir?</h2><p>Tedavi süreci hekim muayenesiyle planlanır.</p>', 'summary' => 'y'],
        ]);
        $this->actingAs($this->admin);

        Livewire::test(SiteFixesPanel::class, ['websiteId' => $this->site->id])->set('phase', '3')->call('writePage', $item->id);
        $violations = (array) data_get($item->fresh()->proposed, 'compliance');
        $matched = array_column($violations, 'matched');
        $this->assertContains('en iyi', $matched);
        $this->assertContains('garanti', $matched);
        $this->assertContains('agrisiz', $matched);
        $this->assertContains('Başlık', array_column($violations, 'field_label'));

        Livewire::test(SiteFixesPanel::class, ['websiteId' => $this->site->id])->set('phase', '3')
            ->assertSee('Yeniden yaz (uyumlu)')->assertSee('Sektör uyum kuralına takılan ifadeler')->assertSee('«en iyi»', false)
            ->call('sendDraft', $item->id)->assertSee('sektör uyum kurallarına takılıyor')->assertSee('«garanti»', false);
        $this->assertSame(0, ExternalWriteAction::query()->count());
        $this->get(route('operator.website.wxr-export', ['site' => $this->site->id, 'items' => $item->id]))->assertStatus(422)->assertSee('uyum kurallarına takılıyor');

        Livewire::test(SiteFixesPanel::class, ['websiteId' => $this->site->id])->set('phase', '3')->call('writePage', $item->id);
        PageWriterAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, '"compliance_fix"') && str_contains((string) $prompt->prompt, '"phrase":"garanti"'));
        $this->assertSame([], ContentComplianceGate::blocking((array) data_get($item->fresh()->proposed, 'compliance')));

        Livewire::test(SiteFixesPanel::class, ['websiteId' => $this->site->id])->set('phase', '3')->call('sendDraft', $item->id);
        $draft = collect($this->sent)->first(fn (array $s): bool => str_ends_with($s[1], '/drafts'));
        $this->assertNotNull($draft);
        $this->assertSame('izmirde-implant-tedavisi', $draft[2]['slug']);
        $this->assertSame('İzmir’de implant tedavisi', $draft[2]['seo']['title']);
        $this->assertSame('Tedavi süreci hekim muayenesiyle planlanır.', $draft[2]['seo']['description']);
        $this->assertSame('page', $draft[2]['post_type']);
    }

    public function test_operator_edit_is_rechecked_and_can_clear_the_block(): void
    {
        $item = $this->newPageItem(['title' => 'Kampanyalı implant', 'html' => '<p>Bu ay indirim var.</p>']);
        app(SiteFixAi::class)->recheckCompliance($item);
        $this->actingAs($this->admin);

        $component = Livewire::test(SiteFixesPanel::class, ['websiteId' => $this->site->id])->set('phase', '3')->call('editPage', $item->id)
            ->set("pageEdits.{$item->id}.title", 'İmplant tedavisi')->set("pageEdits.{$item->id}.html", '<p onclick="x">Bu ay en ucuz fiyat.</p>')
            ->call('savePage', $item->id)->assertSee('hâlâ uyum kuralına takılıyor');
        $this->assertStringNotContainsString('onclick', (string) data_get($item->fresh()->proposed, 'value.html'));
        $this->assertSame('operator', $item->fresh()->proposed_by);

        $component->call('editPage', $item->id)->set("pageEdits.{$item->id}.html", '<p>İmplant tedavisi hakkında bilgi.</p>')->call('savePage', $item->id)->assertSee('uyum kontrolünden geçti');
        $this->assertSame([], (array) data_get($item->fresh()->proposed, 'compliance'));
    }

    public function test_english_health_claims_are_caught(): void
    {
        $gate = app(ContentComplianceGate::class);
        $article = new ArticleDraft(title: 'The best painless implants', html: '<p>Guaranteed, fast and comfortable treatment at a great price.</p>', reference: 'en-1', language: 'en');
        $matched = array_column($gate->violations($this->brand, $article), 'matched');
        foreach (['best', 'painless', 'guaranteed', 'fast', 'comfortable', 'price'] as $phrase) {
            $this->assertContains($phrase, $matched, $phrase);
        }
        $this->assertSame([], $gate->violations($this->brand, new ArticleDraft(title: 'Dental implant treatment', html: '<p>How the treatment is planned by the dentist.</p>', reference: 'en-2', language: 'en')));
        $this->expectException(ValidationException::class);
        $gate->assertCompliant($this->brand, $article);
    }

    public function test_multi_language_publish_sends_source_first_and_links_translations(): void
    {
        $this->siteLanguages(['tr' => true, 'en' => false, 'de' => false]);
        $this->fakeWordPress();
        $source = new ArticleDraft(title: 'İmplant tedavisi', html: '<p>Bilgi.</p>', reference: 'article-9', language: 'tr', categories: [['name' => 'Tedaviler']]);
        $en = new ArticleDraft(title: 'Dental implant treatment', html: '<p>Information.</p>', reference: 'article-9', language: 'en', categories: [['name' => 'Treatments', 'source' => 'Tedaviler']]);
        $de = ['title' => 'Zahnimplantat', 'html' => '<p>Information.</p>', 'reference' => 'article-9-de'];

        $this->actingAs($this->member);
        $this->assertThrows(fn () => app(ContentDraftPublisher::class)->publish($this->member, $this->site, $source, ['en' => $en]));
        $this->assertSame(0, ExternalWriteAction::query()->count());

        $action = app(ContentDraftPublisher::class)->publish($this->admin, $this->site, $source, ['de' => $de, 0 => $en]);
        $action->refresh();
        $this->assertSame('succeeded', $action->status, (string) $action->error);
        $posts = array_values(array_filter($this->sent, fn (array $s): bool => $s[0] === 'POST' && str_ends_with($s[1], '/drafts')));
        $this->assertSame(['tr', 'de', 'en'], array_column(array_column($posts, 2), 'language'));
        $this->assertArrayNotHasKey('translation_of', $posts[0][2]);
        $this->assertSame(101, $posts[1][2]['translation_of']);
        $this->assertSame(101, $posts[2][2]['translation_of']);
        $this->assertSame(['article-9', 'article-9-de', 'article-9-en'], array_column(array_column($posts, 2), 'reference'));
        $this->assertSame(['article-9', 'article-9', 'article-9'], array_column(array_column($posts, 2), 'translation_key'));
        $this->assertSame([['name' => 'Treatments', 'source' => 'Tedaviler']], $posts[2][2]['categories']);
        $this->assertSame([101, 102, 103], array_column($action->result['posts'], 'post_id'));
        $this->assertTrue($action->result['posts'][1]['translation_linked']);

        app(ExternalWriteService::class)->requestUndo($this->admin, $action);
        $this->assertSame('undone', $action->fresh()->status);
        $deletes = array_values(array_filter($this->sent, fn (array $s): bool => $s[0] === 'DELETE'));
        $this->assertSame(['/drafts/103', '/drafts/102', '/drafts/101'], array_map(fn (array $s): string => substr($s[1], strpos($s[1], '/drafts')), $deletes));

        // Unknown language, duplicate languages, and an old plugin are refused before anything is sent.
        $count = count($this->sent);
        foreach ([['fr' => $en->with(['language' => 'fr'])], [$en, $en]] as $bad) {
            try {
                app(ContentDraftPublisher::class)->publish($this->admin, $this->site, $source->with(['reference' => 'x-'.count($bad)]), $bad);
                $this->fail('expected a refusal');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->connection->forceFill(['config' => array_merge($this->connection->config, ['plugin_version' => '1.4.1'])])->save();
        try {
            app(ContentDraftPublisher::class)->publish($this->admin, $this->site, $source->with(['reference' => 'old']), [$en]);
            $this->fail('expected a refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('en az 1.5.0', (string) collect($e->errors())->flatten()->first());
        }
        $this->assertCount($count, $this->sent);
    }

    public function test_translation_that_breaks_rules_is_blocked(): void
    {
        $this->siteLanguages(['tr' => true, 'en' => false]);
        $this->fakeWordPress();
        $source = new ArticleDraft(title: 'İmplant tedavisi', html: '<p>Bilgi.</p>', reference: 'article-3', language: 'tr');
        $en = new ArticleDraft(title: 'Painless implants', html: '<p>Best price.</p>', reference: 'article-3-en', language: 'en');

        try {
            app(ContentDraftPublisher::class)->publish($this->admin, $this->site, $source, [$en]);
            $this->fail('expected a refusal');
        } catch (ValidationException $e) {
            $message = (string) collect($e->errors())->flatten()->first();
            $this->assertStringContainsString('Metin (en)', $message);
            $this->assertStringContainsString('painless', $message);
        }
        $this->assertSame([], $this->sent);
    }

    public function test_localizer_rewrites_links_and_reprompts_with_violations(): void
    {
        $this->enableAi();
        $this->siteLanguages(['tr' => true, 'en' => false]);
        DB::table('website_cms_object_snapshot')->insert([
            $this->object(11, 'https://example.test/implant/', ['language' => 'tr', 'translations' => ['tr' => 11, 'en' => 21]]),
            $this->object(21, 'https://example.test/en/dental-implant/', ['language' => 'en', 'translations' => ['tr' => 11, 'en' => 21]]),
        ]);
        ContentLocalizerAgent::fake([
            ['title' => 'The best dental implants', 'slug' => 'best-dental-implants', 'meta_title' => 'Implants', 'meta_description' => 'x', 'focus_keyword' => 'dental implant', 'excerpt' => '', 'html' => '<p>See <a href="https://example.test/implant/">implant</a>.</p>', 'categories' => ['Treatments']],
            ['title' => 'Dental implant treatment', 'slug' => 'Dental Implant Treatment', 'meta_title' => 'Dental implant treatment', 'meta_description' => 'How treatment is planned.', 'focus_keyword' => 'dental implant',
                'excerpt' => 'Short.', 'html' => '<p>See <a href="https://example.test/implant/">implant</a>, <a href="/kanal-tedavisi/">root canal</a> and <a href="https://other.example/">other</a>.</p>', 'categories' => ['Treatments']],
        ]);
        $source = new ArticleDraft(title: 'İmplant tedavisi', html: '<p>Bilgi <a href="https://example.test/implant/">implant</a>.</p>', reference: 'article-5', language: 'tr', categories: [['name' => 'Tedaviler']]);

        $result = app(ContentLocalizer::class)->localize($source, 'en', $this->site);

        $this->assertSame(2, $result['attempts']);
        ContentLocalizerAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, '"violations"') && str_contains((string) $prompt->prompt, '"phrase":"best"'));
        $article = $result['article'];
        $this->assertSame([], ContentComplianceGate::blocking($result['violations']));
        $this->assertSame('en', $article->language);
        $this->assertSame('article-5-en', $article->reference);
        $this->assertSame('article-5', $article->translationKey);
        $this->assertSame('dental-implant-treatment', $article->slug);
        $this->assertSame([['name' => 'Treatments', 'source' => 'Tedaviler']], $article->categories);
        $this->assertStringContainsString('href="https://example.test/en/dental-implant/"', $article->html, 'known translation');
        $this->assertStringContainsString('href="https://example.test/en/"', $article->html, 'unknown page → language home');
        $this->assertStringContainsString('href="https://other.example/"', $article->html, 'external links stay');
        $this->assertSame(['example.test/implant' => 'https://example.test/en/dental-implant/'], app(LanguageLinkMap::class)->map($this->site, 'en'));
    }

    public function test_wxr_export_is_valid_and_carries_seo_and_translation_keys(): void
    {
        $tr = new ArticleDraft(title: 'İmplant & bakım', html: '<h2>Süreç</h2><p>Metin ]]> sonu</p>', reference: 'article-1', excerpt: 'Özet', metaTitle: 'İmplant', metaDescription: 'Açıklama',
            focusKeyword: 'implant', categories: [['name' => 'Tedaviler']], tags: [['name' => 'İmplant']], language: 'tr', postDate: CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'), translationKey: 'article-1');
        $en = $tr->with(['title' => 'Implant care', 'html' => '<p>Text</p>', 'reference' => 'article-1-en', 'language' => 'en', 'categories' => [['name' => 'Treatments', 'source' => 'Tedaviler']], 'tags' => []]);
        $xml = app(WxrExporter::class)->export([$tr, $en], ['site_url' => 'https://example.test', 'author_login' => 'editor', 'timezone' => 'Europe/Istanbul', 'language' => 'tr', 'generated_at' => CarbonImmutable::parse('2026-10-02 08:00:00', 'UTC')]);

        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($xml, LIBXML_NONET), 'well-formed XML');
        $this->assertStringContainsString('<![CDATA[<h2>Süreç</h2>', $xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('wp', WxrExporter::NS_WP);
        $xpath->registerNamespace('content', WxrExporter::NS_CONTENT);
        $xpath->registerNamespace('excerpt', WxrExporter::NS_EXCERPT);
        $xpath->registerNamespace('dc', WxrExporter::NS_DC);
        $this->assertSame('1.2', $xpath->evaluate('string(//channel/wp:wxr_version)'));
        $this->assertSame('editor', $xpath->evaluate('string(//channel/wp:author/wp:author_login)'));
        $this->assertSame(2, $xpath->query('//item')->length);
        $this->assertSame('<h2>Süreç</h2><p>Metin ]]> sonu</p>', $xpath->evaluate('string(//item[1]/content:encoded)'), 'CDATA end marker survives');
        $this->assertSame('Özet', $xpath->evaluate('string(//item[1]/excerpt:encoded)'));
        $this->assertSame('İmplant & bakım', $xpath->evaluate('string(//item[1]/title)'));
        $this->assertSame('editor', $xpath->evaluate('string(//item[1]/dc:creator)'));
        $this->assertSame('2026-10-01 15:00:00', $xpath->evaluate('string(//item[1]/wp:post_date)'));
        $this->assertSame('2026-10-01 12:00:00', $xpath->evaluate('string(//item[1]/wp:post_date_gmt)'));
        $this->assertSame('draft', $xpath->evaluate('string(//item[1]/wp:status)'));
        $this->assertSame('implant-bakim', $xpath->evaluate('string(//item[1]/wp:post_name)'));
        $this->assertSame('post', $xpath->evaluate('string(//item[1]/wp:post_type)'));
        $this->assertSame('tedaviler', $xpath->evaluate('string(//item[1]/category[@domain="category"]/@nicename)'));
        $this->assertSame('treatments-en', $xpath->evaluate('string(//item[2]/category[@domain="category"]/@nicename)'));
        $this->assertSame('Tedaviler', $xpath->evaluate('string(//channel/wp:category[1]/wp:cat_name)'));
        $meta = fn (int $item, string $key): string => $xpath->evaluate('string(//item['.$item.']/wp:postmeta[wp:meta_key="'.$key.'"]/wp:meta_value)');
        $this->assertSame('İmplant', $meta(1, '_yoast_wpseo_title'));
        $this->assertSame('Açıklama', $meta(1, 'rank_math_description'));
        $this->assertSame('implant', $meta(1, 'rank_math_focus_keyword'));
        $this->assertSame('article-1', $meta(1, '_moxdop_translation_key'));
        $this->assertSame('article-1', $meta(2, '_moxdop_translation_key'));
        $this->assertSame('en', $meta(2, '_moxdop_language'));
    }

    public function test_wxr_download_is_admin_only_and_exports_new_page_drafts(): void
    {
        $item = $this->newPageItem(['title' => 'Kanal tedavisi', 'html' => '<p>Kanal tedavisi hakkında bilgi.</p>']);

        $this->actingAs($this->member)->get(route('operator.website.wxr-export', ['site' => $this->site->id]))->assertForbidden();
        $response = $this->actingAs($this->admin)->get(route('operator.website.wxr-export', ['site' => $this->site->id, 'items' => $item->id]))->assertOk();
        $this->assertStringContainsString('application/xml', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="example-test-moxdop-wxr-', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('<wp:post_type><![CDATA[page]]></wp:post_type>', $response->getContent());
        $this->assertStringContainsString('_moxdop_draft_reference', $response->getContent());
    }

    public function test_plugin_files_are_valid_php_and_keep_drafts_unpublished(): void
    {
        foreach (glob(base_path('connectors/wordpress/moxdop-connector/{*.php,includes/*.php}'), GLOB_BRACE) ?: [] as $file) {
            exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $output, $code);
            $this->assertSame(0, $code, $file.': '.implode("\n", $output));
        }
        $plugin = file_get_contents(base_path('connectors/wordpress/moxdop-connector/moxdop-connector.php'));
        $this->assertStringContainsString("define('MOXDOP_CONNECTOR_VERSION', '1.5.0')", $plugin);
        $this->assertSame('1.5.0', config('moxdop-wordpress.connector_version'));
        $this->assertStringContainsString('Stable tag: 1.5.0', file_get_contents(base_path('connectors/wordpress/moxdop-connector/readme.txt')));

        $drafts = file_get_contents(base_path('connectors/wordpress/moxdop-connector/includes/class-moxdop-connector-drafts.php'));
        $this->assertStringContainsString("\$fields['post_status'] = 'draft';", $drafts);
        $this->assertStringContainsString("get_option('moxdop_connector_allow_schedule', '0') === '1'", $drafts, 'scheduling is off by default');
        $this->assertStringNotContainsString("'publish'", $drafts);
        $this->assertStringContainsString('pll_save_post_translations', $drafts);
        $this->assertStringContainsString('pll_save_term_translations', $drafts);
        $this->assertStringContainsString('if (! self::polylang_active())', $drafts, 'without Polylang the language fields are ignored');

        $pairing = file_get_contents(base_path('connectors/wordpress/moxdop-connector/includes/class-moxdop-connector-translation-pairing.php'));
        $this->assertStringContainsString("check_admin_referer('moxdop_polylang_pair')", $pairing);
        $this->assertStringContainsString("current_user_can('manage_options')", $pairing);
        $this->assertStringContainsString("'_moxdop_translation_key'", $pairing);
        $this->assertStringContainsString("'status' => 'done'", $pairing, 'already linked groups are left alone');
    }

    private function enableAi(): void
    {
        config(['moxdop.anthropic.api_key' => 'sk-ant-test']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
    }

    /** @param array{title?: string, html?: string} $proposal */
    private function newPageItem(array $proposal = []): SiteFixItem
    {
        return SiteFixItem::query()->create([
            'digital_asset_id' => $this->site->id, 'brand_id' => $this->brand->id, 'item_key' => hash('sha256', 'new-page-'.count($proposal)), 'type' => 'new_page', 'phase' => 3,
            'url' => null, 'label' => 'İmplant (İzmir)', 'reason' => 'Talep var, sayfa yok.', 'status' => 'open',
            'current' => ['brief' => ['page_title' => 'İzmir implant', 'queries' => ['izmir implant']]],
            'proposed' => $proposal === [] ? null : ['value' => $proposal], 'proposed_by' => $proposal === [] ? null : 'ai',
        ]);
    }

    /** @param array<string, bool> $languages slug => default */
    private function siteLanguages(array $languages): void
    {
        DB::table('website_cms_site_snapshot')->insert([
            'digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'site_key' => 'k', 'site_url' => 'https://example.test/', 'home_url' => 'https://example.test/',
            'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', 'site'),
            'metadata' => json_encode(['languages' => collect($languages)->map(fn (bool $default, string $slug): array => ['slug' => $slug, 'name' => strtoupper($slug), 'locale' => $slug, 'default' => $default,
                'home_url' => $default ? 'https://example.test/' : 'https://example.test/'.$slug.'/'])->values()->all()]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    private function object(int $id, string $url, array $metadata): array
    {
        return [
            'digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'object_type' => 'page', 'object_id' => (string) $id, 'status' => 'publish',
            'permalink' => $url, 'title' => 'Sayfa '.$id, 'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', 'o'.$id), 'metadata' => json_encode($metadata), 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function fakeWordPress(): void
    {
        $next = 100;
        Http::fake(function (Request $request) use (&$next) {
            $body = json_decode($request->body(), true);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->sent[] = [$request->method(), $request->url(), $body];
            if ($request->method() === 'DELETE') {
                $data = ['schema_version' => 1, 'post_id' => (int) basename($path), 'status' => 'trash'];
            } else {
                $id = ++$next;
                $translations = isset($body['language']) ? array_filter([$body['language'] => $id] + (isset($body['translation_of']) ? ['tr' => $body['translation_of']] : [])) : [];
                $data = ['schema_version' => 1, 'post_id' => $id, 'status' => 'draft', 'slug' => (string) ($body['slug'] ?? ''), 'edit_url' => 'https://example.test/wp-admin/post.php?post='.$id.'&action=edit', 'preview_url' => '',
                    'language' => $body['language'] ?? null, 'translations' => $translations, 'categories' => [], 'tags' => [], 'seo_provider' => 'yoast'];
            }
            $nonce = $request->header(WordPressConnectorClient::HEADER_NONCE)[0] ?? '';
            $time = now()->timestamp;
            $signature = hash_hmac('sha256', implode("\n", [(string) $time, $nonce, hash('sha256', (new WordPressConnectorCanonicalJson)->encode($data))]), self::SECRET);

            return Http::response(['data' => $data, 'meta' => ['server_time' => $time, 'request_nonce' => $nonce, 'signature' => $signature]]);
        });
    }
}
