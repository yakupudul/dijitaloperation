<?php

namespace Tests\Feature\Archive;

use App\Enums\CustomerStatus;
use App\Jobs\DraftGoogleAdsAdCopyJob;
use App\Livewire\Operator\Advisor\AdvisorPanel;
use App\Livewire\Operator\Archive\ProductionArchivePage;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\AiProduction;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\SeoPlan;
use App\Models\SeoTask;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

final class ProductionArchiveTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $ads;

    private AdvisorItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Atlas Dental']);
        $this->ads = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'name' => 'Atlas Ads']);
        $plan = AdvisorPlan::query()->create(['channel' => 'google_ads', 'brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->ads->id, 'status' => 'completed', 'completed_at' => now()]);
        $this->item = AdvisorItem::query()->create([
            'channel' => 'google_ads', 'customer_id' => $this->brand->customer_id, 'brand_id' => $this->brand->id, 'digital_asset_id' => $this->ads->id,
            'item_key' => 'k1', 'category' => 'ads', 'rule_id' => 'weak-ad-strength', 'severity' => 'medium', 'priority_score' => 300,
            'title' => 'İmplant reklam grubu metinleri zayıf', 'reason' => 'r', 'evidence' => [], 'checklist' => [], 'status' => 'open', 'currency' => 'TRY',
            'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id,
        ]);
    }

    public function test_every_new_draft_becomes_a_version_and_nothing_is_overwritten(): void
    {
        $this->draft(['headlines' => ['Kadıköy İmplant'], 'descriptions' => ['Randevu alın.']]);
        $this->draft(['headlines' => ['Kadıköy İmplant'], 'descriptions' => ['Randevu alın.']]); // same content again
        DB::table('advisor_items')->where('id', $this->item->id)->update(['draft_status' => 'failed', 'draft' => json_encode(['error' => 'x'])]);
        $this->draft(['headlines' => ['Diş İmplantı Kadıköy'], 'descriptions' => ['Hemen arayın.']]);

        $versions = AiProduction::query()->where('subject_type', 'AdvisorItem')->where('subject_id', $this->item->id)->orderBy('version')->get();
        $this->assertSame([1, 2], $versions->pluck('version')->all());
        $this->assertSame('google_ads.ad_copy', $versions[0]->kind);
        $this->assertSame(['Kadıköy İmplant'], $versions[0]->content['headlines']);
        $this->assertSame($this->brand->id, $versions[1]->brand_id);
    }

    public function test_seo_brief_from_ai_is_archived_rules_brief_is_not(): void
    {
        $site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website']);
        $plan = SeoPlan::query()->create(['brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $site->id, 'status' => 'completed', 'completed_at' => now()]);
        $task = SeoTask::query()->create([
            'customer_id' => $this->brand->customer_id, 'brand_id' => $this->brand->id, 'digital_asset_id' => $site->id, 'task_key' => 't', 'type' => 'strengthen',
            'rule_id' => 'x', 'severity' => 'medium', 'priority_score' => 1, 'title' => 'İmplant sayfası', 'reason' => 'r', 'evidence' => [], 'checklist' => [],
            'status' => 'open', 'content_brief' => ['source' => 'rules', 'outline' => ['a']], 'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id,
        ]);
        $this->assertSame(0, AiProduction::query()->count());

        $task->forceFill(['content_brief' => ['source' => 'llm', 'outline' => ['Giriş', 'Süreç', 'SSS']]])->save();

        $production = AiProduction::query()->sole();
        $this->assertSame('seo.content_brief', $production->kind);
        $this->assertSame(['Giriş', 'Süreç', 'SSS'], $production->content['outline']);
    }

    public function test_fresh_archived_draft_is_restored_instead_of_a_new_ai_call(): void
    {
        Queue::fake();
        $this->draft(['headlines' => ['Kadıköy İmplant'], 'descriptions' => ['Randevu alın.']]);
        DB::table('advisor_items')->where('id', $this->item->id)->update(['draft_status' => 'failed', 'draft' => json_encode(['error' => 'x'])]);

        Livewire::test(AdvisorPanel::class, ['assetId' => $this->ads->id])->call('requestDraft', $this->item->id)->assertSee('arşivden geri yüklendi');

        Queue::assertNothingPushed();
        $this->assertSame('ready', $this->item->fresh()->draft_status);
        $this->assertSame(['Kadıköy İmplant'], $this->item->fresh()->draft['headlines']);

        // A ready draft + "Yeniden hazırla" makes a real AI request.
        Livewire::test(AdvisorPanel::class, ['assetId' => $this->ads->id])->call('requestDraft', $this->item->id);
        Queue::assertPushed(DraftGoogleAdsAdCopyJob::class);
    }

    public function test_archive_page_filters_marks_and_rates(): void
    {
        $this->draft(['headlines' => ['Kadıköy İmplant'], 'descriptions' => ['Randevu alın.']]);
        $production = AiProduction::query()->sole();

        Livewire::test(ProductionArchivePage::class)
            ->assertSee('Üretim Arşivi')
            ->assertSee('İmplant reklam grubu metinleri zayıf')
            ->assertSee('Kadıköy İmplant')
            ->call('mark', $production->id, 'published')
            ->call('rate', $production->id, 1)
            ->set('kind', 'seo.content_brief')
            ->assertDontSee('İmplant reklam grubu metinleri zayıf');

        $this->assertSame(['published', 1], [$production->fresh()->status, $production->fresh()->rating]);
        $this->get(route('operator.archive', ['subject' => 'AdvisorItem:'.$this->item->id]))->assertOk()->assertSee('Kadıköy İmplant');
    }

    /** @param  array<string, mixed>  $draft */
    private function draft(array $draft): void
    {
        $this->item->refresh()->forceFill(['draft' => $draft + ['provider' => 'anthropic', 'model' => 'm', 'created_at' => now()->toIso8601String()], 'draft_status' => 'ready'])->save();
        $this->travel(1)->minutes();
    }
}
