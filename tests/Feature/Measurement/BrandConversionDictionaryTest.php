<?php

namespace Tests\Feature\Measurement;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Portfolio\BrandConversions;
use App\Models\Brand;
use App\Models\BrandConversionSource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Measurement\BrandConversionDictionary;
use App\Support\BrandIntelligence\ConversionGoalTypes;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class BrandConversionDictionaryTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Atlas Dental']);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'primary_url' => 'https://atlasdis.com/', 'domain' => 'atlasdis.com']);

        $this->pool('ga4_property_metadata', ['property_id' => '111', 'metadata' => json_encode(['key_events' => [['eventName' => 'generate_lead'], ['eventName' => 'whatsapp_click']]])]);
        $this->ga4('generate_lead', 5, days: 3);
        $this->ga4('generate_lead', 2, days: 40);
        $this->ga4('telefon_tiklama', 4, days: 2);
        $this->ga4('page_view', 900, days: 2);

        $this->adsAction('11', 'Website lead', 'SUBMIT_LEAD_FORM', 'WEBPAGE');
        $this->adsAction('12', 'Calls from ads', 'PHONE_CALL_LEAD', 'AD_CALL');
        $this->adsAction('13', 'GA4 generate_lead', 'SUBMIT_LEAD_FORM', 'GOOGLE_ANALYTICS_4_CUSTOM');
        $this->adsAction('14', 'Old action', 'DEFAULT', 'WEBPAGE', status: 'REMOVED');
        $this->adsDaily('11', 6);
        $this->adsDaily('12', 3);

        foreach (['onsite_conversion.lead_grouped' => 7, 'offsite_conversion.fb_pixel_lead' => 4, 'lead' => 11, 'link_click' => 300] as $type => $value) {
            $this->pool('meta_typed_action_daily', ['account_id' => 'act_1', 'reporting_date' => now()->subDays(4)->toDateString(), 'entity_level' => 'campaign', 'entity_id' => 'c1', 'action_type' => $type, 'action_value' => $value]);
            $this->pool('meta_typed_action_daily', ['account_id' => 'act_1', 'reporting_date' => now()->subDays(4)->toDateString(), 'entity_level' => 'ad', 'entity_id' => 'a1', 'action_type' => $type, 'action_value' => $value]);
        }
        foreach (['CALL_CLICKS' => 8, 'WEBSITE_CLICKS' => 20, 'BUSINESS_IMPRESSIONS_MOBILE_MAPS' => 1000] as $metric => $value) {
            DB::table('gbp_performance_daily')->insert([
                'digital_asset_id' => $this->asset->id, 'external_resource_id' => 1, 'run_id' => 1, 'location_name' => 'locations/1',
                'reporting_date' => now()->subDays(5)->toDateString(), 'metric' => $metric, 'value' => $value, 'collected_at' => now(),
            ]);
        }
    }

    public function test_discovers_signals_with_types_and_non_duplicating_defaults(): void
    {
        $stats = app(BrandConversionDictionary::class)->discover($this->brand);

        $rows = BrandConversionSource::query()->where('brand_id', $this->brand->id)->get()->keyBy(fn ($r) => $r->source.'|'.$r->source_key);
        $this->assertSame($stats['found'], $rows->count());
        $this->assertTrue($rows['ga4_key_event|generate_lead']->counts);
        $this->assertSame(ConversionGoalTypes::FORM_SUBMISSION, $rows['ga4_key_event|generate_lead']->conversion_type);
        $this->assertSame(ConversionGoalTypes::WHATSAPP_CONVERSATION, $rows['ga4_key_event|whatsapp_click']->conversion_type, 'configured key event without data yet');
        $this->assertSame(ConversionGoalTypes::PHONE_CALL, $rows['ga4_key_event|telefon_tiklama']->conversion_type);
        $this->assertFalse($rows['ga4_key_event|page_view']->counts, 'engagement event');

        $this->assertFalse($rows['google_ads_conversion_action|11']->counts, 'website action: GA4 already counts the site');
        $this->assertTrue($rows['google_ads_conversion_action|12']->counts, 'calls from ads exist only in Ads');
        $this->assertSame(ConversionGoalTypes::PHONE_CALL, $rows['google_ads_conversion_action|12']->conversion_type);
        $this->assertFalse($rows['google_ads_conversion_action|13']->counts, 'imported from GA4');
        $this->assertArrayNotHasKey('google_ads_conversion_action|14', $rows->all());

        $this->assertTrue($rows['meta_action|onsite_conversion.lead_grouped']->counts);
        $this->assertFalse($rows['meta_action|offsite_conversion.fb_pixel_lead']->counts, 'pixel = website');
        $this->assertFalse($rows['meta_action|lead']->counts, 'aggregate incl. pixel');
        $this->assertArrayNotHasKey('meta_action|link_click', $rows->all());

        $this->assertTrue($rows['gbp_metric|CALL_CLICKS']->counts);
        $this->assertFalse($rows['gbp_metric|WEBSITE_CLICKS']->counts);
        $this->assertArrayNotHasKey('gbp_metric|BUSINESS_IMPRESSIONS_MOBILE_MAPS', $rows->all());
    }

    public function test_totals_count_only_counted_signals_once(): void
    {
        $dictionary = app(BrandConversionDictionary::class);
        $dictionary->discover($this->brand);

        $summary = $dictionary->summary($this->brand);

        // GA4 5 + 4, Ads calls 3, Meta instant forms 7 (one entity level), GBP calls 8.
        $this->assertSame(27.0, $summary['current']['total']);
        $this->assertSame(5.0 + 7.0, $summary['current']['by_type'][ConversionGoalTypes::FORM_SUBMISSION]);
        $this->assertSame(4.0 + 3.0 + 8.0, $summary['current']['by_type'][ConversionGoalTypes::PHONE_CALL]);
        $this->assertSame(2.0, $summary['previous']['total']);
        $this->assertSame(1250.0, $summary['change_pct']);
    }

    public function test_operator_choice_is_kept_by_rediscovery(): void
    {
        $dictionary = app(BrandConversionDictionary::class);
        $dictionary->discover($this->brand);
        $row = BrandConversionSource::query()->where('source_key', '11')->sole();

        $dictionary->set($row, ConversionGoalTypes::QUALIFIED_LEAD, true);
        $dictionary->discover($this->brand);

        $row->refresh();
        $this->assertTrue($row->counts);
        $this->assertSame(ConversionGoalTypes::QUALIFIED_LEAD, $row->conversion_type);
        $this->assertSame(BrandConversionSource::ORIGIN_OPERATOR, $row->origin);
    }

    public function test_brand_section_discovers_and_toggles(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        $component = Livewire::test(BrandConversions::class, ['brandId' => $this->brand->id])
            ->assertSee('Dönüşümler (son 30 gün)')
            ->assertSee('generate_lead')
            ->assertSee('Haritalar: arama');
        $row = BrandConversionSource::query()->where('source_key', 'page_view')->sole();

        $component->call('toggleCounts', $row->id)->call('setType', $row->id, ConversionGoalTypes::CUSTOM);

        $this->assertTrue($row->fresh()->counts);
        $this->assertSame(BrandConversionSource::ORIGIN_OPERATOR, $row->fresh()->origin);
    }

    private function ga4(string $event, int $keyEvents, int $days): void
    {
        $this->pool('ga4_key_event_daily', ['external_resource_id' => 1, 'property_id' => '111', 'reporting_date' => now()->subDays($days)->toDateString(), 'eventName' => $event, 'keyEvents' => $keyEvents]);
    }

    private function adsAction(string $id, string $name, string $category, string $type, string $status = 'ENABLED'): void
    {
        $this->pool('google_ads_conversion_action_snapshot', ['customer_id' => '123', 'conversion_action_id' => $id, 'metadata' => json_encode([
            'name' => $name, 'category' => $category, 'type' => $type, 'status' => $status, 'primary_for_goal' => true,
        ])]);
    }

    private function adsDaily(string $id, float $conversions): void
    {
        $this->pool('google_ads_conversion_action_daily', ['customer_id' => '123', 'reporting_date' => now()->subDays(2)->toDateString(), 'conversion_action_id' => $id, 'conversions' => $conversions]);
    }

    /** @param  array<string, mixed>  $values */
    private function pool(string $table, array $values): void
    {
        DB::table($table)->insert($values + [
            'digital_asset_id' => $this->asset->id, 'contract_version' => 1, 'first_collected_at' => now(),
            'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
        ]);
    }
}
