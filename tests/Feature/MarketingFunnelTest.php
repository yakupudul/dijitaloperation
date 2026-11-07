<?php

namespace Tests\Feature;

use App\Livewire\Operator\Marketing\MarketingPage;
use App\Models\AgencySetting;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\SalesLead;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Marketing\MarketingFunnel;
use App\Services\Marketing\OwnChannels;
use App\Services\Sales\Agreements;
use App\Services\Sales\SalesLeads;
use App\Support\OperatorMenu;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pazarlama hunisi (Moximu büyüme sistemi, Aşama 4, 2026-11-07): kaynak → başvuru → konuşma → teklif → müşteri → ₺
 * from the sales records, filtered by Tür against the 25-a-month health target channel by channel; the agency's own
 * channels as tabs; "bu ay ne çalıştı" in sentences on the 1st.
 */
final class MarketingFunnelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'name' => 'Yakup Udul', 'locale' => 'tr']);
        $this->admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        CarbonImmutable::setTestNow('2026-11-20 09:00:00');
        Http::fake();
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function lead(string $name, array $attributes): SalesLead
    {
        return SalesLead::query()->create(array_merge(['name' => $name, 'source' => 'whatsapp', 'stage' => 'new', 'kind' => 'clinic', 'created_at' => '2026-11-05 08:00:00'], $attributes));
    }

    public function test_the_funnel_reads_the_channel_from_the_record_and_counts_health_applications_against_the_target(): void
    {
        $leads = app(SalesLeads::class);
        // Meta: a WhatsApp chat from an ad, a Meta form; one became a customer with an agreement.
        $won = $this->lead('Dr. Ayşe', ['heard_from' => 'meta_ads', 'stage' => 'priced']);
        $customer = Customer::factory()->create(['name' => 'Ayşe Klinik', 'monthly_fee' => 0]);
        $brand = Brand::query()->create(['customer_id' => $customer->id, 'name' => 'Ayşe Klinik']);
        $won->forceFill(['customer_id' => $customer->id, 'brand_id' => $brand->id])->save();
        $leads->setStage($won, 'won', null, $this->admin);
        app(Agreements::class)->start($brand, ['kind' => 'monthly', 'fee' => 14000, 'starts_on' => '2026-11-10', 'due_day' => 1], $this->admin, $won);
        $this->lead('Meta formu', ['source' => 'meta_form', 'stage' => 'talking']);
        // Web: a site form from a Google search, a WhatsApp from Google; one offered.
        $this->lead('Form', ['source' => 'form', 'heard_from' => 'google', 'stage' => 'priced']);
        $this->lead('Google WhatsApp', ['heard_from' => 'google']);
        $this->lead('Haritalar', ['heard_from' => 'maps', 'kind' => 'practice', 'stage' => 'contacted']);
        $this->lead('Instagram DM', ['source' => 'instagram', 'kind' => 'physician']);
        $this->lead('Tavsiye', ['heard_from' => 'referral']);
        $this->lead('Bilinmeyen', ['heard_from' => null]);
        // Not counted: a scrap dealer (not health), a record with no kind yet, one we reached out to, an irrelevant one, last month's.
        $this->lead('Bornova Hurda', ['kind' => 'factory', 'heard_from' => 'referral', 'stage' => 'won', 'won_at' => '2026-11-12 10:00:00']);
        $this->lead('Türsüz', ['kind' => null, 'source' => 'form']);
        $this->lead('Biz yazdık', ['source' => 'link']);
        $this->lead('Spam', ['lifecycle' => 'irrelevant']);
        $this->lead('Geçen ay', ['heard_from' => 'meta_ads', 'created_at' => '2026-10-05 08:00:00']);
        // An existing customer's record (opened by forCustomer) is not an application.
        $old = Customer::factory()->create(['name' => 'Panorama', 'monthly_fee' => 18000, 'created_at' => '2025-01-01 00:00:00']);
        $leads->forCustomer($old);

        $funnel = app(MarketingFunnel::class);
        $this->assertSame('meta', MarketingFunnel::channel($won));
        $f = $funnel->funnel(CarbonImmutable::parse('2026-11-01', 'Europe/Istanbul'));
        $rows = collect($f['rows'])->keyBy('key');
        $this->assertSame(['applications' => 2, 'talked' => 2, 'offered' => 1, 'won' => 1, 'revenue' => 14000, 'target' => 12], collect($rows['meta'])->only(['applications', 'talked', 'offered', 'won', 'revenue', 'target'])->all());
        $this->assertSame(2, $rows['web']['applications']);
        $this->assertSame(1, $rows['web']['offered']);
        $this->assertSame(1, $rows['gbp']['applications']);
        $this->assertSame(1, $rows['social']['applications']);
        $this->assertSame(1, $rows['referral']['applications'], 'the scrap dealer is not health: not in this row');
        $this->assertSame(1, $rows['unknown']['applications']);
        $this->assertSame(1, $rows['outbound']['applications']);
        $this->assertSame(['applications' => 9, 'talked' => 3, 'offered' => 2, 'won' => 1, 'revenue' => 14000, 'target' => 25, 'counted' => 7], $f['totals']);
        $this->assertSame(['applications' => 2, 'won' => 1, 'revenue' => 0, 'label' => 'Sağlık dışı ve türü boş'], $f['excluded']);
        $this->assertSame('Dr. Ayşe', $f['won_names'][0]['title']);

        $all = $funnel->funnel(CarbonImmutable::parse('2026-11-01', 'Europe/Istanbul'), 'hepsi');
        $this->assertSame(11, $all['totals']['applications']);
        $this->assertSame(2, $all['totals']['won']);
        $this->assertSame(0, $all['excluded']['applications']);
        $this->assertSame(1, $funnel->funnel(CarbonImmutable::parse('2026-11-01', 'Europe/Istanbul'), 'practice')['totals']['applications']);
        $quarter = $funnel->funnel(CarbonImmutable::parse('2026-11-01', 'Europe/Istanbul'), 'saglik', 3);
        $this->assertSame(8, $quarter['totals']['counted'], 'last month\'s Meta record joins the three-month read');
        $this->assertSame(75, $quarter['totals']['target']);

        $lines = $funnel->summary(CarbonImmutable::parse('2026-11-01', 'Europe/Istanbul'));
        $this->assertSame('Kasım ayında Sağlık başvurusu: 7 / 25 hedef (Meta reklamı 2/12, Web (arama) 2/8, Google Ads 0/3, İşletme Profili 1/2, Sosyal (organik) 1, Tavsiye / AFC 1).', $lines[0]);
        $this->assertContains('En çok başvuru Meta reklamı kanalından geldi (2).', $lines);
        $this->assertContains('Müşteri getiren: Meta reklamı (1 müşteri, 14.000 ₺/ay).', $lines);
        $this->assertContains('Teklif → müşteri: 2 tekliften 1 müşteri (%50).', $lines);
        $this->assertContains('Hiç başvuru getirmeyen: Google Ads (hedef 3).', $lines);
        $this->assertContains('Sağlık dışı ve türü boş: 2 başvuru, hedefe sayılmadı; 1 müşteri oldu.', $lines);

        // The target is recorded and editable; channels that never count have none.
        $this->assertSame(['meta' => 12, 'web' => 8, 'google_ads' => 3, 'gbp' => 2, 'social' => 0, 'referral' => 0, 'unknown' => 0, 'outbound' => 0], $funnel->targets());
        $funnel->saveTargets(['meta' => 15, 'web' => '10', 'google_ads' => 0, 'gbp' => 2, 'social' => 3, 'unknown' => 9]);
        $this->assertSame(30, array_sum($funnel->targets()));
        $this->assertSame(0, $funnel->targets()['unknown']);
    }

    public function test_the_screen_shows_the_funnel_the_own_channels_and_sits_in_the_menu_where_social_media_was(): void
    {
        $this->lead('Dr. Ayşe', ['heard_from' => 'meta_ads', 'stage' => 'talking']);
        $this->lead('Form', ['source' => 'form', 'heard_from' => 'google']);
        $this->lead('Instagram DM', ['source' => 'instagram', 'heard_from' => 'instagram']);
        $this->lead('Bornova Hurda', ['kind' => 'factory', 'heard_from' => 'referral']);
        $moximu = Brand::query()->create(['customer_id' => Customer::factory()->create(['name' => 'Moximu'])->id, 'name' => 'Moximu']);
        $site = DigitalAsset::factory()->create(['brand_id' => $moximu->id, 'type' => 'website', 'name' => 'moximu.com']);
        Cache::forget('demo-menu.agency-brand');
        $day = fn (string $date, int $clicks, int $impressions): array => ['digital_asset_id' => $site->id, 'external_resource_id' => 1, 'site_url' => 'https://moximu.com/', 'reporting_date' => $date,
            'clicks' => $clicks, 'impressions' => $impressions, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $date)];
        DB::table('gsc_property_daily')->insert([$day('2026-11-03', 120, 4000), $day('2026-11-10', 80, 3000), $day('2026-10-10', 100, 5000)]);
        SocialPost::query()->create(['brand_id' => $moximu->id, 'kind' => 'image', 'pillar' => 'rehber', 'title' => 'Klinik SEO', 'caption' => 'x', 'media' => [], 'publish_at' => '2026-11-04 08:30:00', 'status' => SocialPost::PUBLISHED, 'created_by' => 'claude']);
        SocialPost::query()->create(['brand_id' => $moximu->id, 'kind' => 'image', 'pillar' => 'vaka', 'title' => 'Vaka', 'caption' => 'x', 'media' => [], 'publish_at' => '2026-11-25 08:30:00', 'status' => SocialPost::DRAFT, 'created_by' => 'claude']);

        $own = app(OwnChannels::class);
        $site = $own->site(CarbonImmutable::parse('2026-11-01', 'Europe/Istanbul'));
        $this->assertTrue($site['connected']);
        $this->assertSame([200, 7000, 100, 5000, 1], [$site['clicks'], $site['impressions'], $site['clicks_prev'], $site['impressions_prev'], $site['applications']]);
        $social = $own->social(CarbonImmutable::parse('2026-11-01', 'Europe/Istanbul'));
        $this->assertSame([1, 1, 1, 1, ['rehber' => 1]], [$social['published'], $social['drafts'], $social['applications'], $social['seen'], $social['by_pillar']]);
        $ads = $own->ads(CarbonImmutable::parse('2026-11-01', 'Europe/Istanbul'));
        $this->assertFalse($ads['meta']['connected']);
        $this->assertSame(1, $ads['applications']['meta']);

        $page = Livewire::test(MarketingPage::class)
            ->assertSee('Pazarlama')->assertSee('Kasım 2026')->assertSee('Meta reklamı')->assertSee('1 / 12')->assertSee('Sağlık dışı ve türü boş')->assertSee('ne çalıştı')->assertSee('Gönderi planı');
        $page->set('filter', 'hepsi')->assertSee('Tavsiye / AFC')->assertDontSee('hedefe sayılmaz;');
        $page->set('tab', 'site')->assertSee('Google tıklaması')->assertSee('200')->assertSee('geçen ay 100 (+100%)');
        $page->set('tab', 'sosyal')->assertSee('Sosyalden başvuru')->assertSee('Rehber 1');
        $page->set('tab', 'reklam')->assertSee('Meta reklamı')->assertSee('bağlı değil');
        $page->set('tab', 'huni')->call('editTargets')->set('targets.meta', 15)->call('saveTargets')->assertHasNoErrors()->assertSee('Hedef kaydedildi: ayda 28 başvuru.')->assertSee('1 / 15');
        $page->call('shift', -1)->assertSet('month', '2026-10')->assertSee('Ekim 2026');

        $this->get('/pazarlama?sekme=huni&ay=2026-11&tur=saglik')->assertOk()->assertSee('Pazar')->assertSee('Meta reklamı');
        $this->get('/sosyal')->assertOk()->assertSee('Pazarlama');
        $menu = collect(OperatorMenu::groups('/pazarlama'))->flatMap(fn (array $g): array => $g['items']);
        $this->assertNotNull($menu->firstWhere('name', 'Pazarlama'));
        $this->assertNull($menu->firstWhere('name', 'Sosyal medya'), 'the social screen lives under Pazarlama now');
        $this->assertSame('Pazarlama', collect(OperatorMenu::groups('/sosyal', 'operator.social'))->flatMap(fn (array $g): array => $g['items'])->firstWhere('active', true)['name'] ?? null);
        $this->assertSame(1, OperatorMenu::computeBadges()['operator.marketing']['count'] ?? null, 'the draft waiting for approval');

        $this->assertTrue(collect(Schedule::events())->contains(fn ($event): bool => ($event->description ?? null) === 'marketing-month-summary'), 'the 1st-of-month summary is scheduled');
    }
}
