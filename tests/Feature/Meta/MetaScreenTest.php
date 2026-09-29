<?php

namespace Tests\Feature\Meta;

use App\Livewire\Demo\Meta\OverviewPage;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\MetaLead;
use App\Models\Suggestion;
use App\Services\Meta\MetaChecks;
use App\Services\Meta\MetaScreen;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Support\Integrations\Meta\MetaResourceType;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\SeedsMetaAccount;
use Tests\TestCase;

/**
 * Faz 6 Meta screen: numbers from the collected tables, the ten system checks → suggestions (veri yok / veri az),
 * approve → instruction + CSV → applied with baseline, lead marks per campaign, Meta / GA4 / CRM kept apart, tabs.
 */
final class MetaScreenTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMetaAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Http::preventStrayRequests();
        $this->seedMetaAccount();
    }

    private function page(string $tab): Testable
    {
        return Livewire::actingAs($this->admin)->test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => $tab]);
    }

    public function test_numbers_come_from_ad_rows_without_double_counting_lead_aliases(): void
    {
        $overview = app(MetaScreen::class)->overview($this->asset);

        $this->assertSame(['from' => '2026-10-02', 'to' => '2026-10-29', 'prev_from' => '2026-09-04', 'prev_to' => '2026-10-01'], $overview['window']);
        $this->assertSame(4200.0, $overview['current']['spend']);
        $this->assertSame(56.0, $overview['current']['results'], 'lead + lead_grouped of the same event count once');
        $this->assertSame(75.0, $overview['current']['cpr']);
        $this->assertSame(112.0, $overview['previous']['results']);
        $this->assertSame('Diş İmplantı Lead Ankara', $overview['top'][0]['name']);
        $this->assertSame('silent', $overview['pixel']['state']);
    }

    public function test_all_ten_checks_fire_with_evidence_and_at_most_one_suggestion_each(): void
    {
        $states = collect(app(MetaChecks::class)->sync($this->asset))->keyBy('id');

        $this->assertCount(10, $states);
        $this->assertSame(array_fill_keys(array_keys(MetaChecks::CHECKS), 'issue'), $states->map->state->all());
        $rows = Suggestion::query()->where('channel', 'meta')->where('action_type', 'meta_check')->get()->keyBy(fn (Suggestion $s): string => $s->action['check']);
        $this->assertCount(10, $rows);
        $this->assertTrue($rows->every(fn (Suggestion $s): bool => $s->target_type === 'meta' && $s->target_id === $this->asset->id && $s->evidence !== []));
        $this->assertSame('İzmir geniş', $rows['region']->evidence[0]['reklam_seti']);
        $this->assertSame('ana hizmet için reklam yok', $rows['service']->evidence[0]['sorun']);
        $this->assertSame('Zirkonyum Kaplama', $rows['service']->evidence[0]['hizmet']);
        $this->assertSame('farklı alan adı', $rows['landing']->evidence[0]['sorun']);
        $this->assertSame('Trafik görsel reklamı', $rows['weak_ad']->evidence[0]['reklam']);
        $this->assertSame('İmplant video reklamı', $rows['fatigue']->evidence[0]['reklam']);
        $this->assertStringContainsString('utm_source=facebook', $rows['utm']->action['text']);

        app(MetaChecks::class)->sync($this->asset);
        $this->assertSame(10, Suggestion::query()->where('action_type', 'meta_check')->count(), 'a re-run refreshes by fingerprint');
    }

    public function test_little_data_never_proposes_closing_an_ad_and_no_data_says_veri_yok(): void
    {
        DB::table('meta_typed_action_daily')->where('reporting_date', '>=', '2026-10-02')->delete();
        DB::table('meta_typed_action_daily')->where('reporting_date', '<', '2026-10-02')->update(['action_value' => 0.1]);

        $states = collect(app(MetaChecks::class)->sync($this->asset))->keyBy('id');

        $this->assertSame('low_data', $states['weak_ad']['state']);
        $this->assertSame('low_data', $states['change']['state']);
        $this->assertSame(0, Suggestion::query()->whereIn('decision_key', ['meta:'.$this->asset->id.':check:weak_ad', 'meta:'.$this->asset->id.':check:change'])->count());

        $unbound = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'meta_ads', 'name' => 'Bağsız']);
        $empty = collect(app(MetaChecks::class)->sync($unbound));
        $this->assertTrue($empty->every(fn (array $c): bool => $c['state'] === 'no_data'));
        $this->page('todo')->assertSee('Pixel / CAPI')->assertSee('veri az');
    }

    public function test_account_is_only_the_explicitly_bound_ad_account(): void
    {
        $other = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'meta_ads']);
        CoreExternalResource::factory()->create(['integration_id' => $this->resource->integration_id, 'provider' => 'meta', 'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_999', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        $this->assertNull(app(MetaScreen::class)->account($other), 'an unbound asset never picks an account');

        $business = CoreExternalResource::factory()->create(['integration_id' => $this->resource->integration_id, 'provider' => 'meta', 'resource_type' => MetaResourceType::META_BUSINESS,
            'external_id' => 'biz_1', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $other->id, 'external_resource_id' => $business->id, 'capability' => MetaAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $this->assertNull(app(MetaScreen::class)->account($other), 'a business is not an ad account');
        $this->assertSame('777', app(MetaScreen::class)->account($this->asset)['account_id']);
    }

    public function test_approve_gives_a_copyable_instruction_and_csv_then_applied_stores_the_baseline(): void
    {
        app(MetaChecks::class)->sync($this->asset);
        $utm = Suggestion::query()->where('decision_key', 'meta:'.$this->asset->id.':check:utm')->sole();

        $page = $this->page('todo')->assertSee('UTM eksik: 1 bağlantı')->call('approveSuggestion', $utm->id)->assertSee('Uygulanacaklar · 1')->assertSee('CSV indir');
        $this->assertSame(Suggestion::APPROVED, $utm->fresh()->status);
        $csv = $page->call('exportCsv')->effects['download'] ?? null;
        $this->assertNotNull($csv);
        $this->assertStringContainsString('utm_source=facebook', base64_decode((string) $csv['content']));

        $page->call('markApplied', $utm->id);
        $utm->refresh();
        $this->assertSame(Suggestion::APPLIED, $utm->status);
        $this->assertNotNull($utm->applied_at);
        $this->assertSame(4200, (int) $utm->baseline['metric']['spend']);
        $this->assertSame(75, (int) $utm->baseline['metric']['cpr']);
        $this->assertSame(0, ExternalWriteAction::query()->count(), 'nothing is written to Meta');
    }

    public function test_lead_export_is_imported_without_contact_details_and_marked_per_campaign(): void
    {
        $csv = "id\tcreated_time\tad_name\tcampaign_id\tcampaign_name\tform_id\tform_name\tfull_name\tphone_number\n"
            ."l:101\t2026-10-20T10:00:00+0300\tİmplant video reklamı\tc:c1\tDiş İmplantı Lead Ankara\tf:9\tİmplant formu\tAyşe Yılmaz\t+905551112233\n"
            ."l:102\t2026-10-21T10:00:00+0300\tİmplant video reklamı\tc:c1\tDiş İmplantı Lead Ankara\tf:9\tİmplant formu\tMehmet Kaya\t+905552223344\n"
            ."l:103\t2026-10-22T10:00:00+0300\tTrafik görsel reklamı\tc:c2\tGenel Trafik\tf:9\tİmplant formu\tAli Veli\t+905553334455\n";
        $this->travelBack(); // Livewire test uploads are signed with the real clock.
        $file = UploadedFile::fake()->createWithContent('leads.csv', "\xFF\xFE".mb_convert_encoding($csv, 'UTF-16LE', 'UTF-8'));

        $page = $this->page('measurement')->set('leadFile', $file)->call('uploadLeads')->assertHasNoErrors();

        $this->assertSame(3, MetaLead::query()->count());
        $this->assertSame(['101', '102', '103'], MetaLead::query()->orderBy('lead_ref')->pluck('lead_ref')->all());
        $stored = json_encode(MetaLead::query()->get()->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Ayşe', $stored);
        $this->assertStringNotContainsString('5551112233', $stored);

        [$a, $b, $c] = MetaLead::query()->orderBy('lead_ref')->get()->all();
        $page->call('markLead', $a->id, 'randevu')->call('markLead', $b->id, 'satis')->call('markLead', $c->id, 'uygunsuz')->call('markLead', $c->id, 'yanlis');
        $this->assertSame('uygunsuz', $c->fresh()->mark, 'an unknown mark is refused');
        $page->call('markLead', $b->id, '')->call('markLead', $b->id, 'satis');
        $this->assertSame($this->admin->id, (int) $b->fresh()->marked_by);

        $page->call('setTab', 'measurement')->assertSee('Lead kalitesi')->assertSee('Genel Trafik')
            ->assertSee('randevu + satış · 3 lead, 3 işaretli')->assertDontSee('Ayşe');
    }

    public function test_measurement_shows_attribution_pixel_and_meta_ga4_crm_separately(): void
    {
        $ga4 = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'ga4', 'name' => 'Panorama GA4']);
        $google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $property = CoreExternalResource::factory()->create(['integration_id' => $google->id, 'provider' => 'google', 'resource_type' => 'ga4_property', 'external_id' => 'properties/5', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $ga4->id, 'external_resource_id' => $property->id, 'capability' => 'ga4', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        foreach ([['facebook', 'paid', 300, 12], ['google', 'organic', 900, 40]] as [$source, $medium, $sessions, $events]) {
            DB::table('ga4_landing_source_daily')->insert(['external_resource_id' => $property->id, 'property_id' => '5', 'reporting_date' => '2026-10-20', 'landingPage' => '/implant/',
                'sessionSource' => $source, 'sessionMedium' => $medium, 'sessions' => $sessions, 'keyEvents' => $events, 'contract_version' => 1, 'first_collected_at' => now(),
                'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $source), 'created_at' => now(), 'updated_at' => now()]);
        }
        MetaLead::query()->create(['brand_id' => $this->brand->id, 'digital_asset_id' => $this->asset->id, 'lead_ref' => '1', 'received_at' => '2026-10-20 10:00:00', 'campaign_name' => 'Diş İmplantı Lead Ankara', 'mark' => 'randevu']);

        $m = app(MetaScreen::class)->measurement($this->asset->load('brand'));

        $this->assertSame(56.0, $m['meta']['results']);
        $this->assertSame(['has_data' => true, 'sessions' => 300, 'key_events' => 12.0], array_intersect_key($m['ga4'], array_flip(['has_data', 'sessions', 'key_events'])), 'only Meta sources');
        $this->assertSame(1, $m['crm']['marks']['randevu']);
        $this->assertSame(['7 gün tıklama · 1 gün görüntüleme'], array_keys($m['attribution']));
        $this->page('measurement')->assertSee('Meta sonuçları · 28 gün')->assertSee('GA4 · Meta kaynaklı · 28 gün')->assertSee('CRM (lead işaretleri) · 28 gün')
            ->assertSee('Üç kaynak ayrı sayar; toplanmaz.')->assertSee('7 gün tıklama · 1 gün görüntüleme')->assertSee('CAPI: veri yok');
    }

    public function test_every_tab_renders_and_old_tab_keys_still_open(): void
    {
        app(MetaChecks::class)->sync($this->asset);

        $this->page('overview')->assertSee('Harcama · 28 gün')->assertSee('4.200,00 TRY')->assertSee('Olay yok');
        $this->page('todo')->assertSee('Kontroller')->assertSee('Kreatif öner')->assertSee('Kampanya yapısı öner')->assertSee('Form / açılış sayfası öner')->assertSee('Sonuç başı maliyet arttı');
        $this->page('creatives')->assertSee('İmplant video reklamı')->assertSee('Yoruldu')->assertSeeHtml('https://cdn.test/thumb-1.jpg');
        $this->page('strategy')->assertSee('Mevcut yapı · 28 gün')->assertSee('Hizmete göre')->assertSee('Hizmete bağlanamadı');
        $this->page('analysis')->assertSee('Kampanyalar')->assertSee('Reklam setleri')->assertSee('Bölgeye göre')->assertSee('+100,0%')
            ->call('setDays', 7)->assertSet('days', 7)->call('setDays', 5)->assertSet('days', 28);
        $this->page('settings')->assertSee('act_777')->assertSee('Çankaya şubesi (şube)')->assertSee('tr, en')->assertSee('Meta entegrasyonu');
        $this->page('campaigns')->assertSet('tab', 'analysis');
        $this->page('operations')->assertSet('tab', 'todo');

        $this->actingAs($this->admin)->get(route('operator.meta.overview', ['assetId' => $this->asset->id, 'tab' => 'creatives']))->assertOk()
            ->assertSee('Genel Bakış')->assertSee('Yapılacaklar')->assertSee('Kreatifler')->assertSee('Kampanya Stratejisi')->assertSee('Ölçümleme')->assertSee('Analiz')->assertSee('Ayarlar');
    }
}
