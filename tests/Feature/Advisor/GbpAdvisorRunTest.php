<?php

namespace Tests\Feature\Advisor;

use App\Enums\DigitalAssetStatus;
use App\Jobs\DraftGbpProfileJob;
use App\Livewire\Operator\Advisor\AdvisorPanel;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Advisor\AdvisorPlanRunner;
use App\Services\Advisor\Gbp\GbpProfileDrafter;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Support\Roles;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Business Profile advisor end-to-end: stored gbp_* rows → run → items → panel and profile tab.
 */
final class GbpAdvisorRunTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    private CoreExternalResource $location;

    private Brand $brand;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00', 'UTC'));
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Örnek Klinik']);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'status' => DigitalAssetStatus::Active, 'name' => 'Örnek Klinik Profil']);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r'], 'expires_at' => now()->addHour()]);
        $this->location = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => 'google_business_profile', 'external_id' => 'locations/1', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->asset->id, 'external_resource_id' => $this->location->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
    }

    public function test_run_finds_profile_keyword_performance_and_rating_issues(): void
    {
        $this->seedProfile();

        $runner = app(AdvisorPlanRunner::class);
        $plan = $runner->run($runner->queue($this->asset, $this->admin)->id);
        $this->assertSame(AdvisorPlan::CHANNEL_GBP, $plan->channel);
        $this->assertSame(AdvisorPlan::STATUS_COMPLETED, $plan->status);
        $items = AdvisorItem::query()->get()->keyBy('rule_id');
        foreach (['profile-gaps', 'keyword-service-gaps', 'profile-actions-drop', 'rating-trend', 'website-utm'] as $rule) {
            $this->assertTrue($items->has($rule), $rule.' should fire');
        }
        $this->assertFalse($items->has('photo-freshness'), 'no media collected → silent');
        $this->assertFalse($items->has('site-profile-services'), 'the missing service is already in the keyword item');

        $gaps = collect($items['profile-gaps']->evidence['issues'])->pluck('issue')->implode(' ');
        $this->assertStringContainsString('Çalışma saatleri', $gaps);
        $this->assertStringContainsString('Yalnızca birincil kategori', $gaps);
        $this->assertStringContainsString('Açıklama kısa', $gaps);

        $keywords = collect($items['keyword-service-gaps']->evidence['keywords'])->keyBy('keyword');
        $this->assertSame('İmplant', $keywords['implant fiyatları']['offering']);
        $this->assertNull($keywords['ortodonti']['offering'], 'not a brand service: flagged as a possible new service');
        $this->assertFalse($keywords->has('örnek klinik'), 'brand search excluded');
        $this->assertFalse($keywords->has('diş beyazlatma'), 'already a profile service');
        $this->assertSame('İmplant', $items['keyword-service-gaps']->copy_text);
        $this->assertStringContainsString('utm_source=google', $items['website-utm']->copy_text);
        $this->assertSame('high', $items['profile-actions-drop']->severity);

        Livewire::test(AdvisorPanel::class)->set('channelFilter', 'google_business_profile')->assertSee('Profil eksikleri')->assertSee('İşletme Profili');
        Queue::fake();
        Livewire::test(AdvisorPanel::class, ['assetId' => $this->asset->id])->call('requestDraft', $items['profile-gaps']->id)->assertSee('taslağı hazırlanıyor');
        Queue::assertPushed(DraftGbpProfileJob::class);

        // The profile page renders for a connected profile (previously an undefined constant) and has the tab.
        $this->get(route('operator.gbp', ['assetId' => $this->asset->id, 'tab' => 'advisor']))->assertOk()->assertSee('Danışman');
    }

    public function test_silent_without_binding_and_draft_rejects_links(): void
    {
        CoreAssetBinding::query()->update(['status' => 'inactive']);
        $runner = app(AdvisorPlanRunner::class);
        $this->assertSame('İşletme Profili hesabı bağlı değil.', $runner->run($runner->queue($this->asset, $this->admin)->id)->summary_text);

        $draft = app(GbpProfileDrafter::class)->validate([
            'profile_descriptions' => ['Kadıköy\'de implant ve estetik diş hizmetleri sunan klinik.', 'Bize www.ornek.test adresinden ulaşın.', str_repeat('a', 751)],
            'service_descriptions' => ['İmplant: eksik dişler için kalıcı çözüm.', 'Arayın 0216 555 00 00'],
            'notes' => 'Kontrol et.',
        ]);
        $this->assertSame(['Kadıköy\'de implant ve estetik diş hizmetleri sunan klinik.'], $draft['profile_descriptions']);
        $this->assertSame(['İmplant: eksik dişler için kalıcı çözüm.'], $draft['service_descriptions']);
    }

    private function seedProfile(): void
    {
        $offerings = app(BrandOfferingService::class);
        $implant = $offerings->resolveOrCreate($this->brand, 'İmplant', actor: $this->admin)['offering'];
        $implant->forceFill(['is_priority' => true])->save();
        $offerings->resolveOrCreate($this->brand, 'Diş Beyazlatma', actor: $this->admin);

        $base = ['digital_asset_id' => null, 'external_resource_id' => $this->location->id, 'location_name' => 'locations/1', 'run_id' => 99, 'created_at' => now(), 'updated_at' => now()];
        DB::table('gbp_location_snapshots')->insert(['run_id' => 1] + $base + [
            'captured_at' => now()->subDay(), 'title' => 'Örnek Klinik', 'primary_category' => 'Diş Kliniği',
            'additional_categories' => json_encode(['primaryCategory' => ['displayName' => 'Diş Kliniği'], 'additionalCategories' => []]),
            'phone_numbers' => json_encode(['primaryPhone' => '+90 216 555 00 00']), 'website_uri' => 'https://ornek.test/',
            'regular_hours' => json_encode(['periods' => []]), 'profile' => json_encode(['description' => 'Diş kliniği.']),
            'open_info' => json_encode(['status' => 'OPEN']), 'provider_metadata' => json_encode(['canModifyServiceList' => true]),
        ]);
        DB::table('gbp_service_snapshots')->insert(['run_id' => 2] + $base + ['captured_at' => now()->subDay(), 'service_items' => json_encode([['freeFormServiceItem' => ['category' => 'x', 'label' => ['displayName' => 'Diş Beyazlatma']]]])]);

        foreach ([['implant fiyatları', 120], ['ortodonti', 90], ['örnek klinik', 500], ['diş beyazlatma', 80]] as [$keyword, $impressions]) {
            DB::table('gbp_search_keywords_monthly')->insert($base + ['month_start' => '2026-08-01', 'search_keyword' => $keyword, 'search_keyword_hash' => hash('sha256', $keyword), 'impressions' => $impressions, 'collected_at' => now()]);
        }
        DB::table('gbp_search_keywords_monthly')->insert($base + ['month_start' => '2026-08-01', 'search_keyword' => 'nadir arama', 'search_keyword_hash' => hash('sha256', 'nadir'), 'impressions' => null, 'threshold' => 15, 'collected_at' => now()]);

        $end = CarbonImmutable::parse('2026-09-22');
        for ($day = 0; $day < 56; $day++) {
            $date = $end->subDays($day)->toDateString();
            DB::table('gbp_performance_daily')->insert($base + ['reporting_date' => $date, 'metric' => 'CALL_CLICKS', 'value' => $day < 28 ? 1 : 3, 'collected_at' => now()]);
            DB::table('gbp_performance_daily')->insert($base + ['reporting_date' => $date, 'metric' => 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'value' => 100, 'collected_at' => now()]);
        }
        foreach (range(1, 10) as $i) {
            DB::table('gbp_reviews')->insert($base + ['review_id' => 'old'.$i, 'star_rating' => 'FIVE', 'create_time' => '2026-03-0'.min(9, $i).' 10:00:00', 'raw_payload' => '{}', 'collected_at' => now()]);
        }
        foreach (range(1, 6) as $i) {
            DB::table('gbp_reviews')->insert($base + ['review_id' => 'new'.$i, 'star_rating' => $i % 2 ? 'TWO' : 'THREE', 'create_time' => '2026-09-1'.$i.' 10:00:00', 'raw_payload' => '{}', 'collected_at' => now()]);
        }
    }
}
