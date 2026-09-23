<?php

namespace Tests\Feature\Advisor;

use App\Enums\AdvisorItemStatus;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Mail\AdvisorWeeklyDigestMail;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\SeoPlan;
use App\Models\SeoTask;
use App\Models\User;
use App\Services\Advisor\AdvisorOutcomeMeasurer;
use App\Services\Advisor\AdvisorWorkQueue;
use App\Services\Advisor\Cross\CrossChannelRuleEngine;
use App\Services\ClientValueStory\ClientValueStoryReadService;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 6: one work list (dashboard + brand page), cross-channel rules, 28-day outcome measurement,
 * the report section and the weekly digest.
 */
final class AdvisorFaz6Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $site;

    private DigitalAsset $ads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00', 'UTC'));
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'email' => 'ops@example.test']);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Örnek Klinik']);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => 'active', 'domain' => 'ornekklinik.com', 'name' => 'ornekklinik.com', 'module_id' => 'website']);
        $this->ads = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'status' => 'active', 'name' => 'Örnek Ads', 'module_id' => 'google_ads']);
    }

    public function test_work_queue_merges_seo_and_advisor_and_shows_on_dashboard_and_brand_page(): void
    {
        $seoPlan = SeoPlan::query()->create(['brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->site->id, 'status' => 'completed', 'completed_at' => now(), 'summary_text' => '3 görev']);
        $this->seoTask($seoPlan, 'Kritik SEO düzeltmesi', 'critical', 950);
        $this->seoTask($seoPlan, 'İçerik yaz', 'medium', 300);
        $adsPlan = AdvisorPlan::query()->create(['channel' => 'google_ads', 'brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->ads->id, 'status' => 'completed', 'completed_at' => now(), 'summary_text' => '1 öneri']);
        $this->advisorItem($adsPlan, 'Negatif anahtar kelime listesi', 'high', 800);

        $top = app(AdvisorWorkQueue::class)->top(5);
        $this->assertSame(['Kritik SEO düzeltmesi', 'Negatif anahtar kelime listesi'], array_column($top, 'title'), 'max 2 per brand, by priority');
        $this->assertCount(3, app(AdvisorWorkQueue::class)->top(5, $this->brand->id));
        $channels = collect(app(AdvisorWorkQueue::class)->brandChannels($this->brand))->keyBy('channel');
        $this->assertSame(2, $channels['Web / SEO']['open']);
        $this->assertSame(1, $channels['Google Ads']['urgent']);

        $this->get(route('operator.dashboard'))->assertOk()->assertSee(__('operator.dashboard_exec.weekly_top'))->assertSee('Kritik SEO düzeltmesi');
        Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])->assertSee('Danışman')->assertSee('Önce bunlar')->assertSee('Negatif anahtar kelime listesi');
    }

    public function test_cross_channel_rules(): void
    {
        $input = [
            'bound' => true,
            'asset' => ['id' => 1, 'name' => 'ornekklinik.com', 'brand_name' => 'Örnek Klinik'],
            'currency' => 'TRY',
            'period' => ['end' => '2026-09-20', 'days' => 90],
            'ads_terms' => [
                'zirkonyum kaplama fiyat' => ['term' => 'zirkonyum kaplama fiyat', 'cost' => 400.0, 'clicks' => 40, 'conversions' => 5.0],
                'implant tedavisi' => ['term' => 'implant tedavisi', 'cost' => 300.0, 'clicks' => 30, 'conversions' => 4.0],
                'ornek klinik' => ['term' => 'örnek klinik', 'cost' => 500.0, 'clicks' => 200, 'conversions' => 10.0],
            ],
            'gbp_keywords' => ['diş taşı temizliği' => 80, 'implant tedavisi' => 90, 'örnek klinik' => 400],
            'gsc_available' => true,
            'gsc_queries' => ['ornek klinik' => ['impressions' => 900, 'clicks' => 500, 'position' => 1.1], 'zirkonyum kaplama fiyat' => ['impressions' => 100, 'clicks' => 1, 'position' => 24.0]],
            'pages' => [['url' => 'https://ornekklinik.com/implant-tedavisi/', 'text' => 'İmplant Tedavisi implant tedavisi']],
        ];
        $items = collect((new CrossChannelRuleEngine)->evaluate($input)['items'])->keyBy('rule_id');

        $this->assertSame(['zirkonyum kaplama fiyat'], array_column($items['ads-term-no-organic-page']['evidence']['terms'], 'term'), 'implant has a page; brand terms excluded');
        $this->assertTrue($items->has('paid-brand-search'));
        $this->assertSame(['diş taşı temizliği'], array_column($items['gbp-search-no-site-content']['evidence']['keywords'], 'keyword'));

        $input['gsc_queries']['ornek klinik']['position'] = 3.2;
        $this->assertFalse(collect((new CrossChannelRuleEngine)->evaluate($input)['items'])->contains('rule_id', 'paid-brand-search'), 'not first organically → no brand test');
        $this->assertSame(['not_bound'], (new CrossChannelRuleEngine)->evaluate(['bound' => false])['silenced']);
    }

    public function test_seo_outcome_is_measured_after_28_days_and_reported(): void
    {
        $plan = SeoPlan::query()->create(['brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->site->id, 'status' => 'completed', 'completed_at' => now()]);
        $task = $this->seoTask($plan, 'İmplant sayfasını güçlendir', 'medium', 500);
        $task->forceFill(['status' => 'done', 'resolved_at' => '2026-08-01 10:00:00', 'resolved_by' => $this->admin->id, 'target_url' => 'https://ornekklinik.com/implant/'])->save();
        foreach (['2026-07-15' => 10, '2026-08-10' => 25] as $date => $clicks) {
            DB::table('gsc_page_daily')->insert(['digital_asset_id' => $this->site->id, 'external_resource_id' => null, 'site_url' => 'sc-domain:ornekklinik.com', 'reporting_date' => $date, 'page' => 'https://ornekklinik.com/implant/', 'clicks' => $clicks, 'impressions' => $clicks * 20, 'search_type' => 'web', 'metadata' => '{}', 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $date), 'created_at' => now(), 'updated_at' => now()]);
        }
        $fresh = $this->seoTask($plan, 'Yeni yapılan', 'low', 100);
        $fresh->forceFill(['status' => 'done', 'resolved_at' => '2026-09-15 10:00:00'])->save();

        $this->assertSame(['advisor' => 0, 'seo' => 1], app(AdvisorOutcomeMeasurer::class)->measureDue(), 'only tasks past 28 days + GSC lag');
        $outcome = $task->fresh()->outcome;
        $this->assertSame('measured', $outcome['status']);
        $this->assertSame(10, $outcome['before']);
        $this->assertSame(25, $outcome['after']);
        $this->assertSame(150, $outcome['change_pct']);
        $this->assertNull($fresh->fresh()->measured_at);

        $story = app(ClientValueStoryReadService::class)->forBrand($this->brand, '2026-09-01', '2026-09-30')->toPresentationArray();
        $texts = collect($story['measured_work'])->keyBy('text');
        $this->assertStringContainsString('10 → 25', $texts['İmplant sayfasını güçlendir']['result']);
        $this->assertStringContainsString('28 gün sonra ölçülecek', $texts['Yeni yapılan']['result']);
    }

    public function test_negative_list_outcome_and_weekly_digest(): void
    {
        $plan = AdvisorPlan::query()->create(['channel' => 'google_ads', 'brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->ads->id, 'status' => 'completed', 'completed_at' => now()]);
        $item = $this->advisorItem($plan, 'Negatif liste', 'high', 800);
        $item->forceFill(['status' => AdvisorItemStatus::Done->value, 'resolved_at' => now()->subDays(40)])->save();
        $this->assertSame(['status' => 'not_measured'], app(AdvisorOutcomeMeasurer::class)->advisorOutcome($item->fresh(), 28), 'unbound account → no metric, still listed as done');
        app(AdvisorOutcomeMeasurer::class)->measureDue();
        $this->assertNotNull($item->fresh()->measured_at);

        $this->artisan('moxdop:advisor:digest')->expectsOutputToContain('kapalı')->assertSuccessful();
        Mail::fake();
        config(['mail.default' => 'array']);
        $open = $this->advisorItem($plan, 'Açık öneri', 'high', 700);
        $this->artisan('moxdop:advisor:digest', ['--force' => true])->assertSuccessful();
        Mail::assertSent(AdvisorWeeklyDigestMail::class, fn (AdvisorWeeklyDigestMail $mail): bool => $mail->hasTo('ops@example.test') && $mail->items[0]['title'] === $open->title);
    }

    private function seoTask(SeoPlan $plan, string $title, string $severity, float $score): SeoTask
    {
        return SeoTask::query()->create([
            'customer_id' => $this->brand->customer_id, 'brand_id' => $this->brand->id, 'digital_asset_id' => $this->site->id,
            'task_key' => hash('sha256', $title), 'type' => $severity === 'critical' ? 'fix' : 'strengthen', 'rule_id' => 'x', 'severity' => $severity,
            'priority_score' => $score, 'title' => $title, 'reason' => 'r', 'evidence' => [], 'checklist' => [], 'status' => 'open',
            'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id,
        ]);
    }

    private function advisorItem(AdvisorPlan $plan, string $title, string $severity, float $score): AdvisorItem
    {
        return AdvisorItem::query()->create([
            'channel' => $plan->channel, 'customer_id' => $this->brand->customer_id, 'brand_id' => $this->brand->id, 'digital_asset_id' => $plan->digital_asset_id,
            'item_key' => hash('sha256', $title), 'category' => 'waste', 'rule_id' => 'negative-keywords', 'severity' => $severity, 'priority_score' => $score,
            'title' => $title, 'reason' => 'r', 'evidence' => ['terms' => [['term' => 'ücretsiz']]], 'checklist' => [], 'status' => 'open', 'currency' => 'TRY',
            'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id,
        ]);
    }
}
