<?php

namespace Tests\Feature\SiteFixes;

use App\Ai\Agents\SiteFixes\InternalLinkAgent;
use App\Ai\Agents\SiteFixes\PageWriterAgent;
use App\Ai\Agents\SiteFixes\SiteFixValuesAgent;
use App\Enums\Collection\CollectionRunStatus;
use App\Livewire\Operator\Website\SiteFixesPanel;
use App\Models\Brand;
use App\Models\Collection\CollectionRun;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\IntelligenceCore\IntelligencePageIdentity;
use App\Models\IntelligenceProjection\WebsiteIntelligenceProjectionRun;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\SiteFixItem;
use App\Models\User;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\SiteFixes\SiteFixFinder;
use App\Services\SiteFixes\SiteFixVerification;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use MoxDop\Website\Discovery\PublicUrlSafety;
use Tests\TestCase;

/** ADR-070: site fixes are found from stored data, proposed by AI on click, approved by an Admin and undoable. */
final class SiteFixesTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'ssssssssssssssssssssssssssssssssssssssssssss';

    private User $admin;

    private User $member;

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
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas Diş']);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'status' => 'active', 'module_id' => 'website', 'name' => 'atlas.example']);
        $this->connection = CoreConnection::factory()->create([
            'digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://example.test/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.4.0'],
        ]);
        CoreConnectionCredential::factory()->create(['connection_id' => $this->connection->id, 'encrypted_payload' => ['client_id' => 'client-1', 'shared_secret' => self::SECRET]]);
        $this->app->instance(WordPressConnectorClient::class, new WordPressConnectorClient(new WordPressConnectorCanonicalJson, new PublicUrlSafety(fn (string $host): array => ['93.184.216.34'])));

        $this->page('/', ['document_head' => ['title' => 'Atlas Diş Kliniği | İzmir', 'meta_description' => str_repeat('Açıklama ', 15)], 'schema' => ['types' => ['WebSite']]], 10, 'page');
        $this->page('/implant/', ['document_head' => ['title' => 'İmplant', 'meta_description' => ''], 'content' => ['word_count' => 120]], 11, 'page');
        $this->page('/gizli/', ['document_head' => ['title' => 'Kanal tedavisi nasıl yapılır İzmir', 'meta_description' => str_repeat('Kanal ', 25), 'robots' => 'noindex,follow', 'canonical_hrefs' => ['https://example.test/']]], 12, 'page');
        $this->page('/eski-kampanya/', ['http' => ['status_code' => 404]], null);
        DB::table('website_cms_object_snapshot')->insert($this->object('attachment', 20, 'https://example.test/wp-content/uploads/implant.jpg', 'implant', ['mime_type' => 'image/jpeg', 'alt_text' => '', 'file' => '2026/09/implant-dis.jpg']));
    }

    public function test_rules_find_phase_one_to_three_problems_from_stored_data(): void
    {
        $result = app(SiteFixFinder::class)->find($this->site);
        $types = SiteFixItem::query()->pluck('type')->all();

        foreach (['seo_title', 'seo_description', 'alt_text', 'schema', 'noindex', 'canonical', 'redirect', 'content_update'] as $type) {
            $this->assertContains($type, $types, $type);
        }
        $this->assertSame(false, SiteFixItem::query()->where('type', 'noindex')->first()->value(), 'noindex is proposed to be switched off');
        $this->assertSame('/eski-kampanya/', SiteFixItem::query()->where('type', 'redirect')->value('url'));
        $this->assertSame('11', SiteFixItem::query()->where('type', 'content_update')->value('object_id'));
        $this->assertSame($result['found'], SiteFixItem::query()->count());

        // A second scan keeps rows; a fixed problem that is still open disappears.
        SiteFixItem::query()->where('type', 'alt_text')->update(['status' => 'applied']);
        app(SiteFixFinder::class)->find($this->site);
        $this->assertSame(1, SiteFixItem::query()->where('type', 'alt_text')->count());
    }

    public function test_ai_proposes_values_and_redirect_targets_must_be_real_pages(): void
    {
        $this->enableAi();
        app(SiteFixFinder::class)->find($this->site);
        $title = SiteFixItem::query()->where('type', 'seo_title')->where('object_id', '11')->firstOrFail();
        $redirect = SiteFixItem::query()->where('type', 'redirect')->firstOrFail();
        $schema = SiteFixItem::query()->where('type', 'schema')->firstOrFail();
        SiteFixValuesAgent::fake([['items' => [
            ['id' => $title->id, 'value' => 'İzmir İmplant Tedavisi | Atlas Diş', 'note' => 'Hizmet + şehir'],
            ['id' => $redirect->id, 'value' => 'https://example.test/uydurma/', 'note' => 'yanlış'],
            ['id' => $schema->id, 'value' => '{"@type":"Dentist","name":"Atlas Diş"}', 'note' => 'işletme'],
        ]]]);

        $this->actingAs($this->admin);
        Livewire::test(SiteFixesPanel::class, ['websiteId' => $this->site->id])->call('propose', 'values');

        $this->assertSame('İzmir İmplant Tedavisi | Atlas Diş', $title->fresh()->value());
        $this->assertSame('ai', $title->fresh()->proposed_by);
        $this->assertNull($redirect->fresh()->proposed, 'a target that is not a published page is refused');
        $this->assertSame('https://schema.org', json_decode((string) $schema->fresh()->value(), true)['@context']);
    }

    public function test_admin_applies_selected_fixes_and_undoes_them(): void
    {
        app(SiteFixFinder::class)->find($this->site);
        $title = SiteFixItem::query()->where('type', 'seo_title')->where('object_id', '11')->firstOrFail();
        $title->forceFill(['proposed' => ['value' => 'İzmir İmplant Tedavisi | Atlas Diş'], 'proposed_by' => 'operator'])->save();
        $noindex = SiteFixItem::query()->where('type', 'noindex')->firstOrFail();
        $this->fakeWordPress();

        $this->actingAs($this->member);
        Livewire::test(SiteFixesPanel::class, ['websiteId' => $this->site->id])->set('selected', [$title->id => true])->call('applySelected')->assertStatus(403);

        $this->actingAs($this->admin);
        Livewire::test(SiteFixesPanel::class, ['websiteId' => $this->site->id])
            ->assertSee('İzmir İmplant Tedavisi | Atlas Diş')
            ->set('selected', [$title->id => true, $noindex->id => true])
            ->call('applySelected')
            ->assertSee('Uygulandı');

        $action = ExternalWriteAction::query()->where('action', 'site_fix')->firstOrFail();
        $this->assertSame('succeeded', $action->status, (string) $action->error);
        $this->assertSame(['POST', 'https://example.test/wp-json/moxdop/v1/fixes'], [$this->sent[0][0], $this->sent[0][1]]);
        $changes = collect($this->sent[0][2]['changes'])->keyBy('type');
        $this->assertSame(['type' => 'seo_title', 'object_id' => 11, 'reference' => 'site-fix-'.$title->id, 'value' => 'İzmir İmplant Tedavisi | Atlas Diş'], $changes['seo_title']);
        $this->assertFalse($changes['noindex']['value']);
        $this->assertSame('applied', $title->fresh()->status);

        Livewire::test(SiteFixesPanel::class, ['websiteId' => $this->site->id])->call('undo', $action->id);
        $this->assertSame('undone', $action->fresh()->status, (string) $action->fresh()->error);
        $this->assertSame('https://example.test/wp-json/moxdop/v1/fixes/undo', $this->sent[1][1]);
        $this->assertCount(2, $this->sent[1][2]['change_ids']);
        $this->assertSame('undone', $title->fresh()->status);
    }

    public function test_applied_fixes_are_recrawled_and_marked_verified_or_still_on_the_site(): void
    {
        $this->site->forceFill(['primary_url' => 'https://example.test/', 'domain' => 'example.test'])->save();
        app(SiteFixFinder::class)->find($this->site);
        $title = SiteFixItem::query()->where('type', 'seo_title')->where('object_id', '11')->firstOrFail();
        $title->forceFill(['proposed' => ['value' => 'İzmir İmplant Tedavisi | Atlas Diş'], 'proposed_by' => 'operator'])->save();
        $noindex = SiteFixItem::query()->where('type', 'noindex')->firstOrFail();
        $this->fakeWordPress();

        $this->actingAs($this->admin);
        Livewire::test(SiteFixesPanel::class, ['websiteId' => $this->site->id])->set('selected', [$title->id => true, $noindex->id => true])->call('applySelected');
        $action = ExternalWriteAction::query()->where('action', 'site_fix')->firstOrFail();
        $this->assertSame('crawling', data_get($action->result, 'verification.state'), json_encode($action->result));
        $run = CollectionRun::query()->findOrFail((int) data_get($action->result, 'verification.run_id'));
        $this->assertEqualsCanonicalizing(['https://example.test/implant/', 'https://example.test/gizli/'], data_get($run->request_context, 'context.targeted_verification.urls'));
        $this->assertSame([WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL], $run->datasetRuns()->pluck('request_family_id')->unique()->values()->all());

        // The recrawl shows the new title; the noindex is still there (e.g. another plugin forces it).
        WebsitePageProfile::query()->where('preferred_url', 'https://example.test/implant/')->get()->each(function (WebsitePageProfile $profile): void {
            $profile->forceFill(['source_states' => array_replace_recursive($profile->source_states, ['website' => ['document_head' => ['title' => 'İzmir İmplant Tedavisi | Atlas Diş', 'meta_description' => str_repeat('İmplant ', 17)]]])])->save();
        });
        $run->forceFill(['status' => CollectionRunStatus::Completed, 'finished_at' => now()])->save();
        $this->assertSame(0, app(SiteFixVerification::class)->settle(), 'waits for the projection');
        $this->travel(4)->minutes();
        $this->assertSame(1, app(SiteFixVerification::class)->settle());

        $this->assertSame('verified', data_get($title->fresh()->current, 'verification.state'));
        $this->assertSame('still_present', data_get($noindex->fresh()->current, 'verification.state'));
        $this->assertSame(['done', 1, 1], [data_get($action->fresh()->result, 'verification.state'), data_get($action->fresh()->result, 'verification.verified'), data_get($action->fresh()->result, 'verification.still_present')]);
        Livewire::test(SiteFixesPanel::class, ['websiteId' => $this->site->id])->set('status', 'applied')->set('phase', 'all')->assertSee('Sitede doğrulandı')->assertSee('Sitede hâlâ görünüyor');
    }

    public function test_page_text_goes_to_a_draft_copy_first_and_live_only_after_a_second_approval(): void
    {
        $this->enableAi();
        app(SiteFixFinder::class)->find($this->site);
        $item = SiteFixItem::query()->where('type', 'content_update')->firstOrFail();
        $this->fakeWordPress();
        PageWriterAgent::fake([['title' => 'İzmir İmplant Tedavisi', 'html' => '<h2 onclick="x()">İmplant nedir?</h2><p>Metin <a href="javascript:alert(1)">bağlantı</a></p><script>bad()</script>', 'summary' => 'Genişletildi.']]);

        $this->actingAs($this->admin);
        Livewire::test(SiteFixesPanel::class, ['websiteId' => $this->site->id, 'phase' => '3'])->set('phase', '3')->call('writePage', $item->id);
        $html = (string) data_get($item->fresh()->proposed, 'value.html');
        $this->assertStringContainsString('<h2>İmplant nedir?</h2>', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<script', $html);
        PageWriterAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'Mevcut implant sayfası metni'));

        Livewire::test(SiteFixesPanel::class, ['websiteId' => $this->site->id])->set('phase', '3')->call('applyContent', $item->id)->assertSee('Önce yeni sürümü');
        Livewire::test(SiteFixesPanel::class, ['websiteId' => $this->site->id])->set('phase', '3')->call('sendDraft', $item->id);
        $this->assertSame('drafted', $item->fresh()->status);
        $this->assertSame('https://example.test/wp-json/moxdop/v1/content-drafts', collect($this->sent)->last()[1]);

        Livewire::test(SiteFixesPanel::class, ['websiteId' => $this->site->id])->set('phase', '3')->call('applyContent', $item->id);
        $this->assertSame('applied', $item->fresh()->status);
        $this->assertSame(['draft_id' => 77], collect($this->sent)->last()[2]);
        $apply = ExternalWriteAction::query()->where('action', 'content_apply')->firstOrFail();
        $this->assertTrue($apply->isUndoable());
    }

    public function test_internal_links_need_an_anchor_that_is_already_in_the_page_text(): void
    {
        $this->enableAi();
        $this->fakeWordPress();
        InternalLinkAgent::fake([['links' => [
            ['source_object_id' => 11, 'target_url' => 'https://example.test/gizli/', 'anchor' => 'implant sayfası', 'reason' => 'ilgili'],
            ['source_object_id' => 11, 'target_url' => 'https://example.test/', 'anchor' => 'metinde olmayan ifade', 'reason' => 'x'],
            ['source_object_id' => 11, 'target_url' => 'https://example.test/implant/', 'anchor' => 'implant', 'reason' => 'kendine bağlantı'],
        ]]]);

        $this->actingAs($this->admin);
        Livewire::test(SiteFixesPanel::class, ['websiteId' => $this->site->id])->call('propose', 'links');

        $links = SiteFixItem::query()->where('type', 'internal_link')->get();
        $this->assertCount(1, $links);
        $this->assertSame(['anchor' => 'implant sayfası', 'url' => 'https://example.test/gizli/'], $links->first()->value());
        $this->assertSame('11', $links->first()->object_id);
    }

    public function test_writes_need_plugin_1_4_and_the_plugin_keeps_fixes_off_by_default(): void
    {
        app(SiteFixFinder::class)->find($this->site);
        $title = SiteFixItem::query()->where('type', 'seo_title')->firstOrFail();
        $title->forceFill(['proposed' => ['value' => 'Yeni başlık burada yeterince uzun'], 'proposed_by' => 'operator'])->save();
        $this->connection->forceFill(['config' => array_merge($this->connection->config, ['plugin_version' => '1.3.0'])])->save();
        Http::fake();

        $this->actingAs($this->admin);
        Livewire::test(SiteFixesPanel::class, ['websiteId' => $this->site->id])->set('selected', [$title->id => true])->call('applySelected')->assertSee('en az 1.4.0');
        Http::assertNothingSent();

        $plugin = file_get_contents(base_path('connectors/wordpress/moxdop-connector/includes/class-moxdop-connector-fixes.php'));
        $this->assertStringContainsString("get_option('moxdop_connector_allow_fixes', '0') === '1'", $plugin);
        $this->assertStringContainsString("get_option('moxdop_connector_allow_content', '0') === '1'", $plugin);
        $this->assertStringContainsString("'error' => 'changed_since'", $plugin, 'undo never overwrites a value changed after MoxDOP');
        $controller = file_get_contents(base_path('connectors/wordpress/moxdop-connector/includes/class-moxdop-connector-rest-controller.php'));
        $this->assertStringContainsString('return $this->signed($management->health(), $request);', $controller, 'management responses are signed');
    }

    private function fakeWordPress(): void
    {
        Http::fake(function (Request $request) {
            $body = json_decode($request->body(), true);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->sent[] = [$request->method(), $request->url(), $body];
            $data = match (true) {
                str_ends_with($path, '/fixes') => ['schema_version' => 1, 'results' => array_map(fn (array $c, int $i): array => ['ok' => true, 'type' => $c['type'], 'change_id' => 'chg-'.$i, 'before' => 'eski', 'after' => $c['value']], $body['changes'], array_keys($body['changes']))],
                str_ends_with($path, '/fixes/undo') => ['schema_version' => 1, 'results' => array_map(fn (string $id): array => ['change_id' => $id, 'ok' => true], $body['change_ids'])],
                str_ends_with($path, '/content-drafts') => ['schema_version' => 1, 'post_id' => 77, 'update_of' => 11, 'status' => 'draft', 'edit_url' => 'https://example.test/wp-admin/post.php?post=77&action=edit', 'preview_url' => ''],
                str_ends_with($path, '/content-drafts/apply') => ['schema_version' => 1, 'ok' => true, 'post_id' => 11, 'change_id' => 'chg-content', 'url' => 'https://example.test/implant/'],
                str_ends_with($path, '/snapshot') => ['schema_version' => 1, 'records' => [
                    ['object_id' => '11', 'content_rendered' => '<p>Mevcut implant sayfası metni.</p>'],
                    ['object_id' => '12', 'content_rendered' => '<p>Kanal tedavisi hakkında bilgiler.</p>'],
                    ['object_id' => '10', 'content_rendered' => '<p>Atlas Diş ana sayfa.</p>'],
                ], 'page' => 1, 'per_page' => 50, 'total' => 3, 'has_more' => false],
                default => ['schema_version' => 1],
            };
            $nonce = $request->header(WordPressConnectorClient::HEADER_NONCE)[0] ?? '';
            $time = now()->timestamp;
            $signature = hash_hmac('sha256', implode("\n", [(string) $time, $nonce, hash('sha256', (new WordPressConnectorCanonicalJson)->encode($data))]), self::SECRET);

            return Http::response(['data' => $data, 'meta' => ['server_time' => $time, 'request_nonce' => $nonce, 'signature' => $signature]]);
        });
    }

    private function enableAi(): void
    {
        config(['moxdop.anthropic.api_key' => 'sk-ant-test']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
    }

    /** @param array<string, mixed> $facts */
    private function page(string $path, array $facts, ?int $objectId, string $type = 'page'): void
    {
        $url = 'https://example.test'.$path;
        $identity = IntelligencePageIdentity::query()->create([
            'uuid' => (string) Str::uuid(), 'website_asset_id' => $this->site->id, 'identity_hash' => hash('sha256', $this->site->id.':'.$url), 'preferred_url' => $url,
            'preferred_url_hash' => hash('sha256', $url), 'scheme' => 'https', 'host' => 'example.test', 'path' => $path,
            'resolution_status' => 'resolved', 'normalization_version' => 'v1', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $projection = WebsiteIntelligenceProjectionRun::query()->create([
            'uuid' => (string) Str::uuid(), 'website_asset_id' => $this->site->id, 'trigger' => 'test', 'status' => 'completed',
            'schema_version' => 1, 'intelligence_registry_version' => 1, 'period_start' => now()->subDays(90), 'period_end' => now()->subDay(),
        ]);
        WebsitePageProfile::query()->create([
            'website_asset_id' => $this->site->id, 'page_identity_id' => $identity->id, 'projection_run_id' => $projection->id,
            'preferred_url' => $url, 'profile_version' => 1, 'projected_at' => now(), 'last_observed_at' => now(),
            'source_states' => ['website' => array_replace_recursive(['url' => $url, 'http' => ['status_code' => 200], 'content' => ['word_count' => 600]], $facts)],
        ]);
        if ($objectId !== null) {
            DB::table('website_cms_object_snapshot')->insert($this->object($type, $objectId, $url, trim($path, '/') ?: 'Ana sayfa'));
        }
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    private function object(string $type, int $id, string $url, string $title, array $metadata = []): array
    {
        return [
            'digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'object_type' => $type, 'object_id' => (string) $id, 'status' => $type === 'attachment' ? 'inherit' : 'publish',
            'permalink' => $url, 'title' => $title, 'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', $type.$id), 'metadata' => json_encode($metadata), 'created_at' => now(), 'updated_at' => now(),
        ];
    }
}
