<?php

namespace Tests\Feature\Gbp;

use App\Ai\Agents\ReviewReplyAgent;
use App\Enums\DigitalAssetStatus;
use App\Jobs\DraftReviewReplyJob;
use App\Livewire\Operator\Gbp\Desk\ReviewsPage;
use App\Models\AiProduction;
use App\Models\AiTask;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\User;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Archive\ProductionArchive;
use App\Services\Gbp\Desk\ReviewDesk;
use App\Services\Gbp\ReviewReplyDrafter;
use App\Services\Prompts\PromptRegistry;
use App\Support\Ai\AiRouteKeys;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
        config(['moxdop.anthropic.api_key' => 'test-anthropic-value']);
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

        Livewire::test(ReviewsPage::class, ['asset' => $this->asset->id])
            ->assertSee('AI taslağı')
            ->call('draftOne', $reviewId)
            ->assertSet('replies.r'.$reviewId, "Geri bildiriminiz için teşekkür ederiz; sizinle iletişime geçmek isteriz.\n\nÖrnek Klinik ekibi");

        ReviewReplyAgent::assertPrompted(function ($prompt) use (&$prompts): bool {
            $prompts[] = (string) $prompt->prompt;

            return true;
        });
        $this->assertStringNotContainsString('Ayşe', implode(' ', $prompts), 'reviewer name is never sent');
        $this->assertStringContainsString('Çok bekledim', implode(' ', $prompts));
        $this->assertSame(1, AiProduction::query()->where('kind', ReviewReplyDrafter::KIND)->count());
        $this->assertSame((string) app(PromptRegistry::class)->current(AiRouteKeys::GBP_REVIEW_REPLY)->id,
            AiProduction::query()->where('kind', ReviewReplyDrafter::KIND)->value('prompt_version'), 'the registry prompt version is archived');
    }

    public function test_one_click_drafts_more_than_thirty_selected_reviews(): void
    {
        // yakup, 2026-10-06: 200+ selected reviews, only 30 drafts were asked.
        Queue::fake();
        $run = (int) DB::table('gbp_reviews')->value('run_id');
        for ($i = 0; $i < 40; $i++) {
            DB::table('gbp_reviews')->insert(['digital_asset_id' => null, 'external_resource_id' => $this->location->id, 'location_name' => 'locations/1', 'run_id' => $run,
                'review_id' => 'bulk'.$i, 'star_rating' => 'FIVE', 'comment' => 'Memnun kaldım '.$i, 'create_time' => now()->subDays(20 + $i), 'reviewer' => json_encode(['displayName' => 'Ali']),
                'review_reply' => null, 'raw_payload' => '{}', 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        // A reply written but not sent stays "waiting" (Google still shows it unanswered), is not drafted again, and
        // "Taslağı olmayanlar" leaves it out.
        $written = (int) DB::table('gbp_reviews')->where('review_id', 'bulk0')->value('id');
        app(ReviewDesk::class)->saveDraft(auth()->user(), $written, 'Teşekkür ederiz.');

        Livewire::test(ReviewsPage::class, ['asset' => $this->asset->id])
            ->assertSee('Yanıtı hazır olanlar (1)')
            ->assertSee('Taslağı olmayanlar (40)')
            ->call('pick', 'nodraft')
            ->assertCount('selected', 40)
            ->call('pick', 'all')
            ->assertCount('selected', 41)
            ->assertSee('1 yanıtı hazır (gönderilmedi) · 40 taslaksız')
            ->assertSee('AI ile taslak yaz (40)')
            ->call('draftSelected')
            ->assertSee('40 yorum için yanıt taslağı yazılıyor');
        Queue::assertPushed(DraftReviewReplyJob::class, 40);
    }

    public function test_liked_replies_become_examples_for_the_brand(): void
    {
        $production = AiProduction::query()->create(['kind' => ReviewReplyDrafter::KIND, 'subject_type' => 'GbpReview', 'subject_id' => 1, 'brand_id' => $this->asset->brand_id,
            'version' => 1, 'content' => ['reply' => 'Değerli yorumunuz için teşekkürler.'], 'content_hash' => str_repeat('b', 64), 'status' => AiProduction::STATUS_NEW]);
        $this->assertSame([], app(ProductionArchive::class)->likedExamples(ReviewReplyDrafter::KIND, (int) $this->asset->brand_id, 'reply'));

        app(ProductionArchive::class)->rate($production, 1);
        $this->assertSame(['Değerli yorumunuz için teşekkürler.'], app(ProductionArchive::class)->likedExamples(ReviewReplyDrafter::KIND, (int) $this->asset->brand_id, 'reply'));
    }

    public function test_the_brand_s_recent_openings_are_sent_so_a_batch_does_not_start_the_same_way(): void
    {
        $reviewId = (int) DB::table('gbp_reviews')->where('review_id', 'r1')->value('id');
        AiProduction::query()->create(['kind' => ReviewReplyDrafter::KIND, 'subject_type' => 'GbpReview', 'subject_id' => $reviewId + 100, 'brand_id' => $this->asset->brand_id,
            'version' => 1, 'content' => ['reply' => 'Değerli yorumunuz için çok teşekkür ederiz. Ekibimize ileteceğiz.'], 'content_hash' => str_repeat('c', 64), 'status' => AiProduction::STATUS_NEW]);
        ReviewReplyAgent::fake([['reply' => 'Bekleme için üzgünüz; bize doğrudan ulaşırsanız çözelim.', 'tone' => 'apology']]);

        app(ReviewReplyDrafter::class)->write($reviewId);

        $prompts = [];
        ReviewReplyAgent::assertPrompted(function ($prompt) use (&$prompts): bool {
            $prompts[] = (string) $prompt->prompt;

            return true;
        });
        $context = json_decode((string) preg_replace('/^REVIEW_JSON\n/', '', $prompts[0]), true);
        $this->assertSame(['Değerli yorumunuz için çok teşekkür ederiz.'], $context['recent_openings']);
    }

    public function test_when_claude_takes_all_the_draft_waits_for_claude_and_lands_in_the_archive(): void
    {
        config(['moxdop-mcp.token' => 'test-mcp-token', 'moxdop-mcp.takes_all' => true]);
        ReviewReplyAgent::fake()->preventStrayPrompts();
        $reviewId = (int) DB::table('gbp_reviews')->where('review_id', 'r1')->value('id');

        Livewire::test(ReviewsPage::class, ['asset' => $this->asset->id])->call('draftOne', $reviewId);

        ReviewReplyAgent::assertNeverPrompted();
        $task = AiTask::query()->sole();
        $this->assertSame(AiRouteKeys::GBP_REVIEW_REPLY, $task->operation);
        $this->assertSame($this->asset->brand_id, $task->brand_id);
        $this->assertStringStartsWith('REVIEW_JSON', (string) $task->input);
        $this->assertStringNotContainsString('Ayşe', (string) $task->input, 'reviewer name is never sent');
        $this->assertSame('running', app(ReviewReplyDrafter::class)->state($reviewId));
        $this->assertSame(0, AiProduction::query()->where('kind', ReviewReplyDrafter::KIND)->count());

        $this->assertSame([], app(AiTaskQueue::class)->submit($task, ['reply' => 'Geri bildiriminiz için teşekkür ederiz; sizinle iletişime geçmek isteriz.', 'tone' => 'apology']));

        ReviewReplyAgent::assertNeverPrompted();
        $draft = AiProduction::query()->where('kind', ReviewReplyDrafter::KIND)->sole();
        $this->assertSame("Geri bildiriminiz için teşekkür ederiz; sizinle iletişime geçmek isteriz.\n\nÖrnek Klinik ekibi", $draft->content['reply'], 'the business signs its replies');
        $this->assertSame(AiTaskQueue::PROVIDER, $draft->content['provider']);
        $this->assertNull(app(ReviewReplyDrafter::class)->state($reviewId));
        $this->assertSame(AiTask::CONSUMED, $task->fresh()->status);
    }

    public function test_replies_are_signed_and_never_thank_for_stars_or_guess_a_doctors_gender(): void
    {
        // yakup 2026-11-14: "hiç klinik cevabı gibi durmuyor" — "Bize yıldız bıraktığınız için sağ olun", "Burçin Hanım" for a male doctor.
        $this->assertSame('Dr. Dt. Burçin Öncül', ReviewReplyDrafter::displayName('Dr. Dt. Burçin ÖNCÜL'));
        $this->assertSame('KCT Kırmızı', ReviewReplyDrafter::displayName('KCT KIRMIZI'), 'short acronyms stay; Turkish dotless i');
        $brand = Brand::query()->findOrFail($this->asset->brand_id);
        $this->assertSame('Örnek Klinik ekibi', ReviewReplyDrafter::signature($brand));
        $brand->forceFill(['name' => 'Dr. Dt. Burçin ÖNCÜL'])->save();
        $this->assertSame('Dr. Dt. Burçin Öncül ve ekibi', ReviewReplyDrafter::signature($brand));

        $this->assertNotNull(ReviewReplyDrafter::voiceProblem('Bize yıldız bıraktığınız için sağ olun.', []));
        $this->assertNotNull(ReviewReplyDrafter::voiceProblem('Puanınız için teşekkür ederiz.', []));
        $this->assertNotNull(ReviewReplyDrafter::voiceProblem('Güzel sözlerinizi Burçin Hanım\'a ileteceğiz.', ['brand_epicrisis' => ['facts' => ['Hekim / uzman: Dr. Dt. Burçin Öncül']]]));
        $this->assertNull(ReviewReplyDrafter::voiceProblem('Güzel sözlerinizi Burçin Bey\'e ileteceğiz.', ['brand_epicrisis' => ['facts' => ['Hekim / uzman: Dr. Dt. Burçin Öncül (hitap: Burçin Bey)']]]));
        $this->assertNull(ReviewReplyDrafter::voiceProblem('Diş Beyazlatma sürecinde yanınızdayız; sağlıklı günler dileriz.', []));

        $reviewId = (int) DB::table('gbp_reviews')->where('review_id', 'r1')->value('id');
        ReviewReplyAgent::fake([['reply' => 'Geri bildiriminizi dikkate alıyoruz. Bizimle doğrudan iletişime geçmenizi rica ederiz.', 'tone' => 'apology']]);
        app(ReviewReplyDrafter::class)->write($reviewId);
        $this->assertSame("Geri bildiriminizi dikkate alıyoruz. Bizimle doğrudan iletişime geçmenizi rica ederiz.\n\nDr. Dt. Burçin Öncül ve ekibi",
            AiProduction::query()->where('kind', ReviewReplyDrafter::KIND)->sole()->content['reply']);

        ReviewReplyAgent::fake([['reply' => 'Burçin Hanım\'a güveniniz için teşekkür ederiz, sağlıklı günler dileriz.', 'tone' => 'thanks']]);
        $other = (int) DB::table('gbp_reviews')->where('review_id', 'r2')->value('id');
        app(ReviewReplyDrafter::class)->write($other);
        $this->assertStringStartsWith('failed', (string) app(ReviewReplyDrafter::class)->state($other));
        $this->assertSame(1, AiProduction::query()->where('kind', ReviewReplyDrafter::KIND)->count());
    }

    public function test_open_drafts_of_an_older_reply_prompt_are_drafted_again_but_hand_written_and_current_ones_are_not(): void
    {
        $run = (int) DB::table('gbp_reviews')->value('run_id');
        $ids = [];
        foreach (['old', 'manual', 'current'] as $i => $key) {
            $ids[$key] = DB::table('gbp_reviews')->insertGetId(['digital_asset_id' => null, 'external_resource_id' => $this->location->id, 'location_name' => 'locations/1', 'run_id' => $run,
                'review_id' => 's'.$key, 'star_rating' => 'FIVE', 'comment' => 'Memnun kaldım '.$key, 'create_time' => now()->subDays(5 + $i), 'reviewer' => json_encode(['displayName' => 'Ali']),
                'review_reply' => null, 'raw_payload' => '{}', 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $current = (string) app(PromptRegistry::class)->current(AiRouteKeys::GBP_REVIEW_REPLY)->id;
        $older = (string) ((int) $current + 100);
        foreach (['old' => ['reply' => 'Yıldız için sağ olun.', 'prompt_version' => $older], 'manual' => ['reply' => 'Elle yazıldı.', 'manual' => true, 'prompt_version' => $older], 'current' => ['reply' => 'Yeni.', 'prompt_version' => $current]] as $key => $content) {
            AiProduction::query()->create(['kind' => ReviewReplyDrafter::KIND, 'subject_type' => 'GbpReview', 'subject_id' => $ids[$key], 'brand_id' => $this->asset->brand_id,
                'digital_asset_id' => $this->asset->id, 'version' => 1, 'content' => $content, 'prompt_version' => $content['prompt_version'],
                'content_hash' => hash('sha256', $key), 'status' => AiProduction::STATUS_NEW]);
        }

        $this->assertSame([$ids['old']], app(ReviewReplyDrafter::class)->staleReviewIds($this->asset->id, 300));
        $this->assertSame([], app(ReviewReplyDrafter::class)->staleReviewIds($this->asset->id, 0));
    }
}
