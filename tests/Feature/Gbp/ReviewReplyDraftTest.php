<?php

namespace Tests\Feature\Gbp;

use App\Ai\Agents\ReviewReplyAgent;
use App\Enums\DigitalAssetStatus;
use App\Livewire\Demo\Gbp\OverviewPage;
use App\Models\AiProduction;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\User;
use App\Services\Archive\ProductionArchive;
use App\Services\Gbp\ReviewReplyDrafter;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Faz 14: one-click AI reply drafts for the brand's Google reviews, response speed, liked examples. */
final class ReviewReplyDraftTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    private CoreExternalResource $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        config(['moxdop.anthropic.api_key' => 'sk-ant-test']);
        CoreIntegration::factory()->anthropic()->create();
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Örnek Klinik']);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_business_profile', 'status' => DigitalAssetStatus::Active, 'name' => 'Örnek Profil']);
        $google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $this->location = CoreExternalResource::factory()->create(['integration_id' => $google->id, 'provider' => 'google', 'resource_type' => 'google_business_profile', 'external_id' => 'locations/1', 'display_name' => 'Örnek', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        $binding = CoreAssetBinding::factory()->create(['digital_asset_id' => $this->asset->id, 'external_resource_id' => $this->location->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $run = Run::query()->create(['digital_asset_id' => $this->asset->id, 'core_asset_binding_id' => $binding->id, 'module_id' => 'google-business-profile', 'status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);
        foreach ([['r1', 'ONE', 'Çok bekledim, ilgilenen olmadı.', null], ['r2', 'FIVE', 'Harika', ['comment' => 'Teşekkürler', 'updateTime' => now()->subDays(9)->toIso8601String()]]] as [$id, $stars, $comment, $reply]) {
            DB::table('gbp_reviews')->insert(['digital_asset_id' => null, 'external_resource_id' => $this->location->id, 'location_name' => 'locations/1', 'run_id' => $run->id,
                'review_id' => $id, 'star_rating' => $stars, 'comment' => $comment, 'create_time' => now()->subDays(10), 'reviewer' => json_encode(['displayName' => 'Ayşe Y.']),
                'review_reply' => $reply !== null ? json_encode($reply) : null, 'raw_payload' => '{}', 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_reply_draft_is_written_on_click_archived_and_shown_without_the_reviewer_name(): void
    {
        $prompts = [];
        ReviewReplyAgent::fake([['reply' => 'Geri bildiriminiz için teşekkür ederiz; sizinle iletişime geçmek isteriz.', 'tone' => 'apology']]);
        $reviewId = (int) DB::table('gbp_reviews')->where('review_id', 'r1')->value('id');

        Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'reviews'])
            ->assertSee('Ortalama yanıt süresi')->assertSee('24 saat')
            ->assertSee('AI ile yanıt taslağı')
            ->call('draftReply', $reviewId)
            ->assertSee('sizinle iletişime geçmek isteriz');

        ReviewReplyAgent::assertPrompted(function ($prompt) use (&$prompts): bool {
            $prompts[] = (string) $prompt->prompt;

            return true;
        });
        $this->assertStringNotContainsString('Ayşe', implode(' ', $prompts), 'reviewer name is never sent');
        $this->assertStringContainsString('Çok bekledim', implode(' ', $prompts));
        $this->assertSame(1, AiProduction::query()->where('kind', ReviewReplyDrafter::KIND)->count());
    }

    public function test_liked_replies_become_examples_for_the_brand(): void
    {
        $production = AiProduction::query()->create(['kind' => ReviewReplyDrafter::KIND, 'subject_type' => 'GbpReview', 'subject_id' => 1, 'brand_id' => $this->asset->brand_id,
            'version' => 1, 'content' => ['reply' => 'Değerli yorumunuz için teşekkürler.'], 'content_hash' => str_repeat('b', 64), 'status' => AiProduction::STATUS_NEW]);
        $this->assertSame([], app(ProductionArchive::class)->likedExamples(ReviewReplyDrafter::KIND, (int) $this->asset->brand_id, 'reply'));

        app(ProductionArchive::class)->rate($production, 1);
        $this->assertSame(['Değerli yorumunuz için teşekkürler.'], app(ProductionArchive::class)->likedExamples(ReviewReplyDrafter::KIND, (int) $this->asset->brand_id, 'reply'));
    }
}
