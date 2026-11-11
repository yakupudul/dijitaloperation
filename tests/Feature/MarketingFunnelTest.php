<?php

namespace Tests\Feature;

use App\Livewire\Operator\Marketing\MarketingPage;
use App\Models\AgencySetting;
use App\Models\Brand;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\SalesLead;
use App\Models\SalesLeadEvent;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\Marketing\AdLeads;
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
        $this->assertSame(['applications' => 9, 'talked' => 3, 'offered' => 2, 'won' => 1, 'revenue' => 14000, 'target' => 25, 'counted' => 8], $f['totals'], 'an unmarked source is still an application; our own outreach is not');
        $this->assertSame(['applications' => 2, 'won' => 1, 'revenue' => 0, 'label' => 'Sağlık dışı ve türü boş'], $f['excluded']);
        $this->assertSame('Dr. Ayşe', $f['won_names'][0]['title']);

        $all = $funnel->funnel(CarbonImmutable::parse('2026-11-01', 'Europe/Istanbul'), 'hepsi');
        $this->assertSame(11, $all['totals']['applications']);
        $this->assertSame(2, $all['totals']['won']);
        $this->assertSame(0, $all['excluded']['applications']);
        $this->assertSame(1, $funnel->funnel(CarbonImmutable::parse('2026-11-01', 'Europe/Istanbul'), 'practice')['totals']['applications']);
        $quarter = $funnel->funnel(CarbonImmutable::parse('2026-11-01', 'Europe/Istanbul'), 'saglik', 3);
        $this->assertSame(9, $quarter['totals']['counted'], 'last month\'s Meta record joins the three-month read');
        $this->assertSame(75, $quarter['totals']['target']);

        $lines = $funnel->summary(CarbonImmutable::parse('2026-11-01', 'Europe/Istanbul'));
        $this->assertSame('Kasım ayında Sağlık başvurusu: 8 / 25 hedef (Meta reklamı 2/12, Web (arama) 2/8, Google Ads 0/3, İşletme Profili 1/2, Sosyal (organik) 1, Tavsiye / AFC 1, Bilinmiyor 1).', $lines[0]);
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

    public function test_the_screen_shows_the_channels_with_what_they_cost_and_brought_and_sits_in_the_menu_where_social_media_was(): void
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
        // Data up to the 10th: November 1-10 is compared with October 1-10, not with the whole of October.
        DB::table('gsc_property_daily')->insert([$day('2026-11-03', 120, 4000), $day('2026-11-10', 80, 3000), $day('2026-10-10', 100, 5000), $day('2026-10-25', 900, 9000)]);
        $meta = fn (string $date, float $spend, int $messages, string $campaign): array => ['digital_asset_id' => $site->id, 'account_id' => 'act_1', 'reporting_date' => $date, 'level' => 'country',
            'ad_id' => 'ad-'.$campaign, 'campaign_id' => 'c-'.$campaign, 'campaign_name' => $campaign, 'country' => 'TR', 'spend' => $spend, 'messages' => $messages, 'clicks' => 10, 'impressions' => 500];
        DB::table('meta_geo_results_daily')->insert([$meta('2026-11-02', 600, 4, 'Seo kampanyası'), $meta('2026-11-04', 500, 2, 'Web sitesi')]);
        SocialPost::query()->create(['brand_id' => $moximu->id, 'kind' => 'image', 'pillar' => 'rehber', 'title' => 'Klinik SEO', 'caption' => 'x', 'media' => [], 'publish_at' => '2026-11-04 08:30:00', 'status' => SocialPost::PUBLISHED, 'created_by' => 'claude']);
        SocialPost::query()->create(['brand_id' => $moximu->id, 'kind' => 'image', 'pillar' => 'vaka', 'title' => 'Vaka gönderisi', 'caption' => 'x', 'media' => [], 'publish_at' => '2026-11-25 08:30:00', 'status' => SocialPost::DRAFT, 'created_by' => 'claude']);

        $own = app(OwnChannels::class);
        $month = CarbonImmutable::parse('2026-11-01', 'Europe/Istanbul');
        $s = $own->site($month);
        $this->assertTrue($s['connected']);
        $this->assertSame([200, 7000, 100, 5000, '2026-11-10'], [$s['clicks'], $s['impressions'], $s['clicks_prev'], $s['impressions_prev'], $s['last_day']]);
        $m = $own->meta($month);
        $this->assertSame([1100.0, 6, 'Seo kampanyası'], [$m['spend'], $m['messages'], $m['campaigns'][0]['name']]);
        $social = $own->social($month);
        $this->assertSame([0, 1, 1, ['rehber' => 1]], [$social['accounts'], $social['published'], $social['drafts'], $social['by_pillar']]);
        $this->assertFalse($own->googleAds($month)['connected']);

        $page = Livewire::test(MarketingPage::class)
            ->assertSee('Pazarlama')->assertSee('Kasım 2026')->assertSee('Genel bakış')->assertSee('Meta reklamı')->assertSee('1 / 12')
            ->assertSee('1.100 ₺ · 6 mesaj/form')->assertSee('Yapılacaklar')->assertSee('Instagram, Facebook ve LinkedIn bağlı değil')
            ->assertSee('Google Ads hedefi 3, bu ay harcama yok')->assertSee('Son 6 ay')->assertSee('ne çalıştı')->assertSee('Gönderi planı');
        $page->call('show', 'meta')->assertSet('channel', 'meta')->assertSee('Seo kampanyası')->assertSee('Başvuru başına')->assertSee('Dr. Ayşe');
        $page->call('show', 'web')->assertSee('Google tıklaması')->assertSee('+100%')->assertSee('Form');
        $page->call('show', 'social')->assertSee('hiçbir gönderi yayınlanamaz')->assertSee('Vaka gönderisi');
        $page->set('filter', 'hepsi')->call('show', 'referral')->assertSee('Bornova Hurda');
        $page->call('show', 'nope')->assertSet('channel', '');
        $page->call('editTargets')->set('targets.meta', 15)->call('saveTargets')->assertHasNoErrors()->assertSee('Hedef kaydedildi: ayda 28 başvuru.')->assertSee('1 / 15');
        $page->call('shift', -1)->assertSet('month', '2026-10')->assertSee('Ekim 2026');
        $page->call('shift', 1)->assertSet('month', '');

        // Old tab links open the channel that replaced the tab.
        Livewire::withQueryParams(['sekme' => 'reklam'])->test(MarketingPage::class)->assertSet('channel', 'meta')->assertSet('tab', '');
        Livewire::withQueryParams(['sekme' => 'site'])->test(MarketingPage::class)->assertSet('channel', 'web');
        $this->get('/pazarlama?kanal=meta&ay=2026-11&tur=saglik')->assertOk()->assertSee('Seo kampanyası');
        $this->get('/pazarlama?sekme=huni')->assertOk()->assertSee('Genel bakış');
        $this->get('/sosyal')->assertOk()->assertSee('Pazarlama');
        $menu = collect(OperatorMenu::groups('/pazarlama'))->flatMap(fn (array $g): array => $g['items']);
        $this->assertNotNull($menu->firstWhere('name', 'Pazarlama'));
        $this->assertNull($menu->firstWhere('name', 'Sosyal medya'), 'the social screen lives under Pazarlama now');
        $this->assertSame('Pazarlama', collect(OperatorMenu::groups('/sosyal', 'operator.social'))->flatMap(fn (array $g): array => $g['items'])->firstWhere('active', true)['name'] ?? null);
        $this->assertSame(1, OperatorMenu::computeBadges()['operator.marketing']['count'] ?? null, 'the draft waiting for approval');

        $this->assertTrue(collect(Schedule::events())->contains(fn ($event): bool => ($event->description ?? null) === 'marketing-month-summary'), 'the 1st-of-month summary is scheduled');
    }

    public function test_unknown_sources_get_a_suggestion_from_the_first_whatsapp_message_and_are_marked_with_one_click(): void
    {
        $inbox = CoreIntegration::factory()->create(['provider' => 'whatsapp', 'status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['phone_number_id' => 'PN1', 'waba_id' => 'W1', 'business_phone' => '905388610288', 'enabled' => true]]);
        $chat = function (string $phone, string $first) use ($inbox): int {
            $conversation = WhatsAppConversation::query()->create(['integration_id' => $inbox->id, 'phone_number_id' => 'PN1', 'contact_id' => $phone, 'last_message_at' => now()]);
            WhatsAppMessage::query()->create(['conversation_id' => $conversation->id, 'message_id' => 'm1-'.$phone, 'direction' => 'incoming', 'message_type' => 'text', 'body' => $first, 'sent_at' => '2026-11-05 08:00:00']);
            WhatsAppMessage::query()->create(['conversation_id' => $conversation->id, 'message_id' => 'm2-'.$phone, 'direction' => 'incoming', 'message_type' => 'text', 'body' => 'Instagramda da gördüm', 'sent_at' => '2026-11-05 09:00:00']);

            return (int) $conversation->id;
        };
        $ad = $this->lead('Reklamdan', ['conversation_id' => $chat('905321110001', 'Merhaba! Bu konuda daha fazla bilgi alabilir miyim?')]);
        $friend = $this->lead('Tavsiyeyle', ['conversation_id' => $chat('905321110002', 'Selam, Ahmet Bey sizi tavsiye etti')]);
        $plain = $this->lead('Düz mesaj', ['conversation_id' => $chat('905321110003', 'Merhaba fiyat alabilir miyim')]);
        $this->lead('Bilinen', ['heard_from' => 'google']);

        $funnel = app(MarketingFunnel::class);
        $records = collect($funnel->records(CarbonImmutable::parse('2026-11-01', 'Europe/Istanbul'), 'saglik', 1, 'unknown'))->keyBy('id');
        $this->assertCount(3, $records);
        $this->assertSame('meta_ads', $records[$ad->id]['suggestion']['key'], 'Meta\'s own Click-to-WhatsApp greeting; only the first message counts');
        $this->assertSame('referral', $records[$friend->id]['suggestion']['key']);
        $this->assertNull($records[$plain->id]['suggestion']);

        $page = Livewire::test(MarketingPage::class)->assertSee('3 kaydın kaynağı bilinmiyor')->assertSee('2 kayıt için ilk mesajdan öneri hazır')
            ->call('show', 'unknown')->assertSee('Önerileri uygula (2)')->assertSee('Düz mesaj');
        $page->call('setSource', $plain->id, 'maps')->assertSee('Düz mesaj: Google Haritalar olarak işaretlendi.');
        $this->assertSame('maps', $plain->fresh()->heard_from);
        $this->assertTrue(SalesLeadEvent::query()->where('lead_id', $plain->id)->get()->contains(fn (SalesLeadEvent $e): bool => str_starts_with((string) $e->body, 'Bizi nereden duydu: Google Haritalar')), 'the record\'s history notes it');
        $page->call('setSource', $plain->id, 'nonsense');
        $this->assertSame('maps', $plain->fresh()->heard_from);
        $page->call('applySuggestions')->assertSee('2 kaydın kaynağı ilk mesajına göre işaretlendi.');
        $this->assertSame(['meta_ads', 'referral'], [$ad->fresh()->heard_from, $friend->fresh()->heard_from]);
        $this->assertSame(0, collect($funnel->funnel(CarbonImmutable::parse('2026-11-01', 'Europe/Istanbul'))['rows'])->firstWhere('key', 'unknown')['applications']);
    }

    public function test_the_month_so_far_is_measured_against_the_days_gone_by_and_six_months_of_applications_are_kept(): void
    {
        $funnel = app(MarketingFunnel::class);
        $this->assertSame(['expected' => 17, 'day' => 20, 'days' => 30], $funnel->pace(CarbonImmutable::parse('2026-11-01', 'Europe/Istanbul'), 25));
        $this->assertNull($funnel->pace(CarbonImmutable::parse('2026-10-01', 'Europe/Istanbul'), 25)['expected'], 'a month that is over has no pace');

        $this->lead('Kasım', ['heard_from' => 'meta_ads']);
        $this->lead('Eylül', ['heard_from' => 'google', 'created_at' => '2026-09-10 08:00:00']);
        $trend = $funnel->trend(CarbonImmutable::parse('2026-11-01', 'Europe/Istanbul'));
        $this->assertSame(['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'], array_column($trend, 'month'));
        $this->assertSame([0, 0, 0, 1, 0, 1], array_column($trend, 'counted'));
        $this->assertSame(1, $trend[5]['by']['meta']);
        $this->assertSame(25, $trend[0]['target']);
    }

    public function test_an_ad_is_judged_by_the_prospects_it_brought_not_by_its_messages(): void
    {
        $inbox = CoreIntegration::factory()->create(['provider' => 'whatsapp', 'status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['phone_number_id' => 'PN1', 'waba_id' => 'W1', 'business_phone' => '905388610288', 'enabled' => true]]);
        $chat = fn (string $phone, ?array $referral): int => (int) WhatsAppConversation::query()->create(['integration_id' => $inbox->id, 'phone_number_id' => 'PN1', 'contact_id' => $phone,
            'last_message_at' => now(), 'last_incoming_at' => now(), 'referral' => $referral])->id;
        $adA = ['source_id' => 'A1', 'headline' => 'Haritada üst sıra', 'body' => 'Kliniğiniz Google\'da görünsün'];
        $adB = ['source_id' => 'B1', 'headline' => 'Web sitesi'];
        // Every chat gets its record by itself; the test only says what each person turned out to be.
        $record = fn (int $conversationId, array $attributes) => SalesLead::query()->where('conversation_id', $conversationId)->firstOrFail()->forceFill($attributes)->save();
        $record($chat('905321110011', $adA), ['stage' => 'priced', 'offer_amount' => 14000, 'offer_confirmed' => true]);
        $chat('905321110012', $adA);
        $record($chat('905321110013', $adB), ['lifecycle' => 'irrelevant']);
        $chat('905321110014', $adB);
        $chat('905321110015', null);

        $rows = app(AdLeads::class)->rows();
        $this->assertSame(['Haritada üst sıra', 'Web sitesi'], array_column($rows, 'ad'));
        $this->assertSame([2, 2, 0, 1, 0], [$rows[0]['wrote'], $rows[0]['prospects'], $rows[0]['screened'], $rows[0]['offers'], $rows[0]['won']]);
        $this->assertSame([2, 1, 1], [$rows[1]['wrote'], $rows[1]['prospects'], $rows[1]['screened']]);

        Livewire::test(MarketingPage::class)->call('show', 'meta')->assertSeeHtml('data-marketing-ad-leads')->assertSee('Haritada üst sıra');
    }
}
