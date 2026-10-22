<?php

namespace Tests\Feature\Goals;

use App\Jobs\ApproveRepairRowsJob;
use App\Livewire\Demo\GlobalSearch;
use App\Livewire\Operator\Komuta\KomutaPage;
use App\Livewire\Operator\Portfolio\PortfolioPage;
use App\Models\BrandConversionSource;
use App\Models\BrandTarget;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\WorkBatch;
use App\Services\Goals\BrandTargets;
use App\Services\Goals\TargetImpact;
use App\Services\Operator\KomutaReader;
use App\Services\Operator\PortfolioReader;
use App\Services\Repair\RepairDesk;
use App\Support\BrandIntelligence\ConversionGoalTypes;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Site\SiteTestCase;

/**
 * Aşama 5 (2026-10-22): a brand's "ayda N talep" goal is proposed from its last three months, approved with one click
 * and split over the channels; Komuta gathers every brand's decisions in one box and Portföy lists the brands by how far
 * they are from their goal.
 */
final class KomutaTest extends SiteTestCase
{
    private Page $implantPage;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-15 09:00:00', 'Europe/Istanbul'));
        Queue::fake()->except([ApproveRepairRowsJob::class]);
        $this->implantPage = $this->page('/implant/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet', 'wp_post_id' => 42]);

        BrandConversionSource::query()->create(['brand_id' => $this->brand->id, 'source' => BrandConversionSource::SOURCE_GA4, 'source_key' => 'generate_lead',
            'label' => 'Form', 'conversion_type' => ConversionGoalTypes::FORM_SUBMISSION, 'counts' => true, 'origin' => 'operator', 'metadata' => []]);
        foreach (['2026-07-10' => 10, '2026-08-10' => 12, '2026-09-10' => 14, '2026-10-05' => 6] as $day => $leads) {
            DB::table('ga4_key_event_daily')->insert([
                'digital_asset_id' => $this->site->id, 'external_resource_id' => 1, 'property_id' => '111', 'reporting_date' => $day, 'eventName' => 'generate_lead',
                'keyEvents' => $leads, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_goal_is_proposed_from_three_months_and_kept_once_approved(): void
    {
        $targets = app(BrandTargets::class);
        $target = $targets->refresh($this->brand);

        $this->assertSame(BrandTarget::PROPOSED, $target->status);
        $this->assertSame(12.0, (float) $target->basis['average']);
        $this->assertSame(15, $target->proposed_leads, 'average 12 plus 20 percent');
        $this->assertSame(15, $target->proposed_split['web'], 'all measured demand came from the site');
        $this->assertStringContainsString('ayda ortalama 12 talep', $target->reason);
        $this->assertSame('no_goal', BrandTargets::progress($target)['state']);

        $targets->approve($target, $this->admin);
        $progress = BrandTargets::progress($target->fresh());
        $this->assertSame(15, $progress['goal']);
        $this->assertSame(6, $progress['mtd']);
        $this->assertSame(13, $progress['projected'], '6 leads in 14 days is 13 by the end of October');
        $this->assertSame('behind', $progress['state']);

        $target->fresh()->forceFill(['monthly_leads' => 20])->save();
        $targets->refresh($this->brand);
        $this->assertSame(20, $target->fresh()->monthly_leads, 'a nightly refresh never overwrites an approved goal');
        $this->assertSame(BrandTarget::APPROVED, $target->fresh()->status);
    }

    public function test_a_brand_without_measured_demand_gets_no_proposal(): void
    {
        BrandConversionSource::query()->delete();
        $target = app(BrandTargets::class)->refresh($this->brand);

        $this->assertSame(BrandTarget::UNMEASURED, $target->status);
        $this->assertNull($target->proposed_leads);
        $this->assertSame([], app(BrandTargets::class)->waiting()->all());
    }

    public function test_the_split_adds_up_to_the_goal(): void
    {
        $split = BrandTargets::split(25, ['web' => 10, 'google_ads' => 7, 'meta' => 0, 'gbp' => 3]);

        $this->assertSame(25, array_sum($split));
        $this->assertSame(['web' => 14, 'google_ads' => 8, 'meta' => 0, 'gbp' => 3], $split);
        $this->assertSame(['web' => 4, 'google_ads' => 0, 'meta' => 0, 'gbp' => 0], BrandTargets::split(4, []));
    }

    public function test_the_command_reads_every_brand_once_a_day(): void
    {
        $this->artisan('moxdop:targets:refresh')->expectsOutputToContain('1 marka okundu')->assertSuccessful();
        $this->artisan('moxdop:targets:refresh')->expectsOutputToContain('0 marka okundu')->assertSuccessful();
        $this->artisan('moxdop:targets:refresh', ['--all' => true])->expectsOutputToContain('1 marka okundu')->assertSuccessful();
        $this->assertSame(1, BrandTarget::query()->count());
    }

    public function test_komuta_lists_goals_waiting_and_approves_one_with_a_typed_number(): void
    {
        $target = app(BrandTargets::class)->refresh($this->brand);

        Livewire::test(KomutaPage::class)
            ->assertSee('Marka amaçları onayını bekliyor')
            ->assertSee('Ayda 15 talep')
            ->set('goals.'.$target->id, '18')
            ->call('approveItem', 'target:'.$target->id)
            ->assertSee('ayda 18 talep');

        $this->assertSame(18, $target->fresh()->monthly_leads);
        $this->assertSame(['web' => 18, 'google_ads' => 0, 'meta' => 0, 'gbp' => 0], $target->fresh()->split);
        $this->assertNull(collect(app(KomutaReader::class)->box()['groups'])->firstWhere('key', 'targets'), 'nothing left to approve');
    }

    public function test_komuta_groups_desk_work_across_brands_and_approves_a_package(): void
    {
        $this->suggestion('Başlık 1', ['seo_title' => 'Birinci başlık']);
        $this->suggestion('Başlık 2', ['seo_title' => 'İkinci başlık']);

        $box = app(KomutaReader::class)->box();
        $group = collect($box['groups'])->first(fn (array $g): bool => str_starts_with($g['key'], 'kind:'));
        $this->assertNotNull($group);
        $this->assertSame(2, $group['count']);
        $this->assertSame(1, $group['brands']);
        $item = $group['items'][0];
        $this->assertSame('Panorama Ankara', $item['brand']);
        $this->assertCount(2, app(KomutaReader::class)->packageIds($item['key']));

        Livewire::test(KomutaPage::class)->assertSee($group['title'])->call('approveItem', $item['key'])->assertSee('2 iş onaylandı');

        $this->assertSame(1, WorkBatch::query()->count(), 'the approval is one row of Akış');
    }

    public function test_gerek_yok_drops_a_package(): void
    {
        $this->suggestion('Başlık 1', ['seo_title' => 'Birinci başlık']);
        $item = collect(app(KomutaReader::class)->box()['groups'])->first(fn (array $g): bool => str_starts_with($g['key'], 'kind:'))['items'][0];

        Livewire::test(KomutaPage::class)->call('dismissItem', $item['key'])->assertSee('1 iş kaldırıldı');

        $this->assertSame([], app(KomutaReader::class)->packageIds($item['key']));
    }

    public function test_portfoy_puts_the_brand_behind_its_goal_first_and_approves_in_the_row(): void
    {
        $target = app(BrandTargets::class)->refresh($this->brand);

        $row = app(PortfolioReader::class)->rows()[0];
        $this->assertSame('Panorama Ankara', $row['name']);
        $this->assertContains('amaç onaylanmadı', $row['reasons']);
        $this->assertSame('ok', $row['channels']['web']);
        $this->assertSame('none', $row['channels']['meta']);

        Livewire::test(PortfolioPage::class)
            ->assertSee('Panorama Ankara')
            ->call('approve', $this->brand->id)
            ->assertSee('ayda 15 talep');

        $this->assertTrue($target->fresh()->isApproved());
        $row = app(PortfolioReader::class)->rows()[0];
        $this->assertSame('behind', $row['progress']['state']);
        $this->assertContains('amacın %13 gerisinde', $row['reasons']);
    }

    public function test_impact_is_worded_as_an_estimate(): void
    {
        $this->assertSame('+3 talep/ay', TargetImpact::label(3.0));
        $this->assertSame('+0,4 talep/ay', TargetImpact::label(0.4));
        $this->assertSame('', TargetImpact::label(0.04));
        $this->assertSame('', TargetImpact::label(null));
        $this->assertNull(app(TargetImpact::class)->forPackage(['brand_id' => $this->brand->id, 'kind' => RepairDesk::SITE_FIELDS, 'clicks' => 300]), 'no measured leads per click yet');
    }

    public function test_a_package_on_pages_with_clicks_is_given_its_leads_a_month(): void
    {
        BrandTarget::query()->create(['brand_id' => $this->brand->id, 'status' => BrandTarget::PROPOSED, 'basis' => ['web_leads_per_click' => 0.05]]);

        // 900 clicks and 30,000 impressions in 90 days: (300 × 10% + 10,000 × 0.2%) × 0.05 = 2.5 leads a month.
        $leads = app(TargetImpact::class)->forPackage(['brand_id' => $this->brand->id, 'kind' => RepairDesk::SITE_FIELDS, 'clicks' => 900, 'impressions' => 30000, 'weight' => 1.0]);
        $this->assertSame(2.5, $leads);
        $this->assertSame('+2,5 talep/ay', TargetImpact::label($leads));
        $this->assertNull(app(TargetImpact::class)->forPackage(['brand_id' => $this->brand->id, 'kind' => RepairDesk::GBP_FIELDS, 'clicks' => 900]), 'profile fields do not move search');
    }

    public function test_cmd_k_finds_work_with_turkish_letters_either_way(): void
    {
        $this->suggestion('İmplant sayfasının başlığı', ['seo_title' => 'Yeni başlık']);
        Cache::flush();

        Livewire::test(GlobalSearch::class)->set('q', 'panorama')->assertSee('Panorama Ankara · 1 karar')->assertSee('İş · Onarım masası');
        Livewire::test(GlobalSearch::class)->set('q', 'birlesSin')->assertSee('Aynı konuyu anlatan sayfalar birleşsin');
        Livewire::test(GlobalSearch::class)->set('q', 'başlığı')->assertSee('İmplant sayfasının başlığı')->assertSee('Açık iş · Panorama Ankara');
        Livewire::test(GlobalSearch::class)->set('q', 'portf')->assertSee(route('operator.portfolio'));
        $this->assertSame('isik implant', GlobalSearch::fold('IŞIK İmplant'));
    }

    public function test_komuta_is_home_and_bugun_stays_one_click_away(): void
    {
        $this->get('/')->assertOk()->assertSee('Komuta')->assertSee(route('operator.today', absolute: false));
        $this->get(route('operator.today'))->assertOk();
        $this->get(route('operator.portfolio'))->assertOk()->assertSee('Portföy');
    }

    /** @param  array<string, string>  $fields */
    private function suggestion(string $title, array $fields): Suggestion
    {
        return Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'komuta.test', 'fingerprint' => hash('sha256', $title.random_int(1, PHP_INT_MAX)),
            'material_hash' => hash('sha256', 'x'), 'title' => $title, 'reason' => 'Gerekçe.', 'priority' => 2, 'action_type' => 'title_description',
            'status' => Suggestion::OPEN, 'action' => ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => $fields]], 'evidence' => [],
            'page_id' => $this->implantPage->id,
        ]);
    }

    public function test_profile_names_with_district_or_service_words_are_one_decision_per_brand(): void
    {
        $profiles = collect(['Panorama Ankara İmplant', 'Panorama Kızılay Diş'])->map(fn (string $name): DigitalAsset => DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'name' => $name]));
        $rows = $profiles->map(fn (DigitalAsset $p): Suggestion => Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'gbp', 'decision_key' => 'gbp:'.$p->id.':standard:'.KomutaReader::NAME_STANDARD, 'fingerprint' => Str::random(40), 'material_hash' => 'm',
            'title' => 'İşletme adına eklenen kelimeler', 'reason' => 'Profil adında markada olmayan "implant" kelimesi var.', 'priority' => 1, 'action_type' => 'gbp_standard',
            'status' => Suggestion::OPEN, 'action' => ['standard_id' => KomutaReader::NAME_STANDARD, 'todo' => 'Adı tabeladaki hale getir.'], 'evidence' => [],
            'target_type' => 'gbp_location', 'target_id' => $p->id]));

        $group = collect(app(KomutaReader::class)->box()['groups'])->firstWhere('key', 'gbp-names');
        $this->assertSame(2, $group['count']);
        $this->assertStringContainsString('Panorama Ankara İmplant', $group['items'][0]['line']);

        Livewire::test(KomutaPage::class)->assertSee('İşletme adında semt ya da hizmet kelimesi')
            ->call('dismissItem', 'gbpname:'.$this->brand->id)->assertSee('2 profilin adı olduğu gibi kalıyor');
        $this->assertSame([Suggestion::DISMISSED, Suggestion::DISMISSED], $rows->map(fn (Suggestion $s): string => $s->fresh()->status)->all());

        $rows->each(fn (Suggestion $s) => $s->fresh()->forceFill(['status' => Suggestion::OPEN])->save());
        Livewire::test(KomutaPage::class)->call('approveItem', 'gbpname:'.$this->brand->id)->assertSee('2 profilin adı düzeltilecek');
        $this->assertSame([Suggestion::APPROVED, Suggestion::APPROVED], $rows->map(fn (Suggestion $s): string => $s->fresh()->status)->all());
    }
}
