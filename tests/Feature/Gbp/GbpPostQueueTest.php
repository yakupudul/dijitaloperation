<?php

namespace Tests\Feature\Gbp;

use App\Ai\Agents\GbpPostQueueAgent;
use App\Enums\CustomerStatus;
use App\Livewire\Operator\Gbp\PostPlanPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpQueuedPost;
use App\Models\Page;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\GbpPostQueue;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ADR-078 otomatik İşletme Profili gönderileri: rules pick page × angle slots from the brand's site (no repeats, other
 * languages and noindex pages left out), AI texts are checked (contact data, similarity), the Admin approves in bulk
 * and each post goes out on its day through the ADR-073 write with the page's image, after a last check.
 */
final class GbpPostQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $location;

    private DigitalAsset $site;

    /** @var array<string, Page> */
    private array $pages = [];

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 08:00', 'Europe/Istanbul'));
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.anthropic.api_key' => 'test-anthropic-value', 'moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Panorama Ankara', 'sector_id' => $dental->id]);
        $this->location = $this->profile('Panorama Çankaya', 'accounts/11/locations/22');

        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'panorama.test', 'domain' => 'panorama.test', 'primary_url' => 'https://panorama.test/']);
        $text = str_repeat('İmplant tedavisinde önce muayene ve görüntüleme yapılır, kemik yapısı değerlendirilir ve tedavi planı oluşturulur. ', 12);
        foreach ([
            'implant' => ['/implant/', 'Diş İmplantı', 'hizmet', 'tr', true, 101],
            'zirkonyum' => ['/zirkonyum/', 'Zirkonyum Kaplama', 'hizmet', 'tr', true, 102],
            'ortodonti' => ['/ortodonti/', 'Ortodonti', 'hizmet', null, true, null],
            'blog' => ['/blog/implant-sonrasi/', 'İmplant sonrası bakım', 'blog', 'tr', true, null],
            'english' => ['/en/implant/', 'Dental Implant', 'hizmet', null, true, null],
            'noindex' => ['/kampanya/', 'Kampanya', 'hizmet', 'tr', false, null],
        ] as $key => [$path, $title, $category, $language, $indexable, $wpId]) {
            $this->pages[$key] = Page::query()->create(['website_asset_id' => $this->site->id, 'url' => 'https://panorama.test'.$path, 'url_hash' => hash('sha256', $path),
                'path' => $path, 'title' => $title, 'category' => $category, 'language' => $language, 'is_indexable' => $indexable, 'content_text' => $text,
                'word_count' => 300, 'wp_post_id' => $wpId, 'changed_at' => now()->subMonths(3), 'created_at' => now()->subMonths(6)]);
        }
        $snapshot = fn (string $type, string $id, ?string $featured, ?string $permalink): bool => DB::table('website_cms_object_snapshot')->insert([
            'digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'object_type' => $type, 'object_id' => $id, 'featured_media_id' => $featured, 'permalink' => $permalink,
            'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $type.$id),
        ]);
        $snapshot('page', '101', '900', 'https://panorama.test/implant/');
        $snapshot('attachment', '900', null, 'https://panorama.test/wp-content/uploads/implant.jpg');

        Http::fake(function (Request $request) {
            $this->calls[] = [$request->method(), $request->url(), $request->data()];

            return Http::response(['name' => 'accounts/11/locations/22/localPosts/'.count($this->calls)]);
        });
    }

    private function profile(string $name, string $externalId): DigitalAsset
    {
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'name' => $name]);
        $integration = CoreIntegration::query()->where('provider', 'google')->first() ?? tap(CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]), function (CoreIntegration $google): void {
            CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $google->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret']]);
            CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $google->id, 'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r'], 'expires_at' => now()->addYear()]);
        });
        $resource = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => 'google_business_profile',
            'external_id' => $externalId, 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        return $asset;
    }

    /** @param  array<int, string>  $texts  slot => text */
    private function fakeTexts(array $texts): void
    {
        GbpPostQueueAgent::fake([['posts' => array_map(fn (int $slot, string $text): array => ['slot' => $slot, 'text' => $text, 'action_type' => 'BOOK'], array_keys($texts), $texts)]]);
    }

    private static function text(string $topic): string
    {
        return $topic.' '.str_repeat('Kliniğimizde '.$topic.' öncesinde ayrıntılı muayene yapılır, uygun yöntem birlikte planlanır ve süreç adım adım anlatılır. ', 3);
    }

    public function test_slots_use_each_page_once_in_rule_order_and_leave_out_other_languages_and_noindex_pages(): void
    {
        $slots = app(GbpPostQueue::class)->slots($this->location, app(GbpPostQueue::class)->emptyDays($this->location));

        $this->assertSame([$this->pages['implant']->id, $this->pages['zirkonyum']->id, $this->pages['blog']->id, $this->pages['ortodonti']->id], array_map(fn (array $s): int => $s['page']->id, $slots));
        $this->assertSame(['tanim', 'tanim', 'ozet', 'tanim'], array_column($slots, 'angle'));
        $this->assertSame(['2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10'], array_column($slots, 'day'));
    }

    public function test_fill_keeps_valid_texts_with_the_page_image_and_drops_contact_data_and_near_copies(): void
    {
        $this->fakeTexts([
            1 => self::text('implant'),
            2 => 'Zirkonyum kaplama için hemen arayın: 0312 555 55 55. '.self::text('zirkonyum'),
            3 => self::text('implant'),
            4 => 'Ortodonti tedavisi çapraşık dişleri ve kapanış bozukluklarını düzeltir. Şeffaf plak ya da braket seçeneği yaşa, dişlerin durumuna ve günlük alışkanlıklara göre belirlenir. Tedavi boyunca düzenli kontroller yapılır, ağız hijyeni için fırçalama ve ara yüz temizliği gösterilir. Bitişte kalıcı pekiştirme aparatı ile sonuç korunur.',
        ]);

        $result = app(GbpPostQueue::class)->fill($this->location->fresh());

        $this->assertSame(['status' => 'ready', 'added' => 2, 'empty' => 28], $result);
        $rows = GbpQueuedPost::query()->orderBy('publish_on')->get();
        $this->assertSame(['2026-10-07', '2026-10-10'], $rows->pluck('publish_on')->map(fn ($d): string => substr((string) $d, 0, 10))->all());
        $this->assertSame('https://panorama.test/wp-content/uploads/implant.jpg', $rows[0]->image_url);
        $this->assertNull($rows[1]->image_url);
        $this->assertSame([GbpQueuedPost::DRAFT, GbpQueuedPost::DRAFT], $rows->pluck('status')->all());
        $this->assertSame('BOOK', $rows[0]->action_type);

        // Used pages wait 21 days (dropped texts leave their page free); the other location takes a different angle.
        $this->assertSame([$this->pages['zirkonyum']->id, $this->pages['blog']->id], array_map(fn (array $s): int => $s['page']->id, app(GbpPostQueue::class)->slots($this->location, app(GbpPostQueue::class)->emptyDays($this->location))));
        $sibling = $this->profile('Panorama Keçiören', 'accounts/11/locations/33');
        $slots = app(GbpPostQueue::class)->slots($sibling, app(GbpPostQueue::class)->emptyDays($sibling));
        $this->assertSame('kimler', collect($slots)->firstWhere('page.id', $this->pages['implant']->id)['angle']);
    }

    public function test_bulk_approval_publishes_each_post_on_its_day_after_a_last_check(): void
    {
        $queue = app(GbpPostQueue::class);
        $make = fn (string $day, string $page, string $status = GbpQueuedPost::DRAFT): GbpQueuedPost => GbpQueuedPost::query()->create([
            'digital_asset_id' => $this->location->id, 'brand_id' => $this->brand->id, 'page_id' => $this->pages[$page]->id, 'angle' => 'tanim',
            'publish_on' => $day, 'summary' => self::text($page), 'url' => $this->pages[$page]->url, 'action_type' => 'BOOK',
            'image_url' => $page === 'implant' ? 'https://panorama.test/wp-content/uploads/implant.jpg' : null, 'status' => $status]);
        $first = $make('2026-10-07', 'implant');
        $second = $make('2026-10-08', 'zirkonyum');
        $unapproved = $make('2026-10-05', 'blog');

        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole(Roles::TEAM_MEMBER);
        Livewire::actingAs($operator)->test(PostPlanPage::class)->call('approveAll')->assertForbidden();
        Livewire::actingAs($this->admin)->test(PostPlanPage::class)->assertSee('Tümünü onayla (2)')->call('approveAll')->assertSee('2 gönderi onaylandı');
        $this->assertSame(GbpQueuedPost::APPROVED, $first->fresh()->status);

        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00', 'Europe/Istanbul'));
        $this->assertSame(['published' => 0, 'skipped' => 0, 'failed' => 0, 'expired' => 1], $queue->publishDue(), 'not before 10:00; yesterday’s unapproved draft expires');
        $this->assertSame(GbpQueuedPost::EXPIRED, $unapproved->fresh()->status);

        $this->travelTo(CarbonImmutable::parse('2026-10-07 11:00', 'Europe/Istanbul'));
        $this->assertSame(1, $queue->publishDue()['published']);
        $this->assertSame(0, $queue->publishDue()['published'], 'a post is sent once');
        $action = ExternalWriteAction::query()->where('action', ExternalWriteAction::ACTION_LOCAL_POST)->sole();
        $this->assertSame('succeeded', $action->status, (string) $action->error);
        $this->assertSame($first->id, (int) $action->request_payload['queue_id']);
        $post = collect($this->calls)->first(fn (array $c): bool => $c[0] === 'POST' && str_contains($c[1], 'localPosts'));
        $this->assertSame([['mediaFormat' => 'PHOTO', 'sourceUrl' => 'https://panorama.test/wp-content/uploads/implant.jpg']], $post[2]['media']);
        $this->assertSame(['actionType' => 'BOOK', 'url' => 'https://panorama.test/implant/'], $post[2]['callToAction']);
        $this->assertSame($action->id, (int) $first->fresh()->external_write_action_id);

        // Next day: the page went noindex → skipped, nothing sent.
        $this->pages['zirkonyum']->forceFill(['is_indexable' => false])->save();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 11:00', 'Europe/Istanbul'));
        $this->assertSame(1, $queue->publishDue()['skipped']);
        $this->assertSame('Sayfa sitede yok ya da dizine kapalı.', $second->fresh()->note);
        $this->assertSame(1, ExternalWriteAction::query()->count());
    }

    public function test_a_manual_post_that_day_wins_and_a_failed_post_can_be_retried(): void
    {
        $queue = app(GbpPostQueue::class);
        $post = GbpQueuedPost::query()->create(['digital_asset_id' => $this->location->id, 'brand_id' => $this->brand->id, 'page_id' => $this->pages['implant']->id,
            'angle' => 'tanim', 'publish_on' => '2026-10-06', 'summary' => self::text('implant'), 'url' => $this->pages['implant']->url, 'status' => GbpQueuedPost::APPROVED, 'approved_by' => $this->admin->id]);
        app(ExternalWriteService::class)->requestLocalPost($this->admin, $this->location, ['summary' => 'Elle yazılan bugünkü gönderi metni.']);

        $this->travelTo(CarbonImmutable::parse('2026-10-06 11:00', 'Europe/Istanbul'));
        $this->assertSame(1, $queue->publishDue()['skipped']);
        $this->assertSame('Bugün elle gönderi var.', $post->fresh()->note);

        $failed = GbpQueuedPost::query()->create(['digital_asset_id' => $this->location->id, 'brand_id' => $this->brand->id, 'page_id' => $this->pages['blog']->id,
            'angle' => 'ozet', 'publish_on' => '2026-10-05', 'summary' => self::text('blog'), 'url' => $this->pages['blog']->url, 'status' => GbpQueuedPost::FAILED, 'note' => 'API kapalı']);
        Livewire::actingAs($this->admin)->test(PostPlanPage::class, ['location' => $this->location->id])->assertSee('Tekrar dene')->call('retry', $failed->id);
        $this->assertSame([GbpQueuedPost::APPROVED, '2026-10-06'], [$failed->fresh()->status, substr((string) $failed->fresh()->publish_on, 0, 10)]);
    }
}
