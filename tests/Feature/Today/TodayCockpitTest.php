<?php

namespace Tests\Feature\Today;

use App\Enums\CustomerStatus;
use App\Livewire\Demo\Dashboard;
use App\Livewire\Demo\GlobalSearch;
use App\Models\AiLiveOperation;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\Operator\TodayCockpitReader;
use App\Support\OperatorMenu;
use App\View\Components\Operator\AssetContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Site\SiteTestCase;
use Tests\Support\InsertsFacts;

/**
 * Bugün as the day's cockpit (counters, alarms, approvals, last night) and the shared shell around it: one lit sidebar
 * entry, the profile menu with the language switch, Ctrl+K search over pages, the de-duplicated asset breadcrumb and
 * the colour rules of the shared badges.
 */
final class TodayCockpitTest extends SiteTestCase
{
    use InsertsFacts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00', 'Europe/Istanbul'));
        Cache::flush();
    }

    public function test_cockpit_shows_counters_alarms_approvals_and_last_night(): void
    {
        $this->negative('implant fiyat bedava');
        $this->negative('implant nedir');
        $meta = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'meta_ads', 'name' => 'Meta Ads · Obezite']);
        $this->alert($meta, 'delivery_stopped', 'critical', 'Meta reklamları harcama yapmadı');
        $this->alert($this->site, 'bad_review_unanswered', 'high', 'Yanıtsız düşük puanlı yorum');
        $this->alert($this->site, 'stale_data', 'medium', 'Veri güncel değil');
        AiLiveOperation::query()->create(['operation' => 'brand.care', 'label' => 'Marka bakım ajanı', 'agent' => 'BrandCareAgent',
            'status' => AiLiveOperation::DONE, 'started_at' => now()->subHours(8), 'finished_at' => now()->subHours(8)]);
        AiLiveOperation::query()->create(['operation' => 'brand.care', 'label' => 'Marka bakım ajanı', 'agent' => 'BrandCareAgent',
            'status' => AiLiveOperation::DONE, 'started_at' => now()->subDays(3), 'finished_at' => now()->subDays(3)]);
        $this->gscClicks(['2026-10-14' => 120, '2026-10-08' => 30, '2026-10-07' => 100]);

        $reader = app(TodayCockpitReader::class);
        $approvals = $reader->approvals();
        $this->assertSame(1, $approvals['total'], 'counted in packages: two negatives of one brand are one decision');
        $this->assertSame(2, $approvals['rows']);
        $this->assertSame(2, $approvals['packages'][0]['count']);
        $this->assertSame(['current' => 150, 'previous' => 100], $reader->week()['clicks'], 'last 7 complete days (08–14.10) against 01–07.10');
        $alarms = $reader->alarms();
        $this->assertSame([2, 1], [$alarms['accounts'], $alarms['loss']]);
        $this->assertSame(['critical', 'look'], array_column($alarms['items'], 'tone'), 'money loss first; freshness alerts stay off');
        $this->assertSame(1, $reader->overnight()['ai_done']);

        Livewire::test(Dashboard::class)
            ->assertSeeHtml('data-today-counter="approvals"')->assertSeeHtml('data-today-counter="week"')
            ->assertSeeHtml('data-today-counter="alarms"')->assertSeeHtml('data-today-counter="ai"')
            ->assertSee('Onay bekleyen')->assertSee('Google Ads negatif kelime')->assertSee('+50%')
            ->assertSee('Meta reklamları harcama yapmadı')->assertSeeHtml('data-today-alarm="critical"')->assertSeeHtml('data-today-alarm="look"')
            ->assertDontSee('Veri güncel değil')
            ->assertSee('Marka bakım ajanı')
            ->assertSeeHtml('data-chief-plan-empty')->assertSee('Şimdi hazırla');
    }

    public function test_empty_portfolio_renders_quiet_cockpit(): void
    {
        Livewire::test(Dashboard::class)
            ->assertSee('Onarım masası boş')->assertSee('Sorun yok')
            ->assertSee('Harcaması duran hesap, erişilemeyen site ya da biten bütçe yok.')
            ->assertSee('Son 24 saatte onaylı yazma ya da biten AI işi yok.');
    }

    public function test_bugun_query_count_has_a_fixed_ceiling_as_brands_grow(): void
    {
        $this->get(route('operator.dashboard'))->assertOk();
        $small = $this->countQueries();

        foreach (range(1, 6) as $i) {
            $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Ek marka '.$i]);
            $site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'name' => 'ek'.$i.'.test', 'domain' => 'ek'.$i.'.test']);
            $ads = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads', 'name' => 'Google Ads '.$i]);
            $this->alert($ads, 'budget_exhausted', 'critical', 'Google Ads bütçesi bitti');
            $this->alert($site, 'site_down', 'critical', 'Site erişilemiyor');
        }
        $large = $this->countQueries();

        $this->assertLessThanOrEqual(70, $large, 'Bugün stays under a fixed query ceiling');
        $this->assertLessThanOrEqual($small + 5, $large, 'six more brands with assets and alarms add only a few queries');
    }

    public function test_only_the_most_specific_sidebar_entry_is_lit(): void
    {
        $active = fn (string $path, ?string $route): array => collect(OperatorMenu::groups($path, $route))->flatMap(fn (array $g): array => $g['items'])
            ->where('active', true)->pluck('path')->values()->all();

        $this->assertSame([route('operator.websites', absolute: false)], $active('/assets/website/5', 'operator.website'));
        $this->assertSame([route('operator.assets', absolute: false)], $active('/assets/google-ads/5', 'operator.google-ads.overview'));
        $this->assertSame([route('operator.meta-desk', absolute: false)], $active('/assets/meta/5', 'operator.meta.overview'));
        $this->assertSame(['/'], $active('/', 'operator.dashboard'));

        $this->get(route('operator.website', ['assetId' => $this->site->id]))->assertOk()
            ->assertSeeHtml('aria-current="page"');
    }

    public function test_header_has_profile_menu_with_language_and_no_tagline_or_settings_button(): void
    {
        $this->get(route('operator.dashboard'))->assertOk()
            ->assertDontSee(__('operator.product.tagline'))
            ->assertSeeHtml('data-profile-menu')->assertSeeHtml('data-global-search')
            ->assertSeeHtml('aria-keyshortcuts="Control+K Meta+K"')
            ->assertSee(__('operator.profile.locale'));
    }

    public function test_search_finds_panel_pages_and_offers_queries(): void
    {
        app()->setLocale('tr');
        Livewire::test(GlobalSearch::class)->set('q', 'onarım')
            ->assertSee('Onarım masası')->assertSee(route('operator.repair'), false)
            ->assertSee('Sorgularda ara: "onarım"');
    }

    public function test_asset_breadcrumb_writes_names_once_and_links_the_brand_analysis(): void
    {
        $customer = Customer::factory()->create(['name' => 'Avrupadent', 'status' => CustomerStatus::Active]);
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Avrupadent']);
        $meta = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'meta_ads', 'name' => 'Meta Ads · Obezite ve Estetik']);

        $html = Blade::render('<x-operator.asset-context :asset-id="$id" />', ['id' => $meta->id]);

        $this->assertSame(1, substr_count($html, '>Avrupadent<'), 'customer named like its brand is written once');
        $this->assertStringContainsString('Meta Ads · Obezite ve Estetik', $html);
        $this->assertStringNotContainsString('Meta Ads: Meta Ads', $html);
        $this->assertStringNotContainsString('data-workspace-link', $html, 'no separate "Analiz marka ekranında" strip');
        $this->assertStringContainsString(e(route('operator.brand', ['brand' => $brand->id, 'tab' => 'meta'])), $html);
        $this->assertSame('Web sitesi · Örnek', AssetContext::labelFor('Web sitesi', 'Örnek'));
    }

    public function test_shared_badges_keep_orange_for_actions_only(): void
    {
        $primary = Blade::render('<x-ta.badge>Bilgi</x-ta.badge>');
        $this->assertStringNotContainsString('brand-', $primary);
        $this->assertStringContainsString('bg-gray-100', $primary);
        $this->assertStringContainsString('bg-error-50', Blade::render('<x-ta.badge color="critical">Kritik</x-ta.badge>'));
        $this->assertStringContainsString('bg-warning-50', Blade::render('<x-ta.badge color="look">Bak</x-ta.badge>'));
        $this->assertStringContainsString('bg-success-50', Blade::render('<x-ta.badge color="success">Tamam</x-ta.badge>'));
    }

    private function countQueries(): int
    {
        Cache::flush();
        $this->app->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(Dashboard::class)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function negative(string $text): Suggestion
    {
        return Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'google_ads', 'decision_key' => 'today.test', 'fingerprint' => hash('sha256', $text),
            'material_hash' => hash('sha256', 'x'), 'title' => 'Negatif: '.$text, 'reason' => 'Boşa harcama.', 'priority' => 2, 'action_type' => 'ads_negative',
            'status' => Suggestion::OPEN, 'action' => ['scope' => 'shared', 'text' => $text, 'match_type' => 'PHRASE', 'blocks' => []], 'evidence' => [],
        ]);
    }

    private function alert(DigitalAsset $asset, string $kind, string $severity, string $title): void
    {
        AssetAlert::query()->create([
            'digital_asset_id' => $asset->id, 'brand_id' => $asset->brand_id, 'alert_key' => hash('sha256', $kind.$asset->id), 'kind' => $kind,
            'severity' => $severity, 'title' => $title, 'message' => 'Ayrıntı.', 'data' => [],
            'first_detected_at' => now()->subDay(), 'last_detected_at' => now(),
        ]);
    }

    /** @param  array<string, int>  $days  Y-m-d => clicks */
    private function gscClicks(array $days): void
    {
        foreach ($days as $day => $clicks) {
            $values = ['digital_asset_id' => null, 'external_resource_id' => $this->gsc->id, 'site_url' => 'sc-domain:panorama.com.tr', 'reporting_date' => $day,
                'clicks' => $clicks, 'impressions' => 10 * $clicks, 'search_type' => 'web', 'metadata' => '{}'];
            $this->insertFact('gsc_property_daily', $values + ['contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
                'source_timezone' => 'Europe/Istanbul', 'record_fingerprint' => hash('sha256', json_encode($values)), 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
