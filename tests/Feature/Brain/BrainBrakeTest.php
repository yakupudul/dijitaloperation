<?php

namespace Tests\Feature\Brain;

use App\Enums\DigitalAssetStatus;
use App\Livewire\Operator\Settings\SectorPacksPage;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Advisor\GoogleAds\GoogleAdsAdCopyDrafter;
use App\Services\Brain\RecommendationWriter;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/** Brain phase 6: sector rules brake recommendations, feed the ad drafters, and the optional legal gate. */
final class BrainBrakeTest extends TestCase
{
    use RefreshDatabase;

    private Brand $clinic;

    private Brand $lawyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinic = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas Diş', 'sector' => 'dental']);
        $this->lawyer = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Mavi Hukuk', 'sector' => 'retail']);
    }

    public function test_health_brands_are_not_told_what_their_sector_forbids(): void
    {
        $item = fn (string $type, string $title, array $evidence = [], string $channel = 'website'): array => ['key' => $type.$title, 'channel' => $channel, 'type' => $type, 'title' => $title, 'detail' => '', 'evidence' => $evidence];
        $writer = app(RecommendationWriter::class);

        $writer->sync(['source' => 'test', 'brand_id' => $this->clinic->id], [
            $item('website_method:has_price', 'İmplant: sayfada fiyat verin'),
            $item('meta_try_angle', 'Meta: fiyat açısı', ['angle' => 'price_offer'], 'meta_ads'),
            $item('website_subtopics', 'İmplant: garantili sonuç bölümü ekleyin'),
            $item('website_method:faq', 'İmplant: SSS ekleyin'),
        ]);
        $writer->sync(['source' => 'test', 'brand_id' => $this->lawyer->id], [$item('website_method:has_price', 'Boşanma: sayfada ücret verin')]);

        $clinic = DB::table('brain_recommendations')->where('brand_id', $this->clinic->id)->pluck('status', 'type');
        $this->assertSame('blocked', $clinic['website_method:has_price'], 'health: prices are never recommended');
        $this->assertSame('blocked', $clinic['meta_try_angle'], 'health: discount / price angle');
        $this->assertSame('blocked', $clinic['website_subtopics'], 'text with a forbidden claim ("garantili")');
        $this->assertSame('open', $clinic['website_method:faq']);
        $this->assertSame('open', DB::table('brain_recommendations')->where('brand_id', $this->lawyer->id)->value('status'), 'other sectors are not affected');
        $this->assertStringContainsString('Uyum kuralı', (string) DB::table('brain_recommendations')->where('type', 'website_subtopics')->value('detail'));
    }

    public function test_legal_gate_holds_paid_growth_for_health_brands_until_eligibility_is_recorded(): void
    {
        config(['moxdop-brain.legal.health_paid_ads_gate' => 1]);
        $grow = ['key' => 'c1', 'channel' => 'google_ads', 'type' => 'ads_missing_cluster', 'title' => '"İmplant" konusu için reklam grubu yok', 'detail' => ''];
        $writer = app(RecommendationWriter::class);

        $writer->sync(['source' => 'test', 'brand_id' => $this->clinic->id], [$grow]);
        $this->assertSame('blocked', DB::table('brain_recommendations')->value('status'));
        $this->assertStringContainsString('Yasal kapı', (string) DB::table('brain_recommendations')->value('detail'));

        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        Livewire::actingAs($admin)->test(SectorPacksPage::class)->assertSee('Yasal kapı')->assertSee('Atlas Diş')
            ->call('setEligibility', $this->clinic->id, 'health_tourism_abroad');

        app(RecommendationWriter::class)->sync(['source' => 'test', 'brand_id' => $this->clinic->id], [$grow]);
        $this->assertSame('open', DB::table('brain_recommendations')->value('status'), 'eligible now: the same recommendation opens');
    }

    public function test_ad_copy_drafts_get_the_brand_sector_rules_in_their_prompt(): void
    {
        $ads = DigitalAsset::factory()->create(['brand_id' => $this->clinic->id, 'type' => 'google_ads', 'status' => DigitalAssetStatus::Active]);
        $plan = AdvisorPlan::query()->create(['channel' => 'google_ads', 'brand_id' => $this->clinic->id, 'customer_id' => $this->clinic->customer_id, 'digital_asset_id' => $ads->id, 'status' => 'completed', 'completed_at' => now()]);
        $item = AdvisorItem::query()->create([
            'channel' => 'google_ads', 'customer_id' => $this->clinic->customer_id, 'brand_id' => $this->clinic->id, 'digital_asset_id' => $ads->id,
            'item_key' => 'k', 'category' => 'growth', 'rule_id' => 'weak-ad-strength', 'severity' => 'medium', 'priority_score' => 1,
            'title' => 't', 'reason' => 'r', 'evidence' => ['ad_group_id' => 'AG1'], 'checklist' => [], 'status' => 'open', 'currency' => 'TRY',
            'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id,
        ]);

        $context = (new ReflectionMethod(GoogleAdsAdCopyDrafter::class, 'context'))->invoke(app(GoogleAdsAdCopyDrafter::class), $item);

        $this->assertNotEmpty($context['compliance_rules']);
        $this->assertStringContainsString('garantili', implode(' ', $context['compliance_rules']));
    }
}
