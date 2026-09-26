<?php

namespace Tests\Feature\ExternalWrites;

use App\Livewire\Operator\Content\ContentCalendarPage;
use App\Models\Brand;
use App\Models\ContentCalendarItem;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpReview;
use App\Models\User;
use App\Services\CommandCenter\CommandCenter;
use App\Services\Content\ContentCalendarPublisher;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** ADR-073: Admin-approved review replies and Business Profile posts (content calendar), undoable. */
final class GbpWritesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DigitalAsset $profile;

    private CoreExternalResource $resource;

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas']);
        $this->profile = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'name' => 'Atlas Çankaya']);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r'], 'expires_at' => now()->addHour()]);
        $this->resource = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => 'google_business_profile',
            'external_id' => 'accounts/11/locations/22', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->profile->id, 'external_resource_id' => $this->resource->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        Http::fake(function (Request $request) {
            $this->calls[] = [$request->method(), $request->url(), $request->data()];

            return str_contains($request->url(), 'localPosts') && $request->method() === 'POST'
                ? Http::response(['name' => 'accounts/11/locations/22/localPosts/555'])
                : Http::response(['comment' => 'ok']);
        });
    }

    public function test_review_reply_is_published_and_undo_deletes_it(): void
    {
        $review = new GbpReview;
        $review->forceFill(['digital_asset_id' => $this->profile->id, 'external_resource_id' => $this->resource->id, 'run_id' => 1, 'location_name' => 'locations/22', 'review_id' => 'R1',
            'star_rating' => 'TWO', 'comment' => 'Beklettiler', 'raw_payload' => [], 'collected_at' => now()])->save();

        $action = app(ExternalWriteService::class)->requestReviewReply($this->admin, $review, 'Geri bildiriminiz için teşekkürler, sizi arayacağız.');

        $this->assertSame('succeeded', $action->fresh()->status);
        $this->assertSame(['PUT', 'https://mybusiness.googleapis.com/v4/accounts/11/locations/22/reviews/R1/reply'], array_slice(end($this->calls), 0, 2));
        $this->assertSame('Geri bildiriminiz için teşekkürler, sizi arayacağız.', $review->fresh()->review_reply['comment']);

        app(ExternalWriteService::class)->requestUndo($this->admin, $action->fresh());
        $this->assertSame('undone', $action->fresh()->status);
        $this->assertSame('DELETE', end($this->calls)[0], 'there was no reply before, so undo deletes it');
    }

    public function test_approved_calendar_post_is_published_when_due_and_team_members_cannot_publish(): void
    {
        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        Livewire::actingAs($this->admin)->test(ContentCalendarPage::class)->call('startNew')
            ->set('form.brand_id', $this->profile->brand_id)->set('form.channel', 'gbp_post')->set('form.digital_asset_id', $this->profile->id)
            ->set('form.title', 'Ekim kampanyası')->set('form.body', 'İmplantta ücretsiz muayene.')->set('form.url', 'https://atlas.test/implant')
            ->set('form.scheduled_for', now()->subMinute()->timezone('Europe/Istanbul')->format('Y-m-d\TH:i'))->call('save');
        $item = ContentCalendarItem::query()->sole();
        $this->assertSame('draft', $item->status);
        $this->assertContains('calendar:'.$item->id, app(CommandCenter::class)->items()->pluck('key')->all(), 'a draft post due soon waits for approval in the command center');

        $this->assertSame(0, app(ContentCalendarPublisher::class)->publishDue(), 'not approved yet');
        Livewire::actingAs($member)->test(ContentCalendarPage::class)->call('approve', $item->id)->assertForbidden();
        Livewire::actingAs($this->admin)->test(ContentCalendarPage::class)->call('approve', $item->id);

        $this->assertSame(1, app(ContentCalendarPublisher::class)->publishDue());
        $item->refresh();
        $this->assertSame('published', $item->status);
        $this->assertSame('accounts/11/locations/22/localPosts/555', $item->external_ref);
        $post = collect($this->calls)->first(fn ($c) => $c[0] === 'POST');
        $this->assertSame('https://atlas.test/implant', $post[2]['callToAction']['url']);
        $this->assertSame(ExternalWriteAction::ACTION_LOCAL_POST, ExternalWriteAction::query()->value('action'));
        $this->assertSame(0, DB::table('content_calendar_items')->whereNull('write_action_id')->count());
        $this->actingAs($this->admin)->get(route('operator.content.calendar', ['showDone' => 1]))->assertOk()->assertSee('Ekim kampanyası');
    }
}
