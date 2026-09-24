<?php

namespace Tests\Feature\Measurement;

use App\Enums\CustomerStatus;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\BrandConversionSource;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Customer;
use App\Models\DataPool\RawIngestionObject;
use App\Models\DigitalAsset;
use App\Services\Alerts\AssetAlertScanner;
use App\Services\Measurement\TrackingHealthChecker;
use App\Services\Measurement\TrackingTagDetector;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TrackingHealthCheckerTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $website;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-23 09:00:00', 'UTC'));
        Storage::fake('tracking-test');
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $this->website = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'primary_url' => 'https://atlasdis.com/', 'domain' => 'atlasdis.com']);
        $this->pool('ga4_property_metadata', ['property_id' => '111', 'metadata' => json_encode(['data_streams' => [['webStreamData' => ['measurementId' => 'G-BOUND12345']]]])]);
    }

    public function test_tag_detection(): void
    {
        $tags = TrackingTagDetector::detect(<<<'HTML'
            <script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC123XYZ"></script>
            <script>gtag('config', 'G-ABC123XYZ'); gtag('config', 'AW-123456789');</script>
            <script>(function(w,d,s,l,i){})(window,document,'script','dataLayer','GTM-K9X8Z7');</script>
            <script>fbq('init', '123456789012345');</script>
            <p>G-Class araçlar</p>
            HTML);

        $this->assertSame(['GTM-K9X8Z7'], $tags['gtm']);
        $this->assertSame(['G-ABC123XYZ'], $tags['ga4']);
        $this->assertSame(['AW-123456789'], $tags['google_ads']);
        $this->assertSame(['123456789012345'], $tags['meta_pixel']);
    }

    public function test_missing_tag_and_wrong_measurement_id_on_homepage(): void
    {
        $this->homepage('<html><body>Merhaba</body></html>');
        $this->assertSame(['tracking_tag_missing'], $this->kinds());

        $this->homepage('<html><head><script src="https://www.googletagmanager.com/gtag/js?id=G-OTHER9999"></script></head></html>');
        $this->assertSame(['tracking_ga4_id_mismatch'], $this->kinds());

        $this->homepage('<html><head><script src="https://www.googletagmanager.com/gtag/js?id=G-BOUND12345"></script></head></html>');
        $this->assertSame([], $this->kinds());
    }

    public function test_ga4_no_data_while_collection_runs(): void
    {
        for ($day = 5; $day <= 20; $day++) {
            $this->sessions($day, 80);
        }
        DB::table('ga4_property_metadata')->update(['last_collected_at' => now()->subHours(6)]);

        $this->assertContains('ga4_no_data', $this->kinds());

        DB::table('ga4_property_metadata')->update(['last_collected_at' => now()->subDays(5)]);
        DB::table('ga4_acquisition_channel_daily')->update(['last_collected_at' => now()->subDays(5)]);
        $this->assertNotContains('ga4_no_data', $this->kinds(), 'collection not running: stale data, not "no data"');
    }

    public function test_website_conversions_stopped_and_not_defined(): void
    {
        for ($day = 1; $day <= 20; $day++) {
            $this->sessions($day, 50);
        }
        $this->assertContains('conversions_not_defined', $this->kinds(), '1000 sessions, nothing counted');

        BrandConversionSource::query()->create(['brand_id' => $this->brand->id, 'source' => 'ga4_key_event', 'source_key' => 'generate_lead', 'label' => 'generate_lead', 'conversion_type' => 'form_submission', 'counts' => true]);
        for ($day = 4; $day <= 20; $day++) {
            $this->pool('ga4_key_event_daily', ['external_resource_id' => 1, 'property_id' => '111', 'reporting_date' => now()->subDays($day)->toDateString(), 'eventName' => 'generate_lead', 'keyEvents' => 3]);
        }

        $kinds = $this->kinds();
        $this->assertContains('website_conversions_stopped', $kinds);
        $this->assertNotContains('conversions_not_defined', $kinds);
    }

    public function test_only_the_first_website_gets_brand_level_alerts(): void
    {
        $second = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'primary_url' => 'https://ikinci.com/', 'domain' => 'ikinci.com']);
        for ($day = 1; $day <= 20; $day++) {
            $this->sessions($day, 50);
        }

        $this->assertSame([], app(TrackingHealthChecker::class)->check($second));
    }

    /** @return list<string> */
    private function kinds(): array
    {
        app(AssetAlertScanner::class)->scan($this->website);

        return AssetAlert::query()->open()->where('digital_asset_id', $this->website->id)->where('kind', '!=', 'stale_data')->pluck('kind')->sort()->values()->all();
    }

    private function sessions(int $daysAgo, int $sessions): void
    {
        $this->pool('ga4_acquisition_channel_daily', ['property_id' => '111', 'reporting_date' => now()->subDays($daysAgo)->toDateString(), 'sessionDefaultChannelGroup' => 'Organic Search', 'sessions' => $sessions]);
    }

    private function homepage(string $html): void
    {
        $this->travel(1)->minutes();
        $resource = CollectionResourceRun::factory()->create(['digital_asset_id' => $this->website->id, 'provider_or_source' => 'website']);
        $key = (string) Str::uuid().'.html';
        Storage::disk('tracking-test')->put($key, $html);
        $object = RawIngestionObject::query()->create(['uuid' => (string) Str::uuid(),
            'resource_run_id' => $resource->id, 'collection_run_id' => $resource->collection_run_id, 'dataset_id' => 'website_html_snapshot',
            'batch_key' => $key, 'provider_or_source' => 'website', 'storage_disk' => 'tracking-test', 'object_key' => $key,
            'byte_size' => strlen($html), 'sha256' => hash('sha256', $html), 'captured_at' => now()]);
        DB::table('website_html_snapshot')->insert(['digital_asset_id' => $this->website->id,
            'url' => 'https://atlasdis.com/', 'raw_ingestion_object_id' => $object->id, 'html_hash' => hash('sha256', $html), 'status_code' => 200,
            'html_bytes' => strlen($html), 'change_state' => 'new', 'observed_at' => now(), 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $key)]);
    }

    /** @param  array<string, mixed>  $values */
    private function pool(string $table, array $values): void
    {
        DB::table($table)->insert($values + [
            'digital_asset_id' => $this->website->id, 'contract_version' => 1, 'first_collected_at' => now(),
            'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
        ]);
    }
}
