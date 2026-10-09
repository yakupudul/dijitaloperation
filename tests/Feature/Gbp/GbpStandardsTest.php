<?php

namespace Tests\Feature\Gbp;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\ServiceCategory;
use App\Services\Gbp\GbpStandardEvaluator;
use App\Services\Gbp\GbpStandardInput;
use App\Support\TurkishPublicHolidays;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MoxDop\Website\Standards\WebsiteStandardCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Business Profile standards (research 2026-09): each gbp_* standard gives pass / fail (or review) / unknown /
 * not_applicable from collected data; missing data is never a failure; Q&A is neither collected nor scored.
 */
final class GbpStandardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_loads_ids_are_unique_and_every_standard_has_a_dated_source(): void
    {
        $raw = json_decode((string) file_get_contents(base_path('app-modules/website/resources/standards.json')), true, 512, JSON_THROW_ON_ERROR);
        $ids = array_column($raw['standards'], 'id');
        $this->assertSame(count($ids), count(array_unique($ids)), 'standard ids are unique');

        $catalog = new WebsiteStandardCatalog;
        $gbp = $catalog->forAssetType('google_business_profile');
        $this->assertCount(16, $gbp);
        foreach ($catalog->definitions() as $id => $standard) {
            $this->assertArrayHasKey($standard['group'], WebsiteStandardCatalog::GROUPS, $id);
            $this->assertContains($standard['severity'], ['low', 'medium', 'high'], $id);
            if (str_starts_with($standard['method'], 'url_') || str_starts_with($standard['method'], 'gbp_')) {
                $this->assertStringStartsWith('https://', (string) $standard['source_url'], $id);
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $standard['source_reviewed_at'], $id);
            }
            // Myths we deliberately never check (PROJECT_MEMORY 2026-09-28).
            $text = mb_strtolower($standard['title'].' '.$standard['criterion'].' '.$standard['action']);
            if ($standard['method'] !== 'expert_review') {
                foreach (['llms.txt', 'meta keywords', 'anahtar kelime yoğunluğu', 'changefreq öner', 'howto'] as $myth) {
                    $this->assertStringNotContainsString($myth, $text, $id);
                }
            }
            $this->assertStringNotContainsString('qanda', $standard['method'], $id);
        }
        foreach ($gbp as $id => $standard) {
            $this->assertStringStartsWith('gbp_', $standard['method'], $id);
        }
        // The website assessment and the URL karnesi never evaluate Business Profile standards.
        $this->assertSame([], array_filter($catalog->forAssetType('website'), fn (array $s): bool => str_starts_with($s['method'], 'gbp_')));
    }

    public function test_faq_standard_is_optional_information(): void
    {
        $faq = (new WebsiteStandardCatalog)->definitions()['website:url:faq_schema'];
        $this->assertTrue($faq['informational']);
        $this->assertSame('low', $faq['severity']);
        $this->assertStringContainsString('isteğe bağlı', $faq['criterion']);
        $this->assertStringContainsString('7 Mayıs 2026', $faq['criterion']);
    }

    public function test_business_profile_qanda_is_not_collected_scheduled_or_scored(): void
    {
        $collector = (string) file_get_contents(app_path('Services/Integrations/Google/GoogleBusinessProfileBoundCollector.php'));
        $this->assertStringNotContainsString('questions', $collector, 'Q&A API was discontinued on 2025-11-03');
        $this->assertFalse(Schema::hasTable('gbp_questions'));
        $events = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) ($event->command.' '.$event->description));
        $this->assertFalse($events->contains(fn (string $e): bool => str_contains(mb_strtolower($e), 'question') || str_contains(mb_strtolower($e), 'qanda')));
        foreach ([app_path('Services/Advisor/Gbp/GbpAdvisorRuleEngine.php'), app_path('Services/Gbp/GbpStandardEvaluator.php'), app_path('Services/Gbp/GbpDailyWorkspace.php')] as $file) {
            $this->assertDoesNotMatchRegularExpression('/Soru[- ]Cevap|questions\(|qanda/iu', (string) file_get_contents($file), $file);
        }
    }

    public function test_holiday_table_lists_upcoming_bayrams(): void
    {
        $october = TurkishPublicHolidays::upcoming(CarbonImmutable::parse('2026-10-10'), 30);
        $this->assertSame(['Cumhuriyet Bayramı'], array_column($october, 'name'));
        $may = array_column(TurkishPublicHolidays::upcoming(CarbonImmutable::parse('2027-05-10'), 30), 'name');
        $this->assertContains('Kurban Bayramı', $may);
        $this->assertContains('Atatürk’ü Anma, Gençlik ve Spor Bayramı', $may);
        $this->assertSame([], TurkishPublicHolidays::upcoming(CarbonImmutable::parse('2026-06-01'), 30));
        $this->assertSame('2027-10-29', TurkishPublicHolidays::coveredUntil());
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: string}> */
    public static function cases(): array
    {
        $holiday = [['key' => '2026-10-29', 'name' => 'Cumhuriyet Bayramı', 'dates' => ['2026-10-28', '2026-10-29']]];
        $special = [['startDate' => ['year' => 2026, 'month' => 10, 'day' => 29], 'closed' => true]];
        $week = [['openDay' => 'MONDAY'], ['openDay' => 'TUESDAY'], ['openDay' => 'WEDNESDAY']];

        return [
            'category pass' => ['primary_category', ['location' => ['primary_category' => 'Diş hekimi']], 'pass'],
            'category review' => ['primary_category', ['location' => ['primary_category' => 'Güzellik salonu']], 'review'],
            'category fail' => ['primary_category', ['location' => ['primary_category' => '']], 'fail'],
            'category pass unknown sector' => ['primary_category', ['sector_codes' => [], 'location' => ['primary_category' => 'Güzellik salonu']], 'pass'],
            'category pass healthcare specialist' => ['primary_category', ['sector_codes' => ['healthcare'], 'location' => ['primary_category' => 'Kardiyolog']], 'pass'],
            'category pass healthcare surgeon' => ['primary_category', ['sector_codes' => ['healthcare'], 'location' => ['primary_category' => 'Plastik cerrah']], 'pass'],
            'category pass healthcare psychiatrist' => ['primary_category', ['sector_codes' => ['healthcare'], 'location' => ['primary_category' => 'Psikiyatrist']], 'pass'],
            'category pass healthcare physiotherapy' => ['primary_category', ['sector_codes' => ['healthcare'], 'location' => ['primary_category' => 'Fizyoterapist']], 'pass'],
            'name pass' => ['name_no_keywords', ['location' => ['title' => 'Atlas Diş Kliniği']], 'pass'],
            'name review district' => ['name_no_keywords', ['location' => ['title' => 'Atlas Diş Kliniği Çankaya']], 'review'],
            'name pass own district' => ['name_no_keywords', ['location' => ['title' => 'Atlas Diş Kliniği Çankaya', 'area_words' => ['cankaya', 'ankara']]], 'pass'],
            'name review other district' => ['name_no_keywords', ['location' => ['title' => 'Atlas Diş Kliniği Çankaya', 'area_words' => ['kecioren', 'ankara']]], 'review'],
            'name review service' => ['name_no_keywords', ['location' => ['title' => 'Atlas İmplant Merkezi']], 'review'],
            'name review separator' => ['name_no_keywords', ['location' => ['title' => 'Atlas Diş | En İyi Klinik']], 'review'],
            'services pass' => ['services_complete', ['services' => ['available' => true, 'labels' => ['İmplant tedavisi', 'Zirkonyum kaplama']]], 'pass'],
            'services review' => ['services_complete', ['services' => ['available' => true, 'labels' => ['İmplant tedavisi']]], 'review'],
            'services unknown' => ['services_complete', ['services' => ['available' => false, 'labels' => []]], 'unknown'],
            'services n/a' => ['services_complete', ['offerings' => []], 'not_applicable'],
            'attributes pass' => ['attributes_set', ['attributes' => ['available' => true, 'set' => ['a', 'b', 'c'], 'unset' => []]], 'pass'],
            'attributes review' => ['attributes_set', ['attributes' => ['available' => true, 'set' => [], 'unset' => ['Tekerlekli sandalye']]], 'review'],
            'attributes unknown' => ['attributes_set', ['attributes' => ['available' => false, 'set' => [], 'unset' => []]], 'unknown'],
            'description pass' => ['description', ['location' => ['description' => str_repeat('Diş sağlığı hakkında bilgi. ', 10)]], 'pass'],
            'description review short' => ['description', ['location' => ['description' => 'Ankara’da diş kliniği.']], 'review'],
            'description fail empty' => ['description', ['location' => ['description' => '']], 'fail'],
            'description fail long' => ['description', ['location' => ['description' => str_repeat('a', 760)]], 'fail'],
            'description fail url' => ['description', ['location' => ['description' => 'Randevu için www.atlas.com.tr adresine gidin.']], 'fail'],
            'description fail compliance' => ['description', ['location' => ['description' => 'Bilgi'], 'description_hits' => [['label' => 'Kampanya / indirim / hediye', 'matched' => 'indirim']]], 'fail'],
            'hours pass' => ['hours', ['location' => ['regular_hours' => $week, 'special_hours' => $special], 'holidays' => $holiday], 'pass'],
            'hours fail special' => ['hours', ['location' => ['regular_hours' => $week, 'special_hours' => []], 'holidays' => $holiday], 'fail'],
            'hours fail regular' => ['hours', ['location' => ['regular_hours' => []]], 'fail'],
            'hours pass no holiday' => ['hours', ['location' => ['regular_hours' => $week], 'holidays' => []], 'pass'],
            'hours n/a closed' => ['hours', ['location' => ['open_status' => 'CLOSED_PERMANENTLY']], 'not_applicable'],
            'nap pass' => ['nap_matches_site', ['nap' => ['state' => 'pass', 'finding' => 'İşletme Profili ile telefon aynı.']], 'pass'],
            'nap fail' => ['nap_matches_site', ['nap' => ['state' => 'fail', 'finding' => 'Telefon farklı.']], 'fail'],
            'nap unknown' => ['nap_matches_site', ['nap' => null], 'unknown'],
            'photos pass' => ['photos_recent', ['media' => ['available' => true, 'last_photo' => '2026-09-01']], 'pass'],
            'photos review' => ['photos_recent', ['media' => ['available' => true, 'last_photo' => '2026-01-01']], 'review'],
            'photos unknown' => ['photos_recent', ['media' => ['available' => false, 'last_photo' => null]], 'unknown'],
            'posts pass' => ['posts_cadence', ['posts' => ['available' => true, 'last_post' => '2026-09-20', 'promotional' => []]], 'pass'],
            'posts review old' => ['posts_cadence', ['posts' => ['available' => true, 'last_post' => '2026-07-01', 'promotional' => []]], 'review'],
            'posts review promotion' => ['posts_cadence', ['posts' => ['available' => true, 'last_post' => '2026-09-20', 'promotional' => ['%20 indirim']]], 'review'],
            'posts unknown' => ['posts_cadence', ['posts' => ['available' => false]], 'unknown'],
            'velocity pass' => ['review_velocity', ['reviews' => self::reviews(['recent_count' => 6, 'recent_avg' => 4.8, 'baseline_avg' => 4.7])], 'pass'],
            'velocity review stopped' => ['review_velocity', ['reviews' => self::reviews(['recent_count' => 0, 'previous_count' => 7])], 'review'],
            'velocity review rating' => ['review_velocity', ['reviews' => self::reviews(['recent_count' => 6, 'recent_avg' => 3.9, 'baseline_avg' => 4.6])], 'review'],
            'velocity n/a' => ['review_velocity', ['reviews' => self::reviews(['recent_count' => 0, 'previous_count' => 0])], 'not_applicable'],
            'velocity unknown' => ['review_velocity', ['reviews' => ['available' => false]], 'unknown'],
            'responses pass' => ['review_responses', ['reviews' => self::reviews(['reply' => ['considered' => 10, 'replied' => 10, 'median_hours' => 12.0]])], 'pass'],
            'responses review rate' => ['review_responses', ['reviews' => self::reviews(['reply' => ['considered' => 10, 'replied' => 6, 'median_hours' => 12.0]])], 'review'],
            'responses review slow' => ['review_responses', ['reviews' => self::reviews(['reply' => ['considered' => 10, 'replied' => 10, 'median_hours' => 120.0]])], 'review'],
            'responses n/a' => ['review_responses', ['reviews' => self::reviews(['reply' => ['considered' => 0, 'replied' => 0, 'median_hours' => null]])], 'not_applicable'],
            'responses unknown' => ['review_responses', ['reviews' => ['available' => false]], 'unknown'],
            'verified pass' => ['verified', ['verification' => ['verified' => true]], 'pass'],
            'verified fail' => ['verified', ['verification' => ['verified' => false]], 'fail'],
            'verified unknown' => ['verified', ['verification' => ['verified' => null]], 'unknown'],
            'extra categories pass' => ['additional_categories', ['location' => ['additional_categories' => ['Ortodontist', 'Ağız ve diş cerrahı']]], 'pass'],
            'extra categories review' => ['additional_categories', ['location' => ['additional_categories' => []]], 'review'],
            'contact pass' => ['contact', ['location' => ['website_uri' => 'https://atlas.com.tr/', 'phone' => '0312 444 55 66']], 'pass'],
            'contact fail' => ['contact', ['location' => ['website_uri' => '', 'phone' => '0312 444 55 66']], 'fail'],
            'contact review nationwide' => ['contact', ['location' => ['website_uri' => 'https://atlas.com.tr/', 'phone' => '+90 850 123 45 67']], 'review'],
            'contact review 444' => ['contact', ['location' => ['website_uri' => 'https://atlas.com.tr/', 'phone' => '444 1 234']], 'review'],
            'photo set pass' => ['photo_set', ['media' => ['available' => true, 'last_photo' => '2026-09-01', 'photo_count' => 14, 'has_logo' => true, 'has_cover' => true]], 'pass'],
            'photo set review' => ['photo_set', ['media' => ['available' => true, 'last_photo' => '2026-09-01', 'photo_count' => 4, 'has_logo' => false, 'has_cover' => true]], 'review'],
            'photo set unknown' => ['photo_set', ['media' => ['available' => false, 'last_photo' => null]], 'unknown'],
            'service area n/a storefront' => ['service_area', ['location' => ['has_storefront' => true]], 'not_applicable'],
            'service area pass' => ['service_area', ['location' => ['has_storefront' => false, 'service_area_count' => 3]], 'pass'],
            'service area fail' => ['service_area', ['location' => ['has_storefront' => false, 'service_area_count' => 0]], 'fail'],
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    #[DataProvider('cases')]
    public function test_business_profile_standard(string $method, array $overrides, string $expected): void
    {
        $standards = (new WebsiteStandardCatalog)->forAssetType('google_business_profile');
        $input = self::input();
        foreach ($overrides as $key => $value) {
            $input[$key] = $key === 'location' ? array_replace($input['location'], $value) : $value;
        }
        $id = 'gbp:'.$method;
        $result = (new GbpStandardEvaluator)->evaluate($standards, $input)[$id];

        $this->assertSame($expected, $result['state'], $result['finding']);
        $this->assertNotSame('', $result['finding']);
        if (in_array($expected, ['fail', 'review'], true)) {
            $this->assertNotEmpty($result['solution']);
        }
    }

    public function test_missing_profile_is_unknown_for_every_standard(): void
    {
        $standards = (new WebsiteStandardCatalog)->forAssetType('google_business_profile');
        $results = (new GbpStandardEvaluator)->evaluate($standards, ['location' => null]);
        $this->assertCount(16, $results);
        $this->assertSame(['unknown'], array_values(array_unique(array_column($results, 'state'))));
    }

    public function test_input_reads_collected_rows_site_nap_and_review_response_times(): void
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas Diş']);
        $category = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş', 'normalized_key' => 'dental']);
        $brand->update(['sector_id' => $category->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_business_profile', 'status' => 'active']);
        $site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'status' => 'active']);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $resource = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => 'google_business_profile',
            'external_id' => 'locations/9', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        $binding = CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $run = Run::query()->create(['digital_asset_id' => $asset->id, 'core_asset_binding_id' => $binding->id, 'module_id' => 'google-business-profile', 'status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);
        $base = ['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'run_id' => $run->id, 'location_name' => 'locations/9', 'created_at' => now(), 'updated_at' => now()];
        DB::table('gbp_location_snapshots')->insert($base + ['title' => 'Atlas Diş', 'primary_category' => 'Diş hekimi', 'profile' => json_encode(['description' => 'Bilgi']),
            'regular_hours' => json_encode(['periods' => [['openDay' => 'MONDAY']]]), 'captured_at' => now()]);
        $today = CarbonImmutable::parse('2026-09-28');
        foreach ([['a', 5, 10], ['b', 10, null], ['c', 20, 30]] as [$id, $daysAgo, $replyHours]) {
            $created = $today->subDays($daysAgo);
            DB::table('gbp_reviews')->insert($base + ['review_id' => $id, 'star_rating' => 'FIVE', 'create_time' => $created,
                'review_reply' => $replyHours !== null ? json_encode(['comment' => 'Teşekkürler', 'updateTime' => $created->addHours($replyHours)->toIso8601String()]) : null,
                'raw_payload' => '{}', 'collected_at' => now()]);
        }

        $input = app(GbpStandardInput::class)->build($asset, $resource->id, $today);
        $this->assertSame(['dental'], $input['sector_codes']);
        $this->assertSame(3, $input['reviews']['reply']['considered']);
        $this->assertSame(2, $input['reviews']['reply']['replied']);
        $this->assertEqualsWithDelta(30.0, $input['reviews']['reply']['median_hours'], 0.01);
        $this->assertNull($input['nap'], 'v2: the site ↔ profile NAP check returns with the pages model');
        $this->assertFalse($input['media']['available']);
        $this->assertSame(['Cumhuriyet Bayramı'], array_column($input['holidays'], 'name'));

        $results = app(GbpStandardInput::class)->results($asset, $resource->id, $today);
        $this->assertSame('pass', $results['gbp:primary_category']['state']);
        $this->assertSame('unknown', $results['gbp:nap_matches_site']['state']);
        $this->assertSame('fail', $results['gbp:hours']['state'], 'Cumhuriyet Bayramı has no special hours');
        $this->assertSame('review', $results['gbp:review_responses']['state'], '1 of 3 unanswered');
        $this->assertSame('unknown', $results['gbp:photos_recent']['state'], 'media not collected is never a failure');
        $this->assertSame('unknown', $results['gbp:attributes_set']['state']);
        $this->assertSame('unknown', $results['gbp:verified']['state'], 'verification not collected');
        $this->assertSame('fail', $results['gbp:contact']['state'], 'no website / phone on the profile');
        $this->assertSame('fail', $results['gbp:service_area']['state'], 'no address and no service area');
    }

    /** @return array<string, mixed> */
    private static function input(): array
    {
        return [
            'today' => '2026-09-28',
            'location' => ['title' => 'Atlas Diş Kliniği', 'primary_category' => 'Diş hekimi', 'additional_categories' => [], 'description' => 'Bilgi',
                'regular_hours' => [['openDay' => 'MONDAY']], 'special_hours' => [], 'open_status' => 'OPEN'],
            'brand_name' => 'Atlas', 'sector_codes' => ['dental'], 'location_words' => ['ankara', 'cankaya'],
            'offerings' => ['İmplant tedavisi', 'Zirkonyum kaplama'], 'service_words' => ['implant', 'tedavisi', 'zirkonyum', 'kaplama'],
            'services' => ['available' => true, 'labels' => []], 'attributes' => ['available' => true, 'set' => [], 'unset' => []],
            'description_hits' => [], 'holidays' => [], 'nap' => null,
            'media' => ['available' => false, 'last_photo' => null], 'posts' => ['available' => false, 'last_post' => null, 'promotional' => []],
            'reviews' => ['available' => false],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function reviews(array $overrides): array
    {
        return array_replace(['available' => true, 'total' => 10, 'recent_count' => 3, 'recent_avg' => 4.5, 'previous_count' => 2, 'baseline_avg' => 4.5,
            'reply' => ['considered' => 3, 'replied' => 3, 'median_hours' => 5.0]], $overrides);
    }
}
