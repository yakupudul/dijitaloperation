<?php

namespace Tests\Feature\Brand;

use App\Ai\Agents\BrandDataReviewAgent;
use App\Enums\CustomerStatus;
use App\Enums\OfferingStatus;
use App\Jobs\Brand\RunBrandDataReviewJob;
use App\Models\AiTask;
use App\Models\Brand;
use App\Models\BrandConversionSource;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Models\ServiceCategory;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Ai\AiAssignments;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Brand\BrandDataAudit;
use App\Services\Brand\BrandDataReview;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Repair\RepairDesk;
use App\Services\Website\Pages\PageStore;
use App\Support\Ai\AiRouteKeys;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Claude marka denetimi (yakup, 2026-10-11): Claude reads the brand's services, places, conversions and page languages,
 * and its allow-listed, evidenced changes wait on the Onarım masası; nothing applies without approval.
 */
final class BrandDataReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.anthropic.api_key' => 'test-anthropic-value']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
        $this->brand = Brand::factory()->create(['name' => 'Opc', 'sector' => 'saglik', 'customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active->value])->id]);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'opc.com.tr', 'primary_url' => 'https://opc.com.tr/', 'module_id' => 'website']);
    }

    public function test_only_allow_listed_evidenced_changes_reach_the_desk_and_apply_on_approval(): void
    {
        $implant = $this->service('İmplant Tedavisi');
        $hair = $this->service('Saç Ekimi');
        $whitening = $this->service('Teeth Whitening');
        $main = $this->service('Dolgu', ['priority' => 'main']);
        foreach (['/implant-1/', '/implant-2/', '/kadikoy-implant/', '/kadikoy-dolgu/', '/kadikoy-beyazlatma/'] as $path) {
            OfferingPage::query()->create(['brand_offering_id' => $implant->id, 'page_id' => $this->page($path, ucfirst(trim(str_replace('-', ' ', $path), '/')))->id, 'source' => 'rule']);
        }
        $izmir = $this->area('İzmir', 'Seferihisar');
        $this->area('İstanbul', 'Üsküdar');
        $scroll = BrandConversionSource::query()->create(['brand_id' => $this->brand->id, 'source' => 'ga4_key_event', 'source_key' => 'scroll', 'label' => 'scroll',
            'conversion_type' => 'custom', 'counts' => true, 'origin' => BrandConversionSource::ORIGIN_OPERATOR]);
        $evidence = ['0 sayfa, 0 arama'];
        $op = fn (string $op, int $id = 0, array $extra = []): array => $extra + ['op' => $op, 'target_id' => $id, 'new_name' => '', 'city' => '', 'district' => '',
            'counts' => false, 'reason' => 'Kanıta göre.', 'evidence' => $evidence];
        BrandDataReviewAgent::fake(fn (): array => ['operations' => [
            $op(BrandDataAudit::ARCHIVE_SERVICE, $hair->id),
            $op(BrandDataAudit::ARCHIVE_SERVICE, $main->id),
            $op('delete_brand', $this->brand->id),
            $op(BrandDataAudit::RENAME_SERVICE, $whitening->id, ['new_name' => 'Diş Beyazlatma']),
            $op(BrandDataAudit::SET_MAIN, $implant->id),
            $op(BrandDataAudit::CONVERSION_COUNTS, $scroll->id, ['counts' => false]),
            $op(BrandDataAudit::REMOVE_AREA, $izmir->id),
            $op(BrandDataAudit::ADD_AREA, 0, ['city' => 'İstanbul', 'district' => 'Kadıköy']),
            $op(BrandDataAudit::ADD_AREA, 0, ['city' => 'Ankara', 'district' => 'Çankaya']),
            $op(BrandDataAudit::ARCHIVE_SERVICE, 999999),
            $op(BrandDataAudit::REMOVE_AREA, $izmir->id, ['evidence' => []]),
        ]]);

        $out = app(BrandDataReview::class)->run($this->brand);

        $this->assertSame('reviewed', $out['status']);
        $this->assertSame(6, $out['proposed']);
        $rows = Suggestion::query()->where('action_type', BrandDataAudit::TYPE)->get()->keyBy(fn (Suggestion $s): string => $s->action['op']);
        $this->assertEqualsCanonicalizing(['archive_service', 'rename_service', 'set_main', 'conversion_counts', 'remove_area', 'add_area'], $rows->keys()->all());
        $this->assertSame('Claude marka denetimi', $rows['archive_service']->evidence[0]['source']);
        $this->assertSame('0 sayfa, 0 arama', $rows['archive_service']->evidence[0]['value']);
        $this->assertSame('Kadıköy, İstanbul hizmet bölgesi olarak eklensin', $rows['add_area']->title, 'the pages name it; Ankara was never named');
        $this->assertSame(OfferingStatus::Active, $hair->fresh()->status, 'nothing applies before approval');
        $this->assertTrue($scroll->fresh()->counts);

        $desk = app(RepairDesk::class)->rows($this->brand->id, RepairDesk::BRAND_DATA);
        $result = app(RepairDesk::class)->approve($desk->pluck('id')->all(), $this->admin);

        $this->assertSame(6, $result['applied'], json_encode($result['failed'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(OfferingStatus::Archived, $hair->fresh()->status);
        $this->assertSame('Diş Beyazlatma', BrandOffering::query()->with('primaryName')->find($whitening->id)->displayName());
        $this->assertContains('Teeth Whitening', $whitening->names()->pluck('raw_label')->all(), 'the old name stays on the service');
        $this->assertTrue($implant->fresh()->isMain());
        $this->assertFalse($main->fresh()->isMain(), 'one ★');
        $this->assertFalse($scroll->fresh()->counts);
        $this->assertSame('archived', $izmir->fresh()->status);
        $this->assertTrue(BrandServiceArea::query()->where('brand_id', $this->brand->id)->where('district_name', 'Kadıköy')->where('status', 'active')->exists());
    }

    public function test_an_unchanged_brand_is_not_sent_again_and_rule_rows_and_claude_rows_keep_each_other(): void
    {
        $this->service('İmplant Tedavisi');
        $hair = $this->service('Saç Ekimi');
        $calls = 0;
        BrandDataReviewAgent::fake(function () use (&$calls, $hair): array {
            $calls++;

            return ['operations' => [['op' => BrandDataAudit::RENAME_SERVICE, 'target_id' => $hair->id, 'new_name' => 'Saç Ekimi (FUE)', 'city' => '', 'district' => '',
                'counts' => false, 'reason' => 'Sitede FUE geçiyor.', 'evidence' => ['3 sayfa FUE diyor']]]];
        });
        $review = app(BrandDataReview::class);

        $this->assertSame('reviewed', $review->run($this->brand)['status']);
        $this->assertSame('unchanged', $review->run($this->brand)['status']);
        $this->assertSame(1, $calls, 'the same input is not sent twice');
        app(BrandDataAudit::class)->run($this->brand->id);
        $this->assertSame(Suggestion::OPEN, Suggestion::query()->where('action->op', BrandDataAudit::RENAME_SERVICE)->sole()->status, 'the rules do not close Claude rows');

        $review->run($this->brand, force: true);
        $this->assertSame(2, $calls);
    }

    public function test_the_command_queues_active_brands_and_the_operation_is_bulk_work_for_haiku(): void
    {
        Queue::fake();
        Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Inactive->value])->id]);

        $this->artisan('moxdop:brands:review')->assertSuccessful();

        Queue::assertPushed(RunBrandDataReviewJob::class, 1);
        Queue::assertPushed(RunBrandDataReviewJob::class, fn (RunBrandDataReviewJob $job): bool => $job->brandId === $this->brand->id);
        $this->assertSame(AiAssignments::HAIKU, app(AiAssignments::class)->target(AiAssignments::PLAN_RECOMMENDED, BrandDataReviewAgent::OPERATION));
    }

    public function test_the_migration_joins_the_claude_api_period_and_queues_a_first_review(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('ai/claude-api-window.json', json_encode(['saved_at' => now()->toIso8601String(), 'models' => ['brand.care' => null]]));
        config(['moxdop-ai-pricing.claude_api_window.until' => now('Europe/Istanbul')->addDays(5)->toDateString()]);

        (require database_path('migrations/2026_12_11_120000_start_claude_brand_data_review.php'))->up();

        Queue::assertPushed(RunBrandDataReviewJob::class, fn (RunBrandDataReviewJob $job): bool => $job->brandId === $this->brand->id);
        $this->assertArrayHasKey('brand.data_review', json_decode((string) Storage::disk('local')->get('ai/claude-api-window.json'), true)['models']);
    }

    public function test_when_claude_takes_all_the_review_waits_for_claude_and_its_changes_reach_the_desk(): void
    {
        config(['moxdop-mcp.token' => 'test-mcp-token', 'moxdop-mcp.takes_all' => true]);
        $this->service('İmplant Tedavisi');
        $hair = $this->service('Saç Ekimi');
        BrandDataReviewAgent::fake()->preventStrayPrompts();

        (new RunBrandDataReviewJob($this->brand->id))->handle(app(BrandDataReview::class), app(AiTaskQueue::class));

        $task = AiTask::query()->sole();
        $this->assertSame([AiRouteKeys::BRAND_DATA_REVIEW, $this->brand->id], [$task->operation, (int) $task->brand_id]);
        $this->assertNull(BrandDataReview::stored($this->brand), 'nothing stored while Claude works');
        BrandDataReviewAgent::assertNeverPrompted();

        $this->assertSame([], app(AiTaskQueue::class)->submit($task, ['operations' => [['op' => BrandDataAudit::ARCHIVE_SERVICE, 'target_id' => $hair->id,
            'new_name' => '', 'city' => '', 'district' => '', 'counts' => false, 'reason' => 'Kanıta göre.', 'evidence' => ['0 sayfa, 0 arama']]]]));

        $row = Suggestion::query()->where('action_type', BrandDataAudit::TYPE)->sole();
        $this->assertSame([BrandDataAudit::ARCHIVE_SERVICE, 'Claude marka denetimi'], [$row->action['op'], $row->evidence[0]['source']]);
        $this->assertSame(1, BrandDataReview::stored($this->brand)['proposed']);
        $this->assertSame(OfferingStatus::Active, $hair->fresh()->status, 'nothing applies before approval');
        BrandDataReviewAgent::assertNeverPrompted();
    }

    /** @param  array<string, mixed>  $extra */
    private function service(string $name, array $extra = []): BrandOffering
    {
        $offering = app(BrandOfferingService::class)->resolveOrCreate($this->brand, $name, actor: $this->admin)['offering'];
        $offering->forceFill($extra + ['created_at' => now()->subDays(30)])->save();

        return $offering->fresh();
    }

    private function area(string $city, string $district): BrandServiceArea
    {
        return BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'country_code' => 'TR', 'city_name' => $city, 'district_name' => $district,
            'normalized_key' => mb_strtolower('tr|'.$city.'|'.$district), 'status' => 'active']);
    }

    private function page(string $path, string $title): Page
    {
        $url = 'https://opc.com.tr'.$path;

        return Page::query()->create(['website_asset_id' => $this->site->id, 'url' => $url, 'url_hash' => PageStore::urlHash($url), 'path' => $path,
            'title' => $title, 'language' => 'tr', 'is_indexable' => true, 'content_hash' => hash('sha256', $path)]);
    }
}
