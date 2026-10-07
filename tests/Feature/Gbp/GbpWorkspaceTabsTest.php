<?php

namespace Tests\Feature\Gbp;

use App\Livewire\Demo\Gbp\OverviewPage;
use App\Livewire\Operator\Gbp\Desk\ReviewsPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpQueuedPost;
use App\Models\Run;
use App\Models\ServiceCategory;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\ExternalWrites\GbpWriter;
use App\Services\Gbp\GbpPostQueue;
use App\Services\Gbp\GbpScreen;
use App\Services\Gbp\GbpSuggestions;
use App\Services\Site\Analysis\SiteRange;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Faz 7 İşletme Profili screen: Genel Bakış numbers, Yapılacaklar (standards → suggestions), Yorumlar (reply + undo),
 * Gönderiler (now / scheduled / cancel, compliance), Analiz, Ayarlar.
 */
final class GbpWorkspaceTabsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private DigitalAsset $asset;

    private CoreExternalResource $resource;

    private int $runId;

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private array $calls = [];

    /** How many localPosts POSTs answer Google's "Internal error encountered." (HTTP 500) before one succeeds. */
    private int $postFailures = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->member = User::factory()->create(['is_active' => true]);
        $this->member->assignRole(Roles::TEAM_MEMBER);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas']);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'name' => 'Atlas Çankaya']);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r'], 'expires_at' => now()->addHour()]);
        $this->resource = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => 'google_business_profile',
            'external_id' => 'locations/22', 'parent_external_id' => 'accounts/11', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        $binding = CoreAssetBinding::factory()->create(['digital_asset_id' => $this->asset->id, 'external_resource_id' => $this->resource->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $this->runId = (int) Run::query()->create(['digital_asset_id' => $this->asset->id, 'core_asset_binding_id' => $binding->id, 'module_id' => 'google-business-profile', 'status' => 'completed',
            'started_at' => now(), 'finished_at' => now(), 'metadata' => ['datasets' => ['gbp_reviews' => ['status' => 'available', 'rows' => 3]]]])->id;
        DB::table('gbp_location_snapshots')->insert(['digital_asset_id' => $this->asset->id, 'external_resource_id' => $this->resource->id, 'run_id' => $this->runId, 'location_name' => 'locations/22',
            'title' => 'Atlas Çankaya', 'place_id' => 'ChIJ-atlas', 'primary_category' => 'Diş kliniği', 'website_uri' => 'https://atlas.test',
            'phone_numbers' => json_encode(['primaryPhone' => '0312 000 00 00']), 'regular_hours' => json_encode(['periods' => [['openDay' => 'MONDAY'], ['openDay' => 'TUESDAY']]]),
            'profile' => json_encode(['description' => 'Kısa açıklama']), 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['r-old-bad', 'ONE', 'Çok bekledim', 5, null], ['r-new', 'FIVE', 'Süper', 1, null], ['r-replied', 'FOUR', 'İyi', 10, ['comment' => 'Teşekkürler', 'updateTime' => now()->subDays(9)->toIso8601String()]]] as [$id, $stars, $comment, $days, $reply]) {
            DB::table('gbp_reviews')->insert(['external_resource_id' => $this->resource->id, 'location_name' => 'locations/22', 'run_id' => $this->runId, 'review_id' => $id,
                'star_rating' => $stars, 'comment' => $comment, 'create_time' => now()->subDays($days), 'update_time' => now()->subDays($days), 'reviewer' => json_encode(['displayName' => 'Müşteri '.$id]),
                'review_reply' => $reply !== null ? json_encode($reply) : null, 'raw_payload' => '{}', 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        Http::fake(function (Request $request) {
            $this->calls[] = [$request->method(), $request->url(), $request->data()];
            if (str_contains($request->url(), 'mybusinessaccountmanagement.googleapis.com/v1/accounts')) {
                return Http::response(['accounts' => [['name' => 'accounts/7'], ['name' => 'accounts/11']]]);
            }
            if (str_contains($request->url(), 'mybusinessbusinessinformation.googleapis.com/v1/accounts/')) {
                return Http::response(['locations' => str_contains($request->url(), 'accounts/11/') ? [['name' => 'locations/22']] : [['name' => 'locations/5']]]);
            }

            if (str_contains($request->url(), 'localPosts') && $request->method() === 'POST' && $this->postFailures > 0) {
                $this->postFailures--;

                return Http::response(['error' => ['code' => 500, 'message' => 'Internal error encountered.', 'status' => 'INTERNAL']], 500);
            }

            return str_contains($request->url(), 'localPosts') && $request->method() === 'POST'
                ? Http::response(['name' => 'accounts/11/locations/22/localPosts/555', 'searchUrl' => 'https://g.co/post'])
                : Http::response(['comment' => 'ok']);
        });
    }

    private function page(string $tab, ?User $user = null): Testable
    {
        return Livewire::actingAs($user ?? $this->admin)->test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => $tab]);
    }

    private function reviewId(string $googleId): int
    {
        return (int) DB::table('gbp_reviews')->where('review_id', $googleId)->value('id');
    }

    public function test_reviews_tab_is_the_review_desk_for_this_profile_only(): void
    {
        $this->page('reviews')->assertSeeLivewire(ReviewsPage::class);

        Livewire::actingAs($this->admin)->test(ReviewsPage::class, ['asset' => $this->asset->id])
            ->assertSeeInOrder(['Müşteri r-new', 'Müşteri r-old-bad'])->assertDontSee('Müşteri r-replied')
            ->call('setSort', 'eski')->assertSeeInOrder(['r-old-bad', 'r-new'])
            ->assertSee('AI taslağı')->assertDontSee('Tüm işletmeler')
            ->call('setLocation', null)->assertSet('location', $this->asset->id)
            ->call('setStatus', 'tumu')->assertSee('Müşteri r-replied')->assertSee('Teşekkürler');
    }

    public function test_admin_sends_a_reply_to_google_and_can_undo_it(): void
    {
        $this->page('reviews')->call('publishReply', $this->reviewId('r-old-bad'), 'Geri bildiriminiz için teşekkürler, sizi arayacağız.');

        $this->assertSame(['PUT', 'https://mybusiness.googleapis.com/v4/accounts/11/locations/22/reviews/r-old-bad/reply'], array_slice(end($this->calls), 0, 2));
        $action = ExternalWriteAction::query()->sole();
        $this->assertSame('succeeded', $action->status);
        $this->assertSame($this->admin->id, (int) $action->requested_by, 'the write is recorded with its approver');
        Livewire::actingAs($this->admin)->test(ReviewsPage::class, ['asset' => $this->asset->id])
            ->call('setStatus', 'yanitli')->assertSee('sizi arayacağız')->assertSee('Geri al')
            ->call('undoReply', $action->id);
        $this->assertSame('undone', $action->fresh()->status);
        $this->assertSame('DELETE', end($this->calls)[0]);
        $this->assertNull(DB::table('gbp_reviews')->where('review_id', 'r-old-bad')->value('review_reply'));
    }

    public function test_team_member_cannot_send_replies_or_publish_posts(): void
    {
        Livewire::actingAs($this->member)->test(ReviewsPage::class, ['asset' => $this->asset->id])->assertDontSee('Kendim yazayım');
        $this->page('reviews', $this->member)
            ->call('publishReply', $this->reviewId('r-new'), 'Teşekkürler')->assertForbidden();
        $this->page('posts', $this->member)->call('startPost')->set('post.body', 'Kış bakımı')->call('publishPost')->assertForbidden();
        $this->assertSame(0, ExternalWriteAction::query()->count());
        Http::assertNothingSent();
    }

    public function test_posts_tab_shows_collected_google_posts_and_the_weekly_rhythm(): void
    {
        DB::table('gbp_posts')->insert(['external_resource_id' => $this->resource->id, 'run_id' => $this->runId, 'location_name' => 'locations/22', 'post_name' => 'accounts/11/locations/22/localPosts/1',
            'summary' => 'Yaz kampanyası başladı', 'state' => 'LIVE', 'create_time' => now()->subDays(23), 'raw_payload' => json_encode(['searchUrl' => 'https://g.co/yaz']),
            'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->page('posts')->assertSee('Yaz kampanyası başladı')->assertSee('Google’da yayında')->assertSee('Son gönderi 23 gün önce; haftada 1 önerilir.');
    }

    public function test_post_published_now_goes_to_google_and_can_be_undone(): void
    {
        $page = $this->page('posts')->call('startPost')->set('post.body', 'Kış aylarında diş hassasiyeti artabilir; kontrol için randevu alın.')
            ->set('post.url', 'https://atlas.test/hassasiyet/')->set('post.action_type', 'BOOK')->call('publishPost')->assertHasNoErrors();

        $action = ExternalWriteAction::query()->sole();
        $this->assertSame('succeeded', $action->status);
        [$method, $url, $body] = end($this->calls);
        $this->assertSame(['POST', 'https://mybusiness.googleapis.com/v4/accounts/11/locations/22/localPosts'], [$method, $url]);
        $this->assertSame(['actionType' => 'BOOK', 'url' => 'https://atlas.test/hassasiyet/'], $body['callToAction']);
        DB::table('gbp_posts')->insert(['external_resource_id' => $this->resource->id, 'run_id' => $this->runId, 'location_name' => 'locations/22', 'post_name' => 'accounts/11/locations/22/localPosts/555',
            'summary' => 'Kış aylarında diş hassasiyeti artabilir; kontrol için randevu alın.', 'state' => 'LIVE', 'create_time' => now(), 'raw_payload' => json_encode([]),
            'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $html = $page->call('setTab', 'posts')->assertSee('Yayınlandı')->assertSee('Geri al')->assertDontSee('Google’da yayında')->html();
        $this->assertSame(1, substr_count($html, 'Kış aylarında diş hassasiyeti artabilir'), 'the post collected back from Google is the same post, listed once');

        $page->call('undoWrite', $action->id);
        $this->assertSame('undone', $action->fresh()->status);
    }

    public function test_a_location_without_its_account_finds_it_before_the_post_goes_out(): void
    {
        $this->resource->forceFill(['parent_external_id' => null])->save();
        $this->page('posts')->call('startPost')->set('post.body', 'Kış aylarında diş hassasiyeti artabilir; kontrol için randevu alın.')->call('publishPost')->assertHasNoErrors();

        $this->assertSame('succeeded', ExternalWriteAction::query()->sole()->status);
        $this->assertSame('accounts/11', $this->resource->fresh()->parent_external_id, 'remembered for the next writes');
        [, $url] = end($this->calls);
        $this->assertSame('https://mybusiness.googleapis.com/v4/accounts/11/locations/22/localPosts', $url);

        $this->resource->fresh()->forceFill(['parent_external_id' => null, 'external_id' => 'locations/99'])->save();
        try {
            app(GbpWriter::class)->location($this->asset->id);
            $this->fail('a location in none of the accounts cannot be written to');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('kullanıcısının hesaplarında bulunamadı', $exception->getMessage());
        }
        $this->assertSame(1, ExternalWriteAction::query()->count());
    }

    public function test_post_whose_photo_google_cannot_take_goes_out_without_it(): void
    {
        $writes = app(ExternalWriteService::class);
        // Google answers "Internal error encountered." once: the post goes again without the photo and says so.
        $this->postFailures = 1;
        $action = $writes->requestLocalPost($this->admin, $this->asset, ['summary' => 'Ankara’da diş hekimi arıyorsanız muayene için randevu alın.', 'url' => 'https://atlas.test/dis-hekimi/', 'image_url' => 'https://atlas.test/wp-content/uploads/kapak.jpg']);
        $this->assertSame('succeeded', $action->fresh()->status);
        $posts = array_values(array_filter($this->calls, fn (array $c): bool => $c[0] === 'POST' && str_contains($c[1], 'localPosts')));
        $this->assertCount(2, $posts);
        $this->assertSame('https://atlas.test/wp-content/uploads/kapak.jpg', $posts[0][2]['media'][0]['sourceUrl']);
        $this->assertArrayNotHasKey('media', $posts[1][2]);
        $this->assertStringContainsString('görselsiz yayımlandı', (string) data_get($action->fresh()->result, 'note'));

        // A WebP photo is never sent: Google takes only JPG / PNG.
        $this->calls = [];
        $webp = $writes->requestLocalPost($this->admin, $this->asset, ['summary' => 'Hafta sonu da açığız.', 'image_url' => 'https://atlas.test/wp-content/uploads/kapak.webp']);
        $this->assertSame('succeeded', $webp->fresh()->status);
        $this->assertArrayNotHasKey('media', end($this->calls)[2]);
        $this->assertStringContainsString('JPG / PNG', (string) data_get($webp->fresh()->result, 'note'));

        // Two internal errors in a row: the post fails with a Turkish reason, nothing half-sent.
        $this->postFailures = 2;
        $failed = $writes->requestLocalPost($this->admin, $this->asset, ['summary' => 'Kontrol randevunuzu planlayın.']);
        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertStringContainsString('iki deneme', (string) $failed->fresh()->error);
    }

    public function test_scheduled_post_waits_is_sent_when_due_and_can_be_cancelled(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00', 'Europe/Istanbul'));
        CoreIntegrationCredential::query()->whereNotNull('expires_at')->update(['expires_at' => now()->addYear()]);
        $page = $this->page('posts')->call('startPost')->set('post.body', 'Hafta sonu da açığız; randevunuzu önceden planlayın.')
            ->set('post.when', 'later')->set('post.publish_at', '2026-10-03T10:30')->call('publishPost')->assertHasNoErrors();

        $action = ExternalWriteAction::query()->sole();
        $this->assertSame('scheduled', $action->status);
        $this->assertSame('2026-10-03T07:30:00+00:00', $action->request_payload['publish_at']);
        Http::assertNothingSent();
        $page->call('setTab', 'posts')->assertSee('Zamanlandı')->assertSee('03.10.2026 10:30')->assertSee('İptal et');

        $this->artisan('moxdop:gbp:publish-scheduled')->assertSuccessful();
        $this->assertSame('scheduled', $action->fresh()->status, 'not due yet');

        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:31', 'Europe/Istanbul'));
        $this->artisan('moxdop:gbp:publish-scheduled')->assertSuccessful();
        $this->assertSame('succeeded', $action->fresh()->status);
        $this->assertSame('POST', end($this->calls)[0]);

        $second = $this->page('posts')->call('startPost')->set('post.body', 'Bayramda kapalıyız; acil durumlar için bizi arayın.')
            ->set('post.when', 'later')->set('post.publish_at', '2026-10-10T09:00')->call('publishPost');
        $scheduled = ExternalWriteAction::query()->where('status', 'scheduled')->sole();
        $second->call('cancelScheduled', $scheduled->id);
        $this->assertSame('cancelled', $scheduled->fresh()->status);
        $this->travelTo(CarbonImmutable::parse('2026-10-11 09:00', 'Europe/Istanbul'));
        $this->artisan('moxdop:gbp:publish-scheduled')->assertSuccessful();
        $this->assertSame('cancelled', $scheduled->fresh()->status);
    }

    public function test_post_text_breaking_sector_compliance_is_not_published(): void
    {
        $this->dentalSector();
        $this->page('posts')->call('startPost')->set('post.body', 'Ağrısız implant tedavisinde garantili sonuç.')->call('publishPost')
            ->assertHasErrors('post.body');
        $this->assertSame(0, ExternalWriteAction::query()->count());
    }

    public function test_failing_profile_standards_become_suggestions_with_approve_reject_snooze(): void
    {
        // Upcoming Cumhuriyet Bayramı without special hours; two opening days only.
        $this->travelTo(now()->setDate(2026, 10, 10));
        $page = $this->page('todo');

        $rows = Suggestion::query()->where('target_type', 'gbp')->where('target_id', $this->asset->id)->get();
        $this->assertGreaterThanOrEqual(2, $rows->count());
        $this->assertLessThanOrEqual(GbpSuggestions::MAX_STANDARDS, $rows->count());
        $this->assertTrue($rows->every(fn (Suggestion $s): bool => $s->channel === 'maps' && $s->action_type === 'gbp_standard' && $s->brand_id === $this->asset->brand_id));
        $hours = $rows->firstWhere('decision_key', 'gbp:'.$this->asset->id.':standard:gbp:hours');
        $this->assertNotNull($hours);
        $page->assertSee('Çalışma ve özel gün saatleri')->assertSee('Cumhuriyet Bayramı')->assertSee('Onayla')->assertSee('Reddet')->assertSee('Ertele');

        $page->call('approveSuggestion', $hours->id);
        $this->assertSame(Suggestion::APPROVED, $hours->fresh()->status, 'approved: the operator still has to change it on Google');
        $this->assertNull($hours->fresh()->baseline);
        $page->call('setTab', 'todo')->assertSee('Uygulanacaklar · 1')->assertSee('Uygulandı');
        $page->call('markApplied', $hours->id);
        $this->assertSame(Suggestion::APPLIED, $hours->fresh()->status);
        $this->assertNotNull($hours->fresh()->baseline, 'baseline on Uygulandı');
        $other = $rows->where('id', '!=', $hours->id)->values();
        $page->call('snoozeSuggestion', $other[0]->id);
        $this->assertSame(Suggestion::SNOOZED, $other[0]->fresh()->status);
        $page->call('dismissSuggestion', $other[0]->id);
        $this->assertSame(Suggestion::DISMISSED, $other[0]->fresh()->status);
        $page->call('setTab', 'todo')->assertDontSee('Çalışma ve özel gün saatleri');

        // A re-check keeps closed ones closed (same action) and does not duplicate rows.
        app(GbpSuggestions::class)->syncStandards($this->asset->fresh());
        $this->assertSame(Suggestion::APPLIED, $hours->fresh()->status);
        $this->assertSame($rows->count(), Suggestion::query()->where('target_id', $this->asset->id)->count());
        Http::assertNothingSent();
    }

    public function test_the_date_picker_range_drives_the_overview_numbers_and_the_analysis(): void
    {
        foreach (range(0, 55) as $day) {
            DB::table('gbp_performance_daily')->insert(['external_resource_id' => $this->resource->id, 'digital_asset_id' => $this->asset->id, 'run_id' => $this->runId, 'location_name' => 'locations/22',
                'reporting_date' => CarbonImmutable::parse('2026-09-20')->subDays($day)->toDateString(), 'metric' => 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'value' => $day < 7 ? 20 : 10,
                'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        $views = app(GbpScreen::class)->overview($this->asset, $this->resource->id, SiteRange::from(7))['views'];
        $this->assertSame(['current' => 140, 'previous' => 70, 'change_pct' => 100], $views);
        $year = app(GbpScreen::class)->overview($this->asset, $this->resource->id, SiteRange::from(7, null, null, SiteRange::COMPARE_YEAR))['views'];
        $this->assertSame(['current' => 140, 'previous' => null, 'change_pct' => null], $year, 'no data a year earlier');
        $this->assertCount(10, app(GbpScreen::class)->analysis($this->resource->id, SiteRange::from(28, '2026-09-01', '2026-09-10'))['daily']);
        $this->assertSame('2026-09-20', app(GbpScreen::class)->lastDay($this->resource->id)->toDateString());

        $this->page('overview')->assertSeeHtml('data-date-picker')->assertSee('20 Eyl 2026')
            ->call('setRange', 7)->assertSee('Son 7 gün')->assertSee('+100% karşılaştırma dönemine göre')
            ->call('setTab', 'analysis')->call('setRange', 28, '2026-09-01', '2026-09-10')->assertSet('days', 10)->assertSee('1 Eyl – 10 Eyl 2026')
            ->call('setTab', 'reviews')->assertDontSeeHtml('data-date-picker');
    }

    public function test_overview_shows_the_five_numbers_from_collected_data(): void
    {
        foreach (range(0, 55) as $day) {
            $date = CarbonImmutable::parse('2026-09-20')->subDays($day)->toDateString();
            foreach ([['BUSINESS_IMPRESSIONS_MOBILE_MAPS', $day < 28 ? 30 : 20], ['BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', $day < 28 ? 10 : 20], ['CALL_CLICKS', 2], ['BUSINESS_DIRECTION_REQUESTS', 1], ['WEBSITE_CLICKS', 3]] as [$metric, $value]) {
                DB::table('gbp_performance_daily')->insert(['external_resource_id' => $this->resource->id, 'digital_asset_id' => $this->asset->id, 'run_id' => $this->runId, 'location_name' => 'locations/22',
                    'reporting_date' => $date, 'metric' => $metric, 'value' => $value, 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        $numbers = app(GbpScreen::class)->overview($this->asset, $this->resource->id);
        $this->assertSame(['current' => 1120, 'previous' => 1120, 'change_pct' => 0], $numbers['views']);
        $this->assertSame(['calls' => 56, 'directions' => 28, 'website_clicks' => 84], $numbers['actions']);
        $this->assertSame(2, $numbers['unanswered']);
        $this->assertSame(3, $numbers['review_count']);
        $this->assertSame(3.3, $numbers['rating']);
        $this->assertNotNull($numbers['standards']);
        $this->assertLessThanOrEqual($numbers['standards']['total'], $numbers['standards']['passed']);

        $this->page('overview')->assertSeeInOrder(['1.120', '0% karşılaştırma dönemine göre', '168', 'Arama 56 · Yol 28 · Web 84', '3,3', '3 yorum', 'Yanıtsız yorum', '2', 'Profil standartları',
            $numbers['standards']['passed'].' / '.$numbers['standards']['total']]);
    }

    public function test_analysis_tab_shows_daily_split_top_twenty_keywords_and_review_trend(): void
    {
        foreach (range(0, 9) as $day) {
            DB::table('gbp_performance_daily')->insert(['external_resource_id' => $this->resource->id, 'digital_asset_id' => $this->asset->id, 'run_id' => $this->runId, 'location_name' => 'locations/22',
                'reporting_date' => now()->subDays($day + 1)->toDateString(), 'metric' => 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH', 'value' => 7, 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (range(1, 25) as $i) {
            foreach (['2026-07-01', '2026-08-01'] as $month) {
                DB::table('gbp_search_keywords_monthly')->insert(['external_resource_id' => $this->resource->id, 'digital_asset_id' => $this->asset->id, 'run_id' => $this->runId, 'location_name' => 'locations/22',
                    'month_start' => $month, 'search_keyword' => 'ifade '.$i, 'search_keyword_hash' => hash('sha256', $month.$i), 'impressions' => $i === 25 ? null : 100 + $i, 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        $analysis = app(GbpScreen::class)->analysis($this->resource->id, 28);
        $this->assertCount(10, $analysis['daily']);
        $this->assertSame(70, $analysis['totals']['search_views']);
        $this->assertSame(['2026-07', '2026-08'], $analysis['keywords']['months']);
        $this->assertCount(20, $analysis['keywords']['rows']);
        $this->assertSame('ifade 24', $analysis['keywords']['rows'][0]['keyword']);
        $this->assertSame(3, array_sum(array_column($analysis['reviews'], 'count')));

        $this->page('analysis')->assertSee('Günlük performans')->assertSee('Arama görüntüleme')->assertSee('ifade 24')->assertDontSee('ifade 25')->assertSee('Yorum trendi');
    }

    public function test_settings_tab_is_read_only_brand_and_location_info(): void
    {
        $this->page('settings')->assertSee('Atlas')->assertSee('Diş kliniği')->assertSee('0312 000 00 00')->assertSee('https://atlas.test')
            ->assertSee('Kısa açıklama')->assertSee('Google’da düzenle');
        Http::assertNothingSent();
    }

    private function dentalSector(): void
    {
        $dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $this->asset->brand->forceFill(['sector_id' => $dental->id])->save();
    }

    public function test_posts_tab_is_a_30_day_calendar_of_the_automatic_plan(): void
    {
        $today = GbpPostQueue::today();
        $make = fn (int $in, string $status, string $text): GbpQueuedPost => GbpQueuedPost::query()->create(['digital_asset_id' => $this->asset->id, 'brand_id' => $this->asset->brand_id,
            'angle' => 'surec', 'publish_on' => $today->addDays($in)->toDateString(), 'summary' => $text, 'status' => $status]);
        $draft = $make(1, GbpQueuedPost::DRAFT, 'İmplant tedavisi adım adım ilerler.');
        $make(2, GbpQueuedPost::APPROVED, 'Zirkonyum kaplamada ilk muayene.');
        $other = DigitalAsset::factory()->create(['brand_id' => $this->asset->brand_id, 'type' => 'google_business_profile']);
        $foreign = GbpQueuedPost::query()->create(['digital_asset_id' => $other->id, 'brand_id' => $this->asset->brand_id, 'angle' => 'surec',
            'publish_on' => $today->addDay()->toDateString(), 'summary' => 'Başka şubenin gönderisi.', 'status' => GbpQueuedPost::DRAFT]);

        $page = $this->page('posts')->assertSeeHtml('data-post-calendar')->assertSeeHtml('data-calendar-day="'.$today->addDays(30)->toDateString().'"')
            ->assertSee('İmplant tedavisi adım adım ilerler.')->assertSee('Süreç')->assertSee('1 onaylı')->assertSee('1 onay bekliyor')->assertSee('28 boş gün')
            ->assertDontSee('Başka şubenin gönderisi.')->assertSee('Otomatik plan açık: her gün 1 gönderi.');

        $page->call('approvePlanned', $draft->id);
        $this->assertSame(GbpQueuedPost::APPROVED, $draft->fresh()->status);
        try {
            $this->page('posts')->call('approvePlanned', $foreign->id);
        } catch (ModelNotFoundException) {
        }
        $this->assertSame(GbpQueuedPost::DRAFT, $foreign->fresh()->status, 'another location\'s post is not touched');
        $this->page('posts')->call('skipPlanned', $draft->id);
        $this->assertSame(GbpQueuedPost::SKIPPED, $draft->fresh()->status);
    }
}
