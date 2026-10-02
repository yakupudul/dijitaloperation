<?php

namespace Tests\Feature\Gbp;

use App\Livewire\Demo\Gbp\OverviewPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\User;
use App\Services\Gbp\GbpDailyWorkspace;
use App\Services\Integrations\Google\GoogleBusinessProfileBoundCollector;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Review collection of a bound Business Profile location: the v4 reviews API needs the account, which a wildcard
 * discovery does not store; the collector resolves it, remembers it on the location, collects incrementally and
 * tells the operator in Turkish when Google refuses reviews.
 */
final class GbpReviewCollectionTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    private CoreExternalResource $resource;

    private CoreAssetBinding $binding;

    /** @var list<string> */
    private array $urls = [];

    /** @var array<string, mixed>|int HTTP status to return for the reviews call, or its payload */
    private array|int $reviews = [];

    private int $accountsStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret']);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $integration->id,
            'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => 'https://www.googleapis.com/auth/business.manage'], 'expires_at' => now()->addHour()]);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas']);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'name' => 'Atlas Çankaya']);
        // Wildcard discovery (accounts/-) stores only locations/{id}: no account on the location.
        $this->resource = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => 'google_business_profile',
            'external_id' => 'locations/22', 'parent_external_id' => null, 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        $this->binding = CoreAssetBinding::factory()->create(['digital_asset_id' => $this->asset->id, 'external_resource_id' => $this->resource->id,
            'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $this->reviews = ['averageRating' => 4.5, 'totalReviewCount' => 2, 'reviews' => [
            ['reviewId' => 'R1', 'starRating' => 'FIVE', 'comment' => 'Harika ekip', 'createTime' => now()->subDays(3)->toIso8601String(), 'updateTime' => now()->subDays(3)->toIso8601String(),
                'reviewer' => ['displayName' => 'Ayşe'], 'reviewReply' => ['comment' => 'Teşekkürler', 'updateTime' => now()->subDays(2)->toIso8601String()]],
            ['reviewId' => 'R2', 'starRating' => 'TWO', 'comment' => 'Bekledim', 'createTime' => now()->subDays(4)->toIso8601String(), 'updateTime' => now()->subDays(4)->toIso8601String(),
                'reviewer' => ['displayName' => 'Mehmet']],
        ]];
        Http::fake(function (Request $request) {
            $url = $request->url();
            $this->urls[] = $url;

            return match (true) {
                str_contains($url, 'mybusinessaccountmanagement.googleapis.com/v1/accounts') => $this->accountsStatus === 200
                    ? Http::response(['accounts' => [['name' => 'accounts/11']]])
                    : Http::response(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'My Business Account Management API has not been used in project 1 before or it is disabled. SERVICE_DISABLED']], $this->accountsStatus),
                str_contains($url, 'v1/accounts/11/locations') => Http::response(['locations' => [['name' => 'locations/22']]]),
                str_contains($url, '/reviews') => is_int($this->reviews)
                    ? Http::response(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'The caller does not have permission']], $this->reviews)
                    : Http::response($this->reviews),
                str_contains($url, 'mybusinessbusinessinformation.googleapis.com/v1/locations/22?') || str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', 'v1/locations/22') => Http::response(['name' => 'locations/22', 'title' => 'Atlas Çankaya', 'metadata' => ['placeId' => 'ChIJ-atlas', 'mapsUri' => 'https://maps.google.com/?cid=1']]),
                str_contains($url, 'getDailyMetricsTimeSeries') => Http::response(['timeSeries' => ['datedValues' => [['date' => ['year' => 2026, 'month' => 9, 'day' => 1], 'value' => '5']]]]),
                default => Http::response([]),
            };
        });
    }

    private function collect(): void
    {
        app(GoogleBusinessProfileBoundCollector::class)->collect($this->binding->fresh());
    }

    public function test_reviews_are_collected_with_reply_state_and_the_account_is_remembered_for_replies(): void
    {
        $this->collect();

        $this->assertSame(2, DB::table('gbp_reviews')->where('external_resource_id', $this->resource->id)->count());
        $this->assertNotNull(DB::table('gbp_reviews')->where('review_id', 'R1')->value('review_reply'), 'the owner reply is stored');
        $this->assertNull(DB::table('gbp_reviews')->where('review_id', 'R2')->value('review_reply'));
        $this->assertSame('accounts/11', $this->resource->fresh()->parent_external_id, 'resolved account is stored for the next run and the ADR-073 writes');
        $this->assertContains('https://mybusiness.googleapis.com/v4/accounts/11/locations/22/reviews?pageSize=50&orderBy=updateTime%20desc', $this->urls);
        $this->assertSame(['state' => 'ok'], array_intersect_key(app(GbpDailyWorkspace::class)->reviewAccess($this->asset), ['state' => true]));
        $this->assertSame('ChIJ-atlas', app(GbpDailyWorkspace::class)->placeId($this->resource->fresh()));
    }

    public function test_daily_collection_is_incremental_after_a_full_pass(): void
    {
        $this->collect();
        $this->urls = [];
        // Page 1 already reaches reviews older than the stored ones: page 2 is not requested.
        $this->reviews = ['reviews' => [['reviewId' => 'R0', 'starRating' => 'FOUR', 'createTime' => now()->subDays(40)->toIso8601String(), 'updateTime' => now()->subDays(40)->toIso8601String()]],
            'nextPageToken' => 'page-2'];

        $this->collect();

        $reviewCalls = array_values(array_filter($this->urls, fn (string $url): bool => str_contains($url, '/reviews')));
        $this->assertCount(1, $reviewCalls);
        $this->assertStringNotContainsString('page-2', $reviewCalls[0]);
        $this->assertSame(3, DB::table('gbp_reviews')->where('external_resource_id', $this->resource->id)->count());
        $this->assertStringNotContainsString('mybusinessaccountmanagement', implode(' ', $this->urls), 'the remembered account is not resolved again');
    }

    public function test_google_refusing_reviews_is_explained_in_turkish_on_the_asset_page(): void
    {
        $this->reviews = 403;
        $this->collect();

        $access = app(GbpDailyWorkspace::class)->reviewAccess($this->asset);
        $this->assertSame('unavailable', $access['state']);
        $this->assertStringContainsString('Google bu hesap için yorum erişimi vermedi (API onayı gerekli)', (string) $access['reason']);
        $this->assertSame(0, DB::table('gbp_reviews')->count());

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        Livewire::actingAs($admin)->test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'reviews'])
            ->assertSee('Yorumlar toplanamıyor')->assertSee('Google bu hesap için yorum erişimi vermedi (API onayı gerekli)')->assertSee('PERMISSION_DENIED');
    }

    public function test_account_resolution_failure_does_not_fail_the_whole_collection(): void
    {
        $this->accountsStatus = 403;
        $this->collect();

        $run = Run::query()->latest('id')->firstOrFail();
        $this->assertSame('partial', $run->status, 'location and performance still count');
        $this->assertSame('available', data_get($run->metadata, 'datasets.gbp_location.status'));
        $this->assertSame('unavailable', data_get($run->metadata, 'datasets.gbp_reviews.status'));
        $this->assertStringContainsString('SERVICE_DISABLED', (string) data_get($run->metadata, 'datasets.gbp_reviews.reason'));
        $this->assertStringContainsString('My Business Account Management API', (string) app(GbpDailyWorkspace::class)->reviewAccess($this->asset)['reason']);
        $this->assertNull($this->resource->fresh()->parent_external_id);
    }
}
