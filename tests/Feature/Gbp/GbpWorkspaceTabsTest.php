<?php

namespace Tests\Feature\Gbp;

use App\Ai\Agents\GbpPostAgent;
use App\Livewire\Demo\Gbp\OverviewPage;
use App\Models\AiProduction;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Run;
use App\Models\User;
use App\Services\Gbp\GbpPostDrafter;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** The Business Profile daily workspace: Yorumlar, Gönderiler, Performans, Profil sağlığı, Yorum toplama. */
final class GbpWorkspaceTabsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private DigitalAsset $asset;

    private CoreExternalResource $resource;

    private int $runId;

    private bool $googleRefuses = false;

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private array $calls = [];

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

            if ($this->googleRefuses) {
                return Http::response(['error' => ['message' => 'Request contains an invalid argument.']], 400);
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

    public function test_reviews_tab_lists_unanswered_first_filters_by_rating_and_flags_late_ones(): void
    {
        $page = $this->page('reviews')->assertSeeInOrder(['Müşteri r-new', 'Müşteri r-old-bad', 'Müşteri r-replied'])
            ->assertSee('48 saati geçti')->assertSee('Teşekkürler');

        $page->set('rating', 'low')->assertSee('Müşteri r-old-bad')->assertDontSee('Müşteri r-new')
            ->set('rating', '')->set('unanswered', true)->assertDontSee('Müşteri r-replied');
    }

    public function test_admin_sends_a_reply_to_google_and_can_undo_it(): void
    {
        $page = $this->page('reviews')->call('publishReply', $this->reviewId('r-old-bad'), 'Geri bildiriminiz için teşekkürler, sizi arayacağız.');

        $this->assertSame(['PUT', 'https://mybusiness.googleapis.com/v4/accounts/11/locations/22/reviews/r-old-bad/reply'], array_slice(end($this->calls), 0, 2));
        $action = ExternalWriteAction::query()->sole();
        $this->assertSame('succeeded', $action->status);
        $page->call('setTab', 'reviews')->assertSee('İşletme yanıtı')->assertSee('sizi arayacağız')->assertSee('Geri al');

        $page->call('undoWrite', $action->id);
        $this->assertSame('undone', $action->fresh()->status);
        $this->assertSame('DELETE', end($this->calls)[0]);
        $this->assertNull(DB::table('gbp_reviews')->where('review_id', 'r-old-bad')->value('review_reply'));
    }

    public function test_team_member_cannot_send_replies_or_publish_posts(): void
    {
        $this->page('reviews', $this->member)->assertDontSee('Google\'a gönder')
            ->call('publishReply', $this->reviewId('r-new'), 'Teşekkürler')->assertForbidden();
        $this->page('posts', $this->member)->call('startPost')->set('post.title', 'Kış bakımı')->call('publishPost')->assertForbidden();
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

    public function test_ai_post_draft_uses_the_brand_context_and_fills_the_form(): void
    {
        config(['moxdop.anthropic.api_key' => 'sk-ant-test']);
        CoreIntegration::factory()->anthropic()->create();
        GbpPostAgent::fake([['title' => 'Kışa hazır gülüşler', 'body' => 'Soğuk havalarda diş hassasiyeti artar. Çankaya kliniğimizde kontrol randevunuzu alın.', 'action_type' => 'BOOK', 'service' => 'Diş kontrolü']]);

        $page = $this->page('posts')->set('postTopic', 'diş hassasiyeti')->call('draftPostWithAi')->assertSee('Kışa hazır gülüşler');
        $draft = AiProduction::query()->where('kind', GbpPostDrafter::KIND)->sole();
        GbpPostAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'diş hassasiyeti') && str_contains((string) $prompt->prompt, 'Diş kliniği'));

        $page->call('useAiDraft', $draft->id)->assertSet('editingPostId', 0)->assertSet('post.title', 'Kışa hazır gülüşler')->assertSet('post.action_type', 'BOOK');
        $this->assertSame(AiProduction::STATUS_USED, $draft->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_profile_health_is_a_read_only_checklist_with_a_link_to_google(): void
    {
        // Profile health is the Business Profile standards: upcoming Cumhuriyet Bayramı without special hours.
        $this->travelTo(now()->setDate(2026, 10, 10));
        $this->page('profile')->assertSee('Profil sağlığı')->assertSee('Çalışma ve özel gün saatleri')->assertSee('Cumhuriyet Bayramı')
            ->assertSee('Yapılacak: Saatler bölümünden')->assertSee('https://business.google.com/locations')->assertSee('2/7 gün')
            ->assertSee('Birincil kategori');
        Http::assertNothingSent();
    }

    public function test_review_collection_tab_gives_the_write_review_link_and_a_qr_code(): void
    {
        $this->page('collect')->assertSee('https://search.google.com/local/writereview?placeid=ChIJ-atlas')->assertSee('<svg', false)->assertSee('Kopyala');
    }

    public function test_performance_tab_compares_periods(): void
    {
        foreach (range(1, 60) as $day) {
            DB::table('gbp_performance_daily')->insert(['external_resource_id' => $this->resource->id, 'run_id' => $this->runId, 'location_name' => 'locations/22',
                'reporting_date' => now()->subDays($day)->toDateString(), 'metric' => 'CALL_CLICKS', 'value' => $day <= 28 ? 3 : 1, 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->page('performance')->assertSee(__('operator_gbp.metrics.calls'))->assertSee('+200%');
    }
}
