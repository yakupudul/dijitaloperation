<?php

namespace Tests\Feature\Ai;

use App\Livewire\Operator\Settings\AiQualityPage;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\AiProduction;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Operations\AiQualityReport;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class AiQualityReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $ads;

    private AdvisorPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->ads = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads']);
        $this->plan = AdvisorPlan::query()->create(['channel' => 'google_ads', 'brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->ads->id, 'status' => 'completed']);
    }

    public function test_archive_outcomes_come_from_the_work_item_and_explicit_marks_win(): void
    {
        $done = $this->advisorItem('done');
        $skipped = $this->advisorItem('skipped');
        $open = $this->advisorItem('open');
        $this->production('google_ads.ad_copy', 'AdvisorItem', $done->id, 1, 'v2');
        $this->production('google_ads.ad_copy', 'AdvisorItem', $skipped->id, 1, 'v2');
        $this->production('google_ads.ad_copy', 'AdvisorItem', $open->id, 1, 'v1');
        $this->production('google_ads.ad_copy', 'AdvisorItem', $open->id, 2, 'v2', status: 'discarded');
        $this->production('google_ads.ad_copy', 'AdvisorItem', $open->id, 3, 'v2', rating: 1);
        $this->production('google_ads.ad_copy', 'AdvisorItem', $done->id, 0, 'v1', createdAt: now()->subDays(40));

        $reviewSame = $this->review('Teşekkür ederiz, tekrar bekleriz.');
        $reviewEdited = $this->review('Çok teşekkürler Ayşe Hanım!');
        $reviewNone = $this->review(null);
        $this->production('gbp.review_reply', 'GbpReview', $reviewSame, 1, 'r1', content: ['reply' => 'Teşekkür ederiz,  tekrar bekleriz.']);
        $this->production('gbp.review_reply', 'GbpReview', $reviewEdited, 1, 'r1', content: ['reply' => 'Teşekkür ederiz.']);
        $this->production('gbp.review_reply', 'GbpReview', $reviewNone, 1, 'r1', content: ['reply' => 'x']);

        DB::table('ai_usage_records')->insert([
            ['route_key' => 'google_ads.ad_copy_draft', 'agent' => 'GoogleAdsAdCopyAgent', 'provider' => 'anthropic', 'model' => 'm', 'cost_usd' => 0.30, 'created_at' => now()->subDays(2)],
            ['route_key' => 'google_ads.ad_copy_draft', 'agent' => 'GoogleAdsAdCopyAgent', 'provider' => 'anthropic', 'model' => 'm', 'cost_usd' => 0.20, 'created_at' => now()->subDays(50)],
            ['route_key' => 'search_demand.clustering', 'agent' => 'X', 'provider' => 'anthropic', 'model' => 'm', 'cost_usd' => 1.00, 'created_at' => now()->subDay()],
        ]);

        $report = app(AiQualityReport::class)->build(30);
        $rows = collect($report['groups'])->firstWhere('key', 'archive')['rows'];
        $copy = collect($rows)->where('source', 'google_ads.ad_copy');
        $total = $copy->firstWhere('is_total', true);
        // done → accepted; skipped → rejected; open v1 replaced → rejected; v2 discarded → rejected; v3 👍 → accepted; 40-day-old row out of window.
        $this->assertSame(['produced' => 5, 'accepted' => 2, 'edited' => 0, 'rejected' => 3, 'pending' => 0], array_intersect_key($total, array_flip(['produced', 'accepted', 'edited', 'rejected', 'pending'])));
        $this->assertSame(40.0, $total['acceptance']);
        $this->assertSame(0.3, $total['cost']);
        $this->assertSame(['v1', 'v2'], $copy->where('is_total', false)->pluck('version')->values()->all(), 'per prompt version');
        $this->assertSame(4, $copy->firstWhere('version', 'v2')['produced']);

        $reply = collect($rows)->where('source', 'gbp.review_reply')->firstWhere('is_total', true);
        $this->assertSame([1, 1, 1], [$reply['accepted'], $reply['edited'], $reply['pending']]);
        $this->assertSame('r1', $reply['version'], 'single version collapses into the total row');

        $this->assertSame(1.3, $report['total_cost']);
        $this->assertFalse(collect($report['routes'])->firstWhere('route', 'search_demand.clustering')['mapped']);
        $this->assertTrue(collect($report['routes'])->firstWhere('route', 'google_ads.ad_copy_draft')['mapped']);
    }

    public function test_site_fix_and_brain_sources_and_low_acceptance_flag(): void
    {
        $site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website']);
        $fix = fn (string $type, string $status, string $by, array $proposed): int => DB::table('site_fix_items')->insertGetId([
            'digital_asset_id' => $site->id, 'brand_id' => $this->brand->id, 'type' => $type, 'label' => 'x', 'proposed' => json_encode($proposed),
            'proposed_by' => $by, 'status' => $status, 'item_key' => hash('sha256', uniqid('', true)), 'created_at' => now()->subDays(3), 'updated_at' => now(),
        ]);
        $fix('seo_title', 'applied', 'ai', ['value' => 'Başlık', 'prompt_version' => 'fix-1']);
        $fix('seo_title', 'applied', 'operator', ['value' => 'Düzeltilmiş', 'prompt_version' => 'fix-1']);
        $fix('seo_title', 'dismissed', 'ai', ['value' => 'Kötü', 'prompt_version' => 'fix-1']);
        $fix('seo_title', 'open', 'rule', ['value' => 'Kural']);
        $fix('new_page', 'open', 'ai', ['value' => ['title' => 't', 'html' => '<p>x</p>'], 'prompt_version' => 'page-1']);

        $brain = function (string $status, int $count, string $source = 'ai'): void {
            foreach (range(1, $count) as $i) {
                DB::table('brain_proposals')->insert([
                    'kind' => 'query_service', 'subject_type' => 'search_query', 'subject_id' => random_int(1, 1_000_000), 'title' => 't', 'proposed' => '{}',
                    'source' => $source, 'status' => $status, 'fingerprint' => hash('sha256', uniqid('', true)), 'created_at' => now()->subDays(5), 'updated_at' => now(),
                ]);
            }
        };
        $brain('applied', 5);
        $brain('rejected', 16);
        $brain('pending', 3, 'vector');
        $brain('nothing', 4);

        $report = app(AiQualityReport::class)->build(30);
        $fixRow = collect(collect($report['groups'])->firstWhere('key', 'site_fix')['rows'])->firstWhere('source', 'seo_title');
        $this->assertSame([3, 1, 1, 1], [$fixRow['produced'], $fixRow['accepted'], $fixRow['edited'], $fixRow['rejected']], 'rule proposals and page drafts are not counted here');
        $this->assertSame(66.7, $fixRow['acceptance']);

        $brainRows = collect(collect($report['groups'])->firstWhere('key', 'brain')['rows'])->where('source', 'query_service');
        $total = $brainRows->firstWhere('is_total', true);
        $this->assertSame([24, 5, 16, 3], [$total['produced'], $total['accepted'], $total['rejected'], $total['pending']]);
        $this->assertTrue($total['flagged'], '5 / 21 decided < 30 % with ≥ 20 decided');
        $this->assertSame(['ai', 'vector'], $brainRows->where('is_total', false)->pluck('version')->values()->all());
        $this->assertSame(1, $report['flagged']);

        Livewire::test(AiQualityPage::class)->assertSee('AI kalitesi')->assertSee('Sorgu → hizmet eşleştirme')->assertSee('Düşük kabul')->assertSee('SEO başlığı')
            ->set('days', 90)->assertOk();
        $this->get(route('operator.settings.ai-quality'))->assertOk();
    }

    public function test_page_is_admin_only(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($user)->get(route('operator.settings.ai-quality'))->assertForbidden();
    }

    private function advisorItem(string $status): AdvisorItem
    {
        return AdvisorItem::query()->create([
            'channel' => 'google_ads', 'customer_id' => $this->brand->customer_id, 'brand_id' => $this->brand->id, 'digital_asset_id' => $this->ads->id,
            'item_key' => hash('sha256', $status.uniqid('', true)), 'category' => 'ads', 'rule_id' => 'ad-strength', 'severity' => 'medium', 'priority_score' => 1,
            'title' => 'Reklam', 'reason' => 'r', 'evidence' => [], 'checklist' => [], 'status' => $status, 'first_seen_plan_id' => $this->plan->id, 'last_seen_plan_id' => $this->plan->id,
        ]);
    }

    /** @param array<string, mixed> $content */
    private function production(string $kind, string $subjectType, int $subjectId, int $version, ?string $prompt, string $status = 'new', ?int $rating = null, array $content = ['x' => 1], ?Carbon $createdAt = null): void
    {
        $row = AiProduction::query()->create([
            'kind' => $kind, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'brand_id' => $this->brand->id, 'version' => $version, 'content' => $content,
            'content_hash' => hash('sha256', uniqid('', true)), 'prompt_version' => $prompt, 'status' => $status, 'rating' => $rating,
        ]);
        $row->forceFill(['created_at' => $createdAt ?? now()->subDays(3)])->save();
    }

    private function review(?string $reply): int
    {
        return DB::table('gbp_reviews')->insertGetId([
            'digital_asset_id' => 1, 'external_resource_id' => 1, 'run_id' => 1, 'location_name' => 'locations/1', 'review_id' => uniqid('r', true),
            'comment' => 'Güzel', 'review_reply' => $reply !== null ? json_encode(['comment' => $reply, 'updateTime' => now()->toIso8601String()]) : null,
            'raw_payload' => '{}', 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
