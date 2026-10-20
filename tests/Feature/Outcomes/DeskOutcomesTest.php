<?php

namespace Tests\Feature\Outcomes;

use App\Livewire\Operator\Repair\RepairDeskPage;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Brand\BrandDossier;
use App\Services\Outcomes\OutcomeTracker;
use App\Services\Repair\RepairDesk;
use App\Services\Repair\WebHealthAudit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Site\SiteTestCase;
use Tests\Support\InsertsFacts;

/**
 * Onarım masası sonuçları (yakup 2026-10-11): every change applied after approval is measured 30 and 90 days later on
 * the stored facts (Search Console + GA4 of the page), equal windows before and after, as işe yaradı / değişmedi /
 * kötüleşti / ölçülemedi with a reason; the short line shows on the desk and in the brand file's decisions; the
 * per-work-type record reorders the desk within a small bounded weight; the measurement runs nightly.
 */
final class DeskOutcomesTest extends SiteTestCase
{
    use InsertsFacts;

    private const string APPLIED = '2026-06-01 10:00:00';

    private Page $implantPage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse(self::APPLIED, OutcomeTracker::TZ));
        $this->implantPage = $this->page('/implant/', 'Ankara İmplant Tedavisi');
        OutcomeTracker::forgetWorkTypes();
    }

    public function test_an_applied_desk_fix_that_won_shows_its_line_on_the_desk_and_in_the_brand_file(): void
    {
        $ga4 = CoreExternalResource::factory()->create();
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->site->id, 'external_resource_id' => $ga4->id, 'capability' => 'ga4']);
        $this->clicks('/implant/', -30, -1, 1);   // 30 clicks before
        $this->clicks('/implant/', 0, 0, 40);     // the apply day is in neither window
        $this->clicks('/implant/', 1, 30, 2);     // 60 after → +100 %
        $this->sessions('/implant/', -30, -1, 10);
        $this->sessions('/implant/', 1, 30, 15);
        $fix = $this->suggestion('Başlığı güçlendir', 'title_description', $this->implantPage);
        app(OutcomeTracker::class)->apply($fix, $this->admin);

        $this->travel(31)->days();
        $this->artisan('moxdop:outcomes:measure')->expectsOutputToContain('Ölçülen sonuç: 1')->assertSuccessful();

        $point = $fix->fresh()->outcome['d30'];
        $this->assertSame(OutcomeTracker::WORKED, $point['verdict']);
        $this->assertSame([30, 60], [$point['before']['clicks'], $point['clicks']]);
        $this->assertSame([300, 450], [$point['before']['sessions'], $point['sessions']], 'GA4 landing sessions of the same windows');
        $this->assertSame('tıklama 30 → 60 (+%100)', $point['reason']);
        $this->assertSame('+30 tıklama (30 gün)', $point['line']);

        $results = app(RepairDesk::class)->results();
        $this->assertSame(1, $results['counts'][OutcomeTracker::WORKED]);
        $this->assertSame(['Başlığı güçlendir', 'Google\'da görünen başlık ve açıklama', '+30 tıklama (30 gün)'], [$results['items'][0]['title'], $results['items'][0]['type'], $results['items'][0]['line']]);
        Livewire::test(RepairDeskPage::class)->assertSee('Sonuçlar')->assertSee('+30 tıklama (30 gün)')->assertSee('1 işe yaradı');

        $decisions = app(BrandDossier::class)->build($this->brand)['sections']['decisions']['markdown'];
        $this->assertStringContainsString('Başlığı güçlendir — sonuç: +30 tıklama (30 gün)', $decisions);
    }

    public function test_lost_and_neutral_are_told_apart_and_90_days_compare_90_days(): void
    {
        $other = $this->page('/zirkonyum/', 'Zirkonyum Kaplama');
        $this->clicks('/implant/', -90, -1, 2);
        $this->clicks('/implant/', 1, 90, 1);      // half → kötüleşti at 30 and at 90 days
        $this->clicks('/zirkonyum/', -90, -1, 2);
        $this->clicks('/zirkonyum/', 1, 90, 2);    // same → değişmedi
        $lost = $this->suggestion('İç link ekle', 'internal_links', $this->implantPage);
        $neutral = $this->suggestion('Açıklamayı yaz', 'title_description', $other);
        app(OutcomeTracker::class)->apply($lost, $this->admin);
        app(OutcomeTracker::class)->apply($neutral, $this->admin);

        $this->travel(91)->days();
        $this->artisan('moxdop:outcomes:measure')->expectsOutputToContain('Ölçülen sonuç: 4')->assertSuccessful();

        $this->assertSame([OutcomeTracker::NOT_WORKED, '−30 tıklama (30 gün)'], [$lost->fresh()->outcome['d30']['verdict'], $lost->fresh()->outcome['d30']['line']]);
        $this->assertSame([OutcomeTracker::NOT_WORKED, '−90 tıklama (90 gün)'], [$lost->fresh()->outcome['d90']['verdict'], $lost->fresh()->outcome['d90']['line']]);
        $this->assertSame([180, 90], [$lost->fresh()->outcome['d90']['before']['clicks'], $lost->fresh()->outcome['d90']['clicks']]);
        $this->assertSame(['2026-03-03', '2026-05-31', '2026-06-02', '2026-08-30'], [$lost->fresh()->outcome['d90']['before_from'],
            $lost->fresh()->outcome['d90']['before_to'], $lost->fresh()->outcome['d90']['from'], $lost->fresh()->outcome['d90']['to']]);
        $this->assertSame(OutcomeTracker::NEUTRAL, $neutral->fresh()->outcome['d90']['verdict']);
        $this->assertSame('+0 tıklama (90 gün)', OutcomeTracker::latest($neutral->fresh())['line']);
    }

    public function test_not_measurable_says_why(): void
    {
        $this->clicks('/implant/', -30, -1, 0.3);
        $this->clicks('/implant/', 1, 30, 0.3);    // 9 clicks each side
        $little = $this->suggestion('Az veri', 'title_description', $this->implantPage);
        $nowhere = $this->suggestion('Sayfasız', 'technical_seo', null);
        $notMeasured = $this->suggestion('Marka bilgisi', 'brand_data', null, ['channel' => 'brand']);
        foreach ([$little, $nowhere, $notMeasured] as $suggestion) {
            app(OutcomeTracker::class)->apply($suggestion, $this->admin);
        }

        $this->travel(31)->days();
        $this->artisan('moxdop:outcomes:measure')->expectsOutputToContain('Ölçülen sonuç: 3')->assertSuccessful();

        $this->assertSame([OutcomeTracker::UNCLEAR, 'ölçülemedi: veri az (en az 20 tıklama) (30 gün)'], [$little->fresh()->outcome['d30']['verdict'], $little->fresh()->outcome['d30']['line']]);
        $this->assertSame([OutcomeTracker::UNCLEAR, 'veri yok'], [$nowhere->fresh()->outcome['d30']['verdict'], $nowhere->fresh()->outcome['d30']['reason']]);
        $this->assertSame([OutcomeTracker::UNCLEAR, 'bu iş türü ölçülmüyor'], [$notMeasured->fresh()->outcome['d30']['verdict'], $notMeasured->fresh()->outcome['d30']['reason']]);
    }

    public function test_a_source_collected_for_less_than_the_before_window_is_not_compared(): void
    {
        $this->clicks('/implant/', -10, -1, 10);   // Search Console bound ten days before apply
        $this->clicks('/implant/', 1, 30, 10);
        $fix = $this->suggestion('Başlık', 'title_description', $this->implantPage);
        app(OutcomeTracker::class)->apply($fix, $this->admin);

        $this->travel(31)->days();
        app(OutcomeTracker::class)->measure($fix->fresh());

        $this->assertSame([OutcomeTracker::UNCLEAR, 'öncesi için 30 günlük veri yok'], [$fix->fresh()->outcome['d30']['verdict'], $fix->fresh()->outcome['d30']['reason']]);
    }

    public function test_a_site_fix_without_a_page_is_measured_on_the_url_it_names(): void
    {
        $this->clicks('/implant/', -30, -1, 1);
        $this->clicks('/implant/', 1, 30, 3);
        $fix = $this->suggestion('Asıl adresi düzelt', WebHealthAudit::TYPE, null, ['target_type' => 'asset', 'target_id' => $this->site->id,
            'action' => ['check' => 'canonical', 'target' => 'https://panorama.com.tr/implant/', 'site_id' => $this->site->id, 'changes' => [['type' => 'canonical']]]]);
        app(OutcomeTracker::class)->apply($fix, $this->admin);

        $this->travel(31)->days();
        app(OutcomeTracker::class)->measure($fix->fresh());

        $this->assertSame('https://panorama.com.tr/implant/', $fix->fresh()->baseline['scope']['url']);
        $this->assertSame([OutcomeTracker::WORKED, '+60 tıklama (30 gün)'], [$fix->fresh()->outcome['d30']['verdict'], $fix->fresh()->outcome['d30']['line']]);
    }

    public function test_the_measurement_runs_every_night_after_the_collections(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'moxdop:outcomes:measure'));

        $this->assertNotNull($event);
        $this->assertSame('13 7 * * *', $event->expression);
        $this->assertSame('Europe/Istanbul', $event->timezone);
        $this->assertSame('outcomes-measure', $event->description);
    }

    public function test_the_learned_weight_is_small_bounded_and_needs_enough_results(): void
    {
        $this->assertSame(1.0, OutcomeTracker::weight(4, 0, 4), 'fewer than brain.min_measured (5) results: no effect');
        $this->assertSame(1.2, OutcomeTracker::weight(5, 0, 5));
        $this->assertSame(0.8, OutcomeTracker::weight(0, 5, 5));
        $this->assertSame(1.067, OutcomeTracker::weight(3, 1, 6), 'değişmedi pulls toward 1');
        config(['moxdop-advisor.brain.weight_range' => 5]);
        $this->assertSame(1.5, OutcomeTracker::weight(5, 0, 5), 'the range is capped at 0.5');
    }

    public function test_work_types_that_keep_winning_rank_higher_on_the_desk(): void
    {
        $proposal = ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => ['seo_title' => 'Yeni başlık']]];
        foreach (range(1, 2) as $n) {
            $this->suggestion('Başlık '.$n, 'title_description', $this->implantPage, ['action' => $proposal]);
            $this->suggestion('Alt '.$n, 'image_alt', null, ['action' => ['site_id' => $this->site->id, 'images' => [['image_id' => $n, 'file' => 'x'.$n.'.jpg', 'alt' => 'Alt']]]]);
        }
        $order = fn (): array => (new RepairDesk)->packages((new RepairDesk)->rows())->pluck('kind')->all();
        $this->assertSame([RepairDesk::IMAGE_ALT, RepairDesk::SITE_FIELDS], $order(), 'no record yet: same size, then by kind');

        foreach (range(1, 6) as $n) {
            $this->measured('title_description', $n <= 5 ? OutcomeTracker::WORKED : OutcomeTracker::NEUTRAL);
            $this->measured('image_alt', $n <= 4 ? OutcomeTracker::NOT_WORKED : OutcomeTracker::UNCLEAR);
        }
        $this->measured('image_alt', OutcomeTracker::NOT_WORKED);
        OutcomeTracker::forgetWorkTypes();

        $learned = app(OutcomeTracker::class)->workTypes();
        $this->assertSame(['won' => 5, 'neutral' => 1, 'lost' => 0, 'unclear' => 0, 'measured' => 6, 'weight' => 1.167], $learned['title_description']);
        $this->assertSame(['won' => 0, 'neutral' => 0, 'lost' => 5, 'unclear' => 2, 'measured' => 5, 'weight' => 0.8], $learned['image_alt'], 'ölçülemedi never counts');

        $packages = (new RepairDesk)->packages((new RepairDesk)->rows());
        $this->assertSame([RepairDesk::SITE_FIELDS, RepairDesk::IMAGE_ALT], $packages->pluck('kind')->all());
        $this->assertSame('Bu tür işler: 6 ölçümün 5 tanesi işe yaradı', $packages[0]['track']);
        $health = (new RepairDesk)->health((new RepairDesk)->rows());
        $this->assertSame(7.87, $health[0]['gain'], 'two winning rows (2 × 2 × 1.167) + two losing rows (2 × 2 × 0.8)');
        Livewire::test(RepairDeskPage::class)->assertSee('İş türü sicili')->assertSee('Bu tür işler: 6 ölçümün 5 tanesi işe yaradı');
    }

    /** Daily Search Console clicks of a page from apply day + $from to + $to ($perDay may be fractional). */
    private function clicks(string $path, int $from, int $to, float $perDay): void
    {
        $applied = CarbonImmutable::parse(self::APPLIED, OutcomeTracker::TZ)->startOfDay();
        $carry = 0.0;
        for ($day = $from; $day <= $to; $day++) {
            $carry += $perDay;
            $value = (int) floor($carry);
            $carry -= $value;
            $this->fact('implant ankara', $path, 100, $value, 5.0, $applied->addDays($day)->toDateString());
        }
    }

    private function sessions(string $path, int $from, int $to, int $perDay): void
    {
        $applied = CarbonImmutable::parse(self::APPLIED, OutcomeTracker::TZ)->startOfDay();
        $ga4 = (int) CoreAssetBinding::query()->where('capability', 'ga4')->value('external_resource_id');
        for ($day = $from; $day <= $to; $day++) {
            $this->insertFacts('ga4_landing_source_daily', ['digital_asset_id' => null, 'external_resource_id' => $ga4, 'property_id' => 'properties/1',
                'reporting_date' => $applied->addDays($day)->toDateString(), 'landingPage' => $path, 'sessionSource' => 'google', 'sessionMedium' => 'organic',
                'sessions' => $perDay, 'keyEvents' => 0, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20)]);
        }
    }

    /** @param  array<string, mixed>  $extra */
    private function suggestion(string $title, string $type, ?Page $page, array $extra = []): Suggestion
    {
        return Suggestion::query()->create(array_merge([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'repair.test', 'fingerprint' => hash('sha256', $title.random_int(1, PHP_INT_MAX)),
            'material_hash' => hash('sha256', 'x'), 'title' => $title, 'reason' => 'Gerekçe.', 'priority' => 2, 'action_type' => $type,
            'status' => Suggestion::OPEN, 'action' => [], 'evidence' => [], 'page_id' => $page?->id,
        ], $extra));
    }

    private function measured(string $type, string $verdict): void
    {
        $this->suggestion('Ölçülmüş '.$type, $type, null, ['status' => Suggestion::APPLIED, 'applied_at' => now()->subDays(40), 'measured_at' => now()->subDays(5),
            'outcome' => ['d30' => ['verdict' => $verdict, 'reason' => 'x', 'line' => 'x (30 gün)']]]);
    }
}
