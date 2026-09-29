<?php

namespace Tests\Feature\Operations;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\Operations\Diagnostics\DiagnosticMasker;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * moxdop:diagnose: read-only portfolio diagnosis (text + JSON), --brand scope, no writes / jobs / HTTP, secrets masked.
 */
final class DiagnoseCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string TOKEN = 'ya29.a0AfB_byDiagnoseSecretToken1234567890abcdef';

    /** Built from parts so secret scanners don't mistake the fixture for a real key. */
    private const string LONG_SECRET = 'sk'.'_live_'.'FAKEdiagnoseFIXTUREvalue000000000';

    private Brand $panorama;

    private Brand $other;

    private DigitalAsset $website;

    private DigitalAsset $gsc;

    protected function setUp(): void
    {
        parent::setUp();

        $active = Customer::factory()->create(['status' => CustomerStatus::Active]);
        $passive = Customer::factory()->create(['status' => CustomerStatus::Inactive]);
        $this->panorama = Brand::factory()->create(['customer_id' => $active->id, 'name' => 'Panorama Dental']);
        $this->other = Brand::factory()->create(['customer_id' => $passive->id, 'name' => 'Başka Marka']);
        Brand::factory()->create(['customer_id' => $active->id, 'name' => 'Boş Marka']);

        $this->website = DigitalAsset::factory()->create(['brand_id' => $this->panorama->id, 'type' => 'website', 'name' => 'Panorama site', 'domain' => 'panorama.example', 'primary_url' => 'https://panorama.example']);
        $this->gsc = DigitalAsset::factory()->create(['brand_id' => $this->panorama->id, 'type' => 'gsc', 'name' => 'Panorama GSC']);
        DigitalAsset::factory()->create(['brand_id' => $this->panorama->id, 'type' => 'google_ads', 'name' => 'Panorama Ads (bağsız)']);
        DigitalAsset::factory()->create(['brand_id' => $this->other->id, 'type' => 'website', 'name' => 'Diğer site', 'domain' => 'other.example']);

        $integration = CoreIntegration::factory()->google()->create([
            'config' => ['auth_status' => 'reconnect_required', 'refresh_token' => self::TOKEN],
            'last_error' => 'invalid_grant access_token='.self::TOKEN.' for owner@panorama.example',
        ]);
        $resource = CoreExternalResource::factory()->searchConsole()->create(['integration_id' => $integration->id, 'external_id' => 'sc-domain:panorama.example']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->gsc->id, 'external_resource_id' => $resource->id, 'capability' => 'search_console']);

        DB::table('resource_automations')->insert([
            'external_resource_id' => $resource->id, 'collection_enabled' => true, 'interval_days' => 1, 'collection_status' => 'attention',
            'collection_failures' => 4, 'collection_error' => 'HTTP 401 Bearer '.self::TOKEN.' api_key='.self::LONG_SECRET.' call +90 532 123 45 67',
            'last_collection_success_at' => now()->subDays(9), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('gsc_property_daily')->insert([
            'digital_asset_id' => $this->gsc->id, 'external_resource_id' => $resource->id, 'site_url' => 'sc-domain:panorama.example', 'reporting_date' => now()->subDays(12)->toDateString(),
            'clicks' => 3, 'impressions' => 40, 'contract_version' => '1', 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => 'fp1',
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['uuid' => 'x', 'displayName' => 'App\\Jobs\\CollectThingJob', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call']),
            'exception' => 'RuntimeException: token rejected access_token='.self::TOKEN."\n#0 stack", 'failed_at' => now()->subHour(),
        ]);
        DB::table('app_error_groups')->insert([
            'fingerprint' => 'abc', 'exception_class' => 'ErrorException', 'location' => 'app/Livewire/Thing.php:10',
            'message' => 'Undefined key while calling with secret='.self::LONG_SECRET.' user admin@example.test', 'occurrences' => 7,
            'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subMinutes(5),
        ]);
        DB::table('website_url')->insert([
            'digital_asset_id' => $this->website->id, 'asset_id' => (string) $this->website->id, 'normalized_url' => 'https://panorama.example/tedaviler/',
            'contract_version' => '1', 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => 'u1',
        ]);
    }

    public function test_text_report_covers_every_section_and_flags_problems(): void
    {
        $exit = Artisan::call('moxdop:diagnose');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        foreach (['[environment]', '[ownership]', '[integrations]', '[collection]', '[website]', '[advisors]', '[ai]', '[errors]', '== ÖZET'] as $header) {
            $this->assertStringContainsString($header, $output);
        }
        $this->assertStringContainsString('!! Başarısız işler: son 24 saat 1', $output);
        $this->assertStringContainsString('App\\Jobs\\CollectThingJob', $output);
        $this->assertStringContainsString('İşletimde ama kaynağa bağlı değil (google_ads)', $output);
        $this->assertStringContainsString('Varlığı olmayan işletimdeki marka: #', $output);
        $this->assertStringContainsString('reconnect_required', $output);
        $this->assertStringContainsString('STALE', $output);
        $this->assertStringContainsString('art arda hata 4', $output);
        $this->assertStringContainsString('×7 ErrorException @ app/Livewire/Thing.php:10', $output);
        $this->assertSecretsMasked($output);
    }

    public function test_brand_filter_limits_deep_sections_to_the_matched_brand(): void
    {
        $exit = Artisan::call('moxdop:diagnose', ['--brand' => 'panorama']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('kapsam: marka #'.$this->panorama->id.' Panorama Dental', $output);
        $this->assertStringContainsString('#'.$this->website->id.' Panorama site (panorama.example', $output);
        $this->assertStringNotContainsString('Diğer site', $output);
        $this->assertStringContainsString('gsc_query_page_daily=yok', $output);
        $this->assertStringContainsString('Katalog SEARCH_CONSOLE', $output);
        $this->assertStringContainsString('Sorgu kaynakları (query_sources): boş', $output);
        $this->assertStringContainsString('sayfa (pages) 0', $output);
        $this->assertSecretsMasked($output);

        Artisan::call('moxdop:diagnose', ['--brand' => (string) $this->other->id, '--section' => 'website']);
        $this->assertStringContainsString('Diğer site', Artisan::output());

        Artisan::call('moxdop:diagnose', ['--brand' => 'yok-boyle-marka', '--section' => 'collection']);
        $this->assertStringContainsString('marka bulunamadı', Artisan::output());
    }

    public function test_json_format_returns_the_same_sections(): void
    {
        $exit = Artisan::call('moxdop:diagnose', ['--format' => 'json', '--brand' => 'Panorama', '--section' => 'ownership,collection']);
        $output = Artisan::output();
        $report = json_decode($output, true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($report);
        $this->assertSame(['ownership', 'collection'], array_keys($report['sections']));
        $this->assertSame('brand', $report['scope']['mode']);
        $this->assertNotEmpty($report['summary']);
        $this->assertSecretsMasked($output);
    }

    public function test_command_is_read_only(): void
    {
        Queue::fake();
        Http::fake();
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter|truncate)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });
        $before = collect(['digital_assets', 'brands', 'resource_automations', 'failed_jobs', 'cache'])->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()]);

        $this->assertSame(0, Artisan::call('moxdop:diagnose'));
        $this->assertSame(0, Artisan::call('moxdop:diagnose', ['--brand' => 'Panorama', '--format' => 'json']));
        $this->assertSame(0, Artisan::call('moxdop:diagnose', ['--asset' => (string) $this->gsc->id]));

        $this->assertSame([], $writes);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
    }

    public function test_invalid_options_are_rejected(): void
    {
        $this->artisan('moxdop:diagnose', ['--format' => 'xml'])->assertExitCode(2);
        $this->artisan('moxdop:diagnose', ['--section' => 'nope'])->assertExitCode(2);
    }

    public function test_masker_hides_tokens_emails_phones_and_keeps_readable_text(): void
    {
        $masked = DiagnosticMasker::text('Bearer abc.def refresh_token=1//0gAbcdefghijklmnopqrstuvwxyz EAA'.str_repeat('B', 30).' mail a.b@c.com tel 0532 123 45 67 site panorama.example 2026-09-28 12:00:00');

        $this->assertStringNotContainsString('abc.def', $masked);
        $this->assertStringNotContainsString('1//0gAbc', $masked);
        $this->assertStringNotContainsString('EAABBB', $masked);
        $this->assertStringNotContainsString('a.b@c.com', $masked);
        $this->assertStringNotContainsString('0532 123 45 67', $masked);
        $this->assertStringContainsString('panorama.example 2026-09-28 12:00:00', $masked);
        $this->assertSame('***7890', DiagnosticMasker::id('123-456-7890'));
        $this->assertSame($masked, DiagnosticMasker::text($masked), 'masking twice changes nothing');
        $this->assertStringNotContainsString(']]', $masked);
    }

    private function assertSecretsMasked(string $output): void
    {
        $this->assertStringNotContainsString(self::TOKEN, $output);
        $this->assertStringNotContainsString('ya29.', $output);
        $this->assertStringNotContainsString(self::LONG_SECRET, $output);
        $this->assertStringNotContainsString('owner@panorama.example', $output);
        $this->assertStringNotContainsString('admin@example.test', $output);
        $this->assertStringNotContainsString('532 123 45 67', $output);
        $this->assertStringNotContainsString('[MASKED]]', $output);
        $this->assertStringContainsString('[MASKED]', $output);
    }
}
