<?php

namespace Tests\Feature\Measurement;

use App\Enums\CustomerStatus;
use App\Jobs\Brand\RefreshBrandFilesJob;
use App\Livewire\Operator\Portfolio\BrandConversions;
use App\Models\Brand;
use App\Models\BrandConversionSource;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Brand\BrandFacts;
use App\Services\Measurement\BrandConversionDictionary;
use App\Services\Measurement\Ga4Conversions;
use App\Support\BrandIntelligence\ConversionGoalTypes;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

    public function test_attention_signals_are_never_counted_as_customers(): void
    {
        $this->ga4('form_start', 40, days: 2);
        $this->ga4('scroll_kaydirma_sayisi', 300, days: 2);
        $this->adsAction('15', 'Local actions - Website visits', 'DEFAULT', 'GOOGLE_HOSTED');
        $this->adsAction('16', 'Scroll Kaydırma Sayısı', 'DEFAULT', 'WEBPAGE');
        $this->adsAction('17', 'Local actions - Other engagements', 'DEFAULT', 'GOOGLE_HOSTED');
        $this->adsAction('18', 'Telefon tıklama', 'DEFAULT', 'CLICK_TO_CALL');
        $this->adsAction('19', 'Ana sayfa görüntüleme', 'PAGE_VIEW', 'WEBPAGE');

        app(BrandConversionDictionary::class)->discover($this->brand);

        $rows = BrandConversionSource::query()->where('brand_id', $this->brand->id)->get()->keyBy(fn ($r) => $r->source.'|'.$r->source_key);
        foreach (['ga4_key_event|form_start', 'ga4_key_event|scroll_kaydirma_sayisi', 'google_ads_conversion_action|15', 'google_ads_conversion_action|16', 'google_ads_conversion_action|17', 'google_ads_conversion_action|19'] as $key) {
            $this->assertFalse($rows[$key]->counts, $key);
        }
        $this->assertSame('engagement', $rows['google_ads_conversion_action|15']->metadata['note']);
        $this->assertTrue($rows['google_ads_conversion_action|18']->counts, 'a phone click is a customer');
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

    public function test_a_day_stored_both_centrally_and_on_the_asset_is_counted_once(): void
    {
        $ads = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads']);
        $resource = CoreExternalResource::factory()->create(['provider' => 'google', 'resource_type' => 'google_ads', 'external_id' => '123', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $ads->id, 'external_resource_id' => $resource->id, 'capability' => 'google_ads', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        // The same account's day stored twice: a legacy copy on the asset and the central row.
        $this->pool('google_ads_conversion_action_daily', ['digital_asset_id' => $ads->id, 'customer_id' => '123', 'reporting_date' => now()->subDays(2)->toDateString(), 'conversion_action_id' => '12', 'conversions' => 3]);
        $this->pool('google_ads_conversion_action_daily', ['digital_asset_id' => null, 'external_resource_id' => $resource->id, 'customer_id' => '123', 'reporting_date' => now()->subDays(2)->toDateString(), 'conversion_action_id' => '12', 'conversions' => 3]);
        $dictionary = app(BrandConversionDictionary::class);
        $dictionary->discover($this->brand);

        $row = BrandConversionSource::query()->where('brand_id', $this->brand->id)->where('source', 'google_ads_conversion_action')->where('source_key', '12')->firstOrFail();
        // 3 from the original per-asset row of the website asset + 3 central; the legacy copy on the Ads asset is dropped.
        $this->assertSame(6.0, $dictionary->summary($this->brand)['current']['by_row'][$row->id]);
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

        $component->call('toggleCounts', $row->id)->call('setType', $row->id, ConversionGoalTypes::CUSTOM)
            ->assertSee('Etkileşim sinyali müşteri sayılıyor');

        $this->assertTrue($row->fresh()->counts);
        $this->assertSame(BrandConversionSource::ORIGIN_OPERATOR, $row->fresh()->origin);
        $this->assertTrue($row->fresh()->metadata[BrandConversionDictionary::ENGAGEMENT_KEPT], 'counting an attention signal is marked');
    }

    public function test_changing_only_the_type_keeps_the_row_automatic_and_its_count(): void
    {
        $dictionary = app(BrandConversionDictionary::class);
        $dictionary->discover($this->brand);
        $row = BrandConversionSource::query()->where('source_key', '11')->sole();

        $dictionary->setType($row, ConversionGoalTypes::QUALIFIED_LEAD);
        DB::table('google_ads_conversion_action_snapshot')->where('conversion_action_id', '11')->update(['metadata' => json_encode([
            'name' => 'Website lead', 'category' => 'PHONE_CALL_LEAD', 'type' => 'AD_CALL', 'status' => 'ENABLED', 'primary_for_goal' => true,
        ])]);
        $dictionary->discover($this->brand);

        $row->refresh();
        $this->assertSame(BrandConversionSource::ORIGIN_AUTO, $row->origin);
        $this->assertTrue($row->counts, 'the count still follows discovery');
        $this->assertSame(ConversionGoalTypes::QUALIFIED_LEAD, $row->conversion_type, 'the operator\'s type is kept');
    }

    public function test_clean_up_stops_automatic_attention_signals_and_flags_the_operators(): void
    {
        $auto = $this->row('google_ads_conversion_action', '15', 'Local actions - Website visits', BrandConversionSource::ORIGIN_AUTO, ['category' => 'DEFAULT']);
        $scroll = $this->row('ga4_key_event', 'scroll', 'scroll', BrandConversionSource::ORIGIN_AUTO);
        $operator = $this->row('ga4_key_event', 'form_start', 'form_start', BrandConversionSource::ORIGIN_OPERATOR);
        $lead = $this->row('ga4_key_event', 'generate_lead', 'generate_lead', BrandConversionSource::ORIGIN_AUTO);
        $dictionary = app(BrandConversionDictionary::class);

        $this->assertFalse($dictionary->countsAsCustomer($operator), 'an unflagged attention signal is not in the total');

        $result = $dictionary->cleanEngagement();

        $this->assertSame(['switched' => 2, 'flagged' => 1, 'brands' => [$this->brand->id]], $result);
        $this->assertFalse($auto->fresh()->counts);
        $this->assertFalse($scroll->fresh()->counts);
        $this->assertTrue($operator->fresh()->counts, 'the operator\'s choice is not switched silently');
        $this->assertTrue($operator->fresh()->metadata[BrandConversionDictionary::ENGAGEMENT_KEPT]);
        $this->assertTrue($dictionary->countsAsCustomer($operator->fresh()));
        $this->assertTrue($lead->fresh()->counts);
        $this->assertSame(['switched' => 0, 'flagged' => 0, 'brands' => []], $dictionary->cleanEngagement(), 'runs once');

        $dictionary->discover($this->brand);
        $this->assertTrue($operator->fresh()->metadata[BrandConversionDictionary::ENGAGEMENT_KEPT], 'discovery keeps the mark');
        $card = app(BrandFacts::class)->build($this->brand)['fields']['conversions'];
        $this->assertContains('form_start (GA4) · Etkileşim sinyali müşteri sayılıyor', $card['items']);
        $this->assertNotContains('scroll (GA4)', $card['items']);
    }

    public function test_the_migration_cleans_up_and_rebuilds_the_brand_files(): void
    {
        Queue::fake();
        config(['moxdop.brand_files_live_refresh' => true]);
        $auto = $this->row('ga4_key_event', 'scroll', 'scroll', BrandConversionSource::ORIGIN_AUTO);

        (require database_path('migrations/2026_12_11_110000_stop_counting_attention_conversion_signals.php'))->up();

        $this->assertFalse($auto->fresh()->counts);
        Queue::assertPushed(RefreshBrandFilesJob::class, fn (RefreshBrandFilesJob $job): bool => $job->brandId === $this->brand->id);
    }

    public function test_the_card_lists_every_counted_signal_with_its_source_and_says_how_many_more(): void
    {
        foreach (range(1, 10) as $i) {
            $this->row('google_ads_conversion_action', (string) (100 + $i), 'Arama '.$i, BrandConversionSource::ORIGIN_AUTO, ['category' => 'PHONE_CALL_LEAD']);
        }

        $field = app(BrandFacts::class)->build($this->brand)['fields']['conversions'];

        $this->assertCount(8, $field['items']);
        $this->assertSame('Arama 1 (Google Ads)', $field['items'][0]);
        $this->assertSame('+3 sinyal daha (Dönüşümler)', $field['items'][7]);
        $this->assertStringContainsString('İşletme Profili', $field['source']);
    }

    public function test_ga4_conversions_count_only_the_dictionary_events_not_page_views(): void
    {
        $ga4 = app(Ga4Conversions::class);
        $from = now()->subDays(10)->toDateString();
        $to = now()->toDateString();

        // Before discovery: every key event except the known attention events.
        $this->assertSame(9.0, array_sum($ga4->daily($this->brand, [1], $from, $to)));

        app(BrandConversionDictionary::class)->discover($this->brand);
        $ga4 = app(Ga4Conversions::class);
        $this->assertSame(9.0, array_sum($ga4->daily($this->brand, [1], $from, $to)), 'generate_lead 5 + telefon_tiklama 4, page_view 900 left out');
        $this->assertEqualsWithDelta(9 / 909, $ga4->share($this->brand, [1], $from, $to), 0.0001);
        $this->assertLessThan(Ga4Conversions::RELIABLE_SHARE, $ga4->share($this->brand, [1], $from, $to));
    }

    public function test_customer_share_leaves_out_ads_actions_that_count_page_visits(): void
    {
        $this->adsAction('15', 'Page visit', 'PAGE_VIEW', 'WEBPAGE');
        $this->adsDaily('15', 9);
        $dictionary = app(BrandConversionDictionary::class);
        $this->assertNull($dictionary->customerShare($this->brand, BrandConversionSource::SOURCE_GOOGLE_ADS, now()->subDays(29), now()));

        $dictionary->discover($this->brand);

        // Website lead 6 (not in the brand total because GA4 counts the site, still a customer) + calls 3 of 18.
        $this->assertSame(0.5, $dictionary->customerShare($this->brand, BrandConversionSource::SOURCE_GOOGLE_ADS, now()->subDays(29), now()));
    }

    /** @param  array<string, mixed>  $metadata */
    private function row(string $source, string $key, string $label, string $origin, array $metadata = []): BrandConversionSource
    {
        return BrandConversionSource::query()->create(['brand_id' => $this->brand->id, 'source' => $source, 'source_key' => $key, 'label' => $label,
            'conversion_type' => ConversionGoalTypes::CUSTOM, 'counts' => true, 'origin' => $origin, 'metadata' => $metadata]);
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
