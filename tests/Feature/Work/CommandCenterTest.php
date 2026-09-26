<?php

namespace Tests\Feature\Work;

use App\Livewire\Operator\Work\CommandCenterPage;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\SeoPlan;
use App\Models\SeoTask;
use App\Models\User;
use App\Services\CommandCenter\CommandCenter;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Komuta merkezi: one ranked, de-duplicated list across producers; actions close the item at its source. */
final class CommandCenterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $site;

    private DigitalAsset $ads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas Diş']);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => 'active', 'domain' => 'atlas.test', 'name' => 'atlas.test', 'module_id' => 'website']);
        $this->ads = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'status' => 'active', 'name' => 'Atlas Ads', 'module_id' => 'google_ads']);
    }

    public function test_items_are_merged_ranked_by_severity_and_impact_and_duplicates_are_hidden(): void
    {
        $adsPlan = AdvisorPlan::query()->create(['channel' => 'google_ads', 'brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->ads->id, 'status' => 'completed', 'completed_at' => now()]);
        $waste = $this->advisor($adsPlan, 'Boşa harcanan terimler', 'high', 'negative-keywords', 700, 4200);
        $covered = $this->advisor($adsPlan, 'Dönüşüm sinyali yok', 'high', 'primary-no-signal', 700, null);
        AssetAlert::query()->create(['digital_asset_id' => $this->ads->id, 'brand_id' => $this->brand->id, 'alert_key' => hash('sha256', 'conversions_stopped'), 'kind' => 'conversions_stopped',
            'severity' => 'critical', 'title' => 'Dönüşüm gelmiyor', 'message' => '3 gündür dönüşüm yok.', 'first_detected_at' => now(), 'last_detected_at' => now()]);
        $seoPlan = SeoPlan::query()->create(['brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->site->id, 'status' => 'completed', 'completed_at' => now()]);
        $meta = $this->seo($seoPlan, 'Meta açıklaması olmayan sayfalar (4)', 'medium', 'meta-missing', 300, null);
        $this->seo($seoPlan, 'İmplant sayfasını güçlendir', 'medium', 'weak-page', 300, 900);
        DB::table('site_fix_items')->insert(['digital_asset_id' => $this->site->id, 'brand_id' => $this->brand->id, 'type' => 'seo_description', 'label' => 'İmplant', 'status' => 'open',
            'item_key' => hash('sha256', 'a'), 'created_at' => now(), 'updated_at' => now()]);

        $items = app(CommandCenter::class)->items();
        $keys = $items->pluck('key')->all();

        $this->assertSame('alert', $items->first()['source'], 'a critical alert outranks every recommendation');
        $this->assertNotContains('advisor:'.$covered->id, $keys, 'the alert already says conversions stopped');
        $this->assertNotContains('seo:'.$meta->id, $keys, 'the ready site fix repairs the missing meta descriptions');
        $this->assertContains('site_fix:'.$this->site->id, $keys);
        $this->assertLessThan(array_search('seo:'.$meta->id, $keys) ?: PHP_INT_MAX, array_search('advisor:'.$waste->id, $keys));
        $this->assertEqualsWithDelta(4200.0, app(CommandCenter::class)->summary()['money'], 0.01);

        $this->actingAs($this->admin)->get(route('operator.command-center'))->assertOk()->assertSee('Komuta merkezi')->assertSee('Dönüşüm gelmiyor')->assertSee('Boşa harcanan terimler');
    }

    public function test_actions_close_items_at_their_source_and_snooze_hides_them(): void
    {
        $adsPlan = AdvisorPlan::query()->create(['channel' => 'google_ads', 'brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->ads->id, 'status' => 'completed', 'completed_at' => now()]);
        $item = $this->advisor($adsPlan, 'Negatif kelimeler', 'high', 'negative-keywords', 700, 100);
        $rec = DB::table('brain_recommendations')->insertGetId(['brand_id' => $this->brand->id, 'source' => 'brain_gaps', 'channel' => 'website', 'type' => 'website_method:faq', 'title' => 'SSS ekle',
            'basis' => 'observational', 'fingerprint' => 'f', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);

        Livewire::actingAs($this->admin)->test(CommandCenterPage::class)
            ->set('bulkIds', ['advisor:'.$item->id])->call('act', 'done')
            ->call('act', 'snooze', 'brain:'.$rec, 7)->assertOk();

        $this->assertSame('done', $item->fresh()->status->value);
        $this->assertSame('open', DB::table('brain_recommendations')->where('id', $rec)->value('status'), 'snooze keeps the source open');
        $this->assertNotContains('brain:'.$rec, app(CommandCenter::class)->items()->pluck('key')->all());
        $this->travel(8)->days();
        $this->assertContains('brain:'.$rec, app(CommandCenter::class)->items()->pluck('key')->all(), 'back after the snooze');
    }

    private function advisor(AdvisorPlan $plan, string $title, string $severity, string $rule, float $score, ?float $money): AdvisorItem
    {
        return AdvisorItem::query()->create([
            'channel' => 'google_ads', 'customer_id' => $this->brand->customer_id, 'brand_id' => $this->brand->id, 'digital_asset_id' => $this->ads->id,
            'item_key' => hash('sha256', $title), 'category' => 'waste', 'rule_id' => $rule, 'severity' => $severity, 'priority_score' => $score, 'impact_amount' => $money,
            'title' => $title, 'reason' => 'r', 'evidence' => [], 'checklist' => [], 'status' => 'open', 'currency' => 'TRY',
            'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id,
        ]);
    }

    private function seo(SeoPlan $plan, string $title, string $severity, string $rule, float $score, ?float $clicks): SeoTask
    {
        return SeoTask::query()->create([
            'customer_id' => $this->brand->customer_id, 'brand_id' => $this->brand->id, 'digital_asset_id' => $this->site->id,
            'task_key' => hash('sha256', $title), 'type' => 'fix', 'rule_id' => $rule, 'severity' => $severity, 'estimated_extra_clicks' => $clicks,
            'priority_score' => $score, 'title' => $title, 'reason' => 'r', 'evidence' => [], 'checklist' => [], 'status' => 'open',
            'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id,
        ]);
    }
}
