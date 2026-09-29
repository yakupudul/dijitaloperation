<?php

namespace Tests\Feature\Analyst;

use App\Ai\Agents\Analyst\ChannelAnalystAgent;
use App\Enums\CustomerStatus;
use App\Enums\DigitalAssetStatus;
use App\Jobs\DraftMetaAdsCreativeJob;
use App\Livewire\Operator\Workspace\MetaTab;
use App\Models\AdvisorItem;
use App\Models\AnalystDecision;
use App\Models\AnalystRun;
use App\Models\Brand;
use App\Models\BrandServiceArea;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\LeadOutcome;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Analyst\AnalystEngine;
use App\Services\Analyst\AnalystPack;
use App\Services\Analyst\Meta\MetaAnalyst;
use App\Services\Analyst\Meta\MetaFacts;
use App\Services\Analyst\Meta\MetaPlanExport;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Meta channel of the brand workspace: pack from stored Meta data (2 ad accounts), Durum, cards, actions, Veri yok. */
final class MetaAnalystTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $main;

    private DigitalAsset $second;

    /** @var array<int, CoreExternalResource> asset id => resource */
    private array $resources = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00', 'Europe/Istanbul'));
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Atlas Diş']);
        $this->brand->sectors()->attach(ServiceCategory::query()->where('code', 'dental')->value('id'));
        BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'country_code' => 'TR', 'country_name' => 'Türkiye', 'city_name' => 'Ankara',
            'normalized_key' => 'tr|ankara', 'status' => 'active', 'priority_rank' => 1]);
        $integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['auth_method' => 'oauth', 'auth_status' => 'connected', 'connection_status' => 'connected', 'credential_status' => 'valid', 'granted_permissions' => ['ads_read', 'business_management']],
        ]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['access_token' => 'synthetic-token', 'granted_permissions' => ['ads_read']]]);
        $this->main = $this->account($integration, '11110001', 'Atlas Meta');
        $this->second = $this->account($integration, '22220002', 'Atlas Marka');
        $this->seedMain();
        $this->seedSecond();
    }

    public function test_pack_and_durum_come_from_both_accounts_with_fatigue_learning_region_tracking_and_lead_facts(): void
    {
        $pack = app(MetaAnalyst::class)->buildPack($this->brand);

        $this->assertTrue($pack->hasData(), (string) $pack->missing);
        $stats = collect($pack->stats)->keyBy('id');
        $this->assertSame([3080.0, 10], [$stats['spend_28d']['value'], $stats['spend_28d']['delta_pct']], '28 × (50 + 40 + 20) vs 28 × (40 + 40 + 20)');
        $this->assertSame([84.0, 50, 30.0, -25], [$stats['results_28d']['value'], $stats['results_28d']['delta_pct'], $stats['results_28d']['cpr'], $stats['results_28d']['cpr_delta_pct']]);
        $this->assertSame([50.0, 4, 8], [$stats['quality_lead_rate']['value'], $stats['quality_lead_rate']['qualified'], $stats['quality_lead_rate']['marked']]);
        $this->assertSame(2, $stats['tracking']['value'], 'stale pixel + ad set on an unknown pixel');
        $this->assertSame(1, $stats['learning_limited']['value']);
        $this->assertNotNull($stats['frequency_7d']['value']);

        $m = $this->main->id;
        $s = $this->second->id;
        foreach (['acc:'.$m, 'acc:'.$s, 'cmp:'.$m.'-c1', 'cmp:'.$s.'-c9', 'set:'.$m.'-s2', 'ad:'.$m.'-a1', 'reg:'.$m.'-ankara', 'reg:'.$m.'-istanbul', 'px:'.$m.'-p1',
            'cmpl:'.$m.'-a2', 'adv:'.$m.'-creative-fatigue-a1', 'lq:meta', 'lq:all', 'stat:spend_28d'] as $ref) {
            $this->assertTrue($pack->has($ref), $ref);
        }
        $this->assertTrue($pack->fact('ad:'.$m.'-a1')['fatigue'], 'frequency up, link CTR down');
        $this->assertTrue($pack->fact('set:'.$m.'-s2')['learning_limited']);
        $this->assertFalse($pack->fact('reg:'.$m.'-istanbul')['in_area']);
        $this->assertTrue($pack->fact('reg:'.$m.'-ankara')['in_area']);
        $this->assertSame(25.0, $pack->fact('reg:'.$m.'-istanbul')['share_pct']);
        $this->assertSame('sessiz', $pack->fact('px:'.$m.'-p1')['status']);
        $this->assertSame('üst huni', $pack->fact('cmp:'.$s.'-c9')['objective_fit']);
        $this->assertSame('lead/dönüşüm', $pack->fact('cmp:'.$m.'-c1')['objective_fit']);
        $this->assertStringContainsString('Kampanya / indirim / hediye', $pack->fact('cmpl:'.$m.'-a2')['rule']);
        $this->assertSame([50.0, 770.0, 2], [$pack->fact('lq:meta')['qualified_rate'], $pack->fact('lq:meta')['cost_per_qualified'], $pack->fact('lq:meta')['unmarked']]);
        $this->assertCount(2, array_filter(array_keys($pack->sections()['tracking']), fn (string $id): bool => str_starts_with($id, 'trk:'.$m)));
        $this->assertLessThan(AnalystPack::DEFAULT_TOKEN_BUDGET, $pack->tokens());

        // Accounts in different currencies are never summed.
        $this->resources[$this->second->id]->forceFill(['metadata' => ['currency' => 'USD', 'timezone_name' => 'Europe/Istanbul', 'account_status' => 1]])->save();
        $stats = collect((new MetaAnalyst(app(MetaFacts::class)))->stats($this->brand))->keyBy('id');
        $this->assertSame([null, 'Hesap bazında'], [$stats['spend_28d']['value'], $stats['spend_28d']['display']]);
    }

    public function test_ai_decisions_are_validated_stored_rendered_and_actions_work(): void
    {
        $this->enableAi();
        $m = $this->main->id;
        $pack = app(MetaAnalyst::class)->buildPack($this->brand);
        $trk = collect(array_keys($pack->sections()['tracking']))->first(fn (string $id): bool => str_starts_with($id, 'trk:'));
        $card = fn (string $key, string $title, string $why, string $type, string $target, int $priority = 2): array => ['key' => $key, 'title_tr' => $title, 'why_tr' => $why,
            'priority' => $priority, 'effort' => 'low', 'impact' => ['estimate' => 'daha ucuz kaliteli lead', 'basis' => 'pack'], 'evidence_refs' => [$target], 'action' => ['type' => $type, 'params' => ['target' => $target]]];
        ChannelAnalystAgent::fake([['decisions' => [
            $card('tracking:'.$trk, 'Pikseli Events Manager’da düzelt', 'Piksel 13 gündür sessiz; dönüşüm kampanyaları kör optimize ediyor.', 'fix_tracking', $trk, 1),
            $card('leads:lq:meta', 'Meta lead sonuçlarını işaretle', 'Meta leadlerinin 2 tanesi hâlâ işaretsiz.', 'mark_lead_outcomes', 'lq:meta', 1),
            $card('region:istanbul', 'İstanbul’u hedeflemeden çıkar', 'İstanbul harcamanın %25’ini alıyor ama hizmet bölgesi dışında.', 'export_plan', 'reg:'.$m.'-istanbul'),
            $card('creative:a1', 'Implant videosu için yeni kreatif hazırla', 'Implant videosunun sıklığı 2,2’ye çıktı ve tıklama oranı düştü.', 'draft_creatives', 'ad:'.$m.'-a1', 3),
            $card('objective:c9', 'Bilinirlik kampanyasını lead hedefine çevir', 'Bilinirlik kampanyası 28 günde 560 harcadı ve hiç lead getirmedi.', 'open_campaign', 'cmp:'.$this->second->id.'-c9', 2),
            $card('invented', 'Uydurma', 'Hesap 98765 lead kaybetti.', 'export_plan', 'reg:'.$m.'-istanbul'),
            $card('write:c1', 'Kampanyayı durdur', 'Kampanya 84 sonuç aldı.', 'pause_campaign', 'cmp:'.$m.'-c1'),
        ]]]);

        $run = app(AnalystEngine::class)->queue($this->brand, 'meta', $this->admin);

        ChannelAnalystAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'INPUT_JSON') && str_contains((string) $prompt->prompt, 'reg:'.$m.'-istanbul'));
        $run = $run->fresh();
        $this->assertSame(AnalystRun::DONE, $run->status, (string) $run->error);
        $this->assertSame([7, 5], [$run->decisions_received, $run->decisions_kept]);
        $this->assertEqualsCanonicalizing(['invented', 'write:c1'], array_column($run->dropped, 'key'), 'invented number and a Meta write action are dropped');

        $this->actingAs($this->admin);
        $page = $this->get(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'meta']))->assertOk()
            ->assertSee('data-workspace-tab="meta"', false)->assertSee('Harcama (28g)')->assertSee('Öğrenmede takılı')
            ->assertSee('Pikseli Events Manager’da düzelt')->assertSee('İstanbul harcamanın %25’ini alıyor ama hizmet bölgesi dışında.')
            ->assertSee('Hizmet bölgesi dışı harcama')->assertDontSee('Uydurma');
        $page->assertSee(e(route('operator.brand.leads', ['brand' => $this->brand->id])), false);
        $page->assertSee(e(route('operator.meta.overview', ['assetId' => $m, 'tab' => 'measurement'])), false);
        $page->assertSee(e(route('operator.meta.overview', ['assetId' => $this->second->id, 'tab' => 'campaigns', 'campaign' => 'c9'])), false);

        // "Kreatif taslağı hazırla" → existing advisor draft flow (item + queued AI draft job).
        Queue::fake();
        $draft = AnalystDecision::query()->where('action_type', 'draft_creatives')->sole();
        Livewire::test(MetaTab::class, ['brandId' => $this->brand->id])->call('runDecisionAction', $draft->id)
            ->assertSet('noticeTone', 'success')->assertSee('taslağı hazırlanıyor');
        Queue::assertPushed(DraftMetaAdsCreativeJob::class);
        $item = AdvisorItem::query()->where('digital_asset_id', $m)->where('rule_id', 'creative-fatigue')->sole();
        $this->assertSame(['a1', 'queued'], [$item->evidence['ad_id'], $item->draft_status]);

        // "Değişiklik planını indir" → CSV of the open Meta cards for Ads Manager.
        $export = AnalystDecision::query()->where('action_type', 'export_plan')->sole();
        Livewire::test(MetaTab::class, ['brandId' => $this->brand->id])->call('runDecisionAction', $export->id)
            ->assertFileDownloaded(MetaPlanExport::filename($this->brand));
        $csv = app(MetaPlanExport::class)->csv(app(MetaAnalyst::class)->planRows($this->brand));
        $this->assertStringContainsString('Yapılacak', $csv);
        $this->assertStringContainsString('İstanbul’u hedeflemeden çıkar', $csv);
        $this->assertStringContainsString('Bölge', $csv);
        $this->assertStringContainsString('c9', $csv);
    }

    public function test_brand_without_meta_account_shows_veri_yok_without_ai_call(): void
    {
        $this->enableAi();
        CoreAssetBinding::query()->update(['status' => 'inactive']);
        ChannelAnalystAgent::fake()->preventStrayPrompts();

        $run = app(AnalystEngine::class)->queue($this->brand, 'meta', $this->admin);

        $this->assertSame([AnalystRun::SKIPPED, 'Veri yok: Meta reklam hesabı bağlı değil.'], [$run->fresh()->status, $run->fresh()->error]);
        ChannelAnalystAgent::assertNeverPrompted();
        Livewire::actingAs($this->admin)->test(MetaTab::class, ['brandId' => $this->brand->id])->assertSee('Veri yok: Meta reklam hesabı bağlı değil.')->assertDontSee('Harcama (28g)');
    }

    private function enableAi(): void
    {
        config(['moxdop.anthropic.api_key' => implode('-', ['sk', 'ant', 'fake', 'meta'])]);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
    }

    private function account(CoreIntegration $integration, string $accountId, string $name): DigitalAsset
    {
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'meta_ads', 'module_id' => 'meta-ads', 'status' => DigitalAssetStatus::Active, 'name' => $name]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => 'meta', 'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_'.$accountId, 'display_name' => $name, 'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['currency' => 'TRY', 'timezone_name' => 'Europe/Istanbul', 'account_status' => 1],
        ]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id,
            'capability' => MetaAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $this->resources[$asset->id] = $resource;

        return $asset;
    }

    private function seedMain(): void
    {
        $a = $this->main;
        $end = now('Europe/Istanbul')->toImmutable()->subDay()->startOfDay();
        $this->row($a, 'meta_campaign_snapshot', ['campaign_id' => 'c1', 'metadata' => json_encode(['name' => 'Lead Kampanya', 'objective' => 'OUTCOME_LEADS', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => '50'])]);
        $this->row($a, 'meta_campaign_snapshot', ['campaign_id' => 'c2', 'metadata' => json_encode(['name' => 'Satış Deneme', 'objective' => 'OUTCOME_SALES', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE'])]);
        $this->row($a, 'meta_adset_snapshot', ['adset_id' => 's1', 'metadata' => json_encode(['name' => 'Lead Geniş', 'campaign_id' => 'c1', 'optimization_goal' => 'LEAD_GENERATION', 'effective_status' => 'ACTIVE'])]);
        $this->row($a, 'meta_adset_snapshot', ['adset_id' => 's2', 'metadata' => json_encode(['name' => 'Satış İlgi', 'campaign_id' => 'c2', 'optimization_goal' => 'OFFSITE_CONVERSIONS', 'effective_status' => 'ACTIVE', 'daily_budget' => '40'])]);
        $this->row($a, 'meta_adset_targeting_snapshot', ['adset_id' => 's2', 'campaign_id' => 'c2', 'adset_name' => 'Satış İlgi', 'optimization_goal' => 'OFFSITE_CONVERSIONS', 'promoted_object' => json_encode(['pixel_id' => '999']), 'targeting' => json_encode(['age_min' => 25])]);
        $this->row($a, 'meta_ad_snapshot', ['ad_id' => 'a1', 'ad_name' => 'Implant videosu', 'campaign_id' => 'c1', 'adset_id' => 's1', 'creative_id' => 'cr1', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
        $this->row($a, 'meta_ad_snapshot', ['ad_id' => 'a2', 'ad_name' => 'Kampanya görseli', 'campaign_id' => 'c2', 'adset_id' => 's2', 'creative_id' => 'cr2', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
        $this->row($a, 'meta_creative_snapshot', ['creative_id' => 'cr1', 'metadata' => json_encode(['title' => 'İmplantta uzman ekip', 'body' => 'Randevunu al.', 'call_to_action_type' => 'LEARN_MORE', 'link_url' => 'https://atlas.test/implant/'])]);
        $this->row($a, 'meta_creative_snapshot', ['creative_id' => 'cr2', 'metadata' => json_encode(['title' => 'Ücretsiz muayene', 'body' => 'Bu ay indirim fırsatı.', 'call_to_action_type' => 'SIGN_UP'])]);
        $this->row($a, 'meta_conversion_source_snapshot', ['source_type' => 'PIXEL', 'source_id' => 'p1', 'source_name' => 'Site pikseli', 'pixel_id' => 'p1', 'last_fired_time' => '2026-09-09 10:00:00', 'is_unavailable' => false]);

        for ($day = 0; $day < 56; $day++) {
            $date = $end->subDays($day)->toDateString();
            $recent = $day < 7;
            $current = $day < 28;
            $this->row($a, 'meta_campaign_daily', ['reporting_date' => $date, 'campaign_id' => 'c1', 'spend' => $current ? 50 : 40, 'impressions' => 5000, 'clicks' => 120, 'reach' => $recent ? 1800 : 3300, 'frequency' => $recent ? 2.8 : 1.5, 'currency' => 'TRY', 'metadata' => json_encode(['inline_link_clicks' => $recent ? 50 : 100])]);
            $this->row($a, 'meta_campaign_daily', ['reporting_date' => $date, 'campaign_id' => 'c2', 'spend' => 40, 'impressions' => 2000, 'clicks' => 30, 'reach' => 1600, 'frequency' => 1.2, 'currency' => 'TRY', 'metadata' => json_encode(['inline_link_clicks' => 20])]);
            $this->row($a, 'meta_ad_daily', ['reporting_date' => $date, 'ad_id' => 'a1', 'spend' => $current ? 50 : 40, 'impressions' => 5000, 'clicks' => 120, 'reach' => $recent ? 2300 : 3300, 'currency' => 'TRY', 'metadata' => json_encode(['inline_link_clicks' => $recent ? 50 : 100, 'frequency' => $recent ? 2.2 : 1.5, 'campaign_id' => 'c1', 'adset_id' => 's1'])]);
            $this->row($a, 'meta_ad_daily', ['reporting_date' => $date, 'ad_id' => 'a2', 'spend' => 40, 'impressions' => 2000, 'clicks' => 30, 'reach' => 1600, 'currency' => 'TRY', 'metadata' => json_encode(['inline_link_clicks' => 20, 'frequency' => 1.2, 'campaign_id' => 'c2', 'adset_id' => 's2'])]);
            $this->row($a, 'meta_typed_action_daily', ['reporting_date' => $date, 'entity_level' => 'ad', 'entity_id' => 'a1', 'action_type' => 'lead', 'action_value' => $current ? 3 : 2, 'currency' => 'TRY']);
            $this->row($a, 'meta_typed_action_daily', ['reporting_date' => $date, 'entity_level' => 'ad', 'entity_id' => 'a2', 'action_type' => 'link_click', 'action_value' => 20, 'currency' => 'TRY']);
            if ($current) {
                foreach ([['Ankara', 30, 2], ['Istanbul', 10, 0]] as [$region, $spend, $leads]) {
                    DB::table('meta_geo_results_daily')->insert(['digital_asset_id' => $a->id, 'account_id' => '11110001', 'reporting_date' => $date, 'level' => 'region', 'ad_id' => 'a1',
                        'country' => 'TR', 'region' => $region, 'spend' => $spend, 'impressions' => 1000, 'clicks' => 20, 'leads' => $leads, 'currency' => 'TRY', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
        $this->row($a, 'meta_analysis_breakdown_daily', ['reporting_date' => $end->toDateString(), 'breakdown_type' => 'placement', 'breakdown_key' => json_encode(['publisher_platform' => 'audience_network', 'platform_position' => 'an_classic']), 'spend' => 400, 'impressions' => 20000, 'clicks' => 20, 'reach' => 9000, 'currency' => 'TRY']);
        $this->row($a, 'meta_analysis_breakdown_daily', ['reporting_date' => $end->toDateString(), 'breakdown_type' => 'placement', 'breakdown_key' => json_encode(['publisher_platform' => 'facebook', 'platform_position' => 'feed']), 'spend' => 2000, 'impressions' => 100000, 'clicks' => 2000, 'reach' => 40000, 'currency' => 'TRY']);

        foreach (['appointment', 'appointment', 'appointment', 'sale', 'junk', 'junk', 'junk', 'junk', 'new', 'new'] as $i => $status) {
            LeadOutcome::factory()->create(['brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'lead_source' => 'meta_lead_form',
                'lead_received_at' => now()->subDays(2 + $i), 'campaign_label' => 'Lead Kampanya', 'status' => $status]);
        }
    }

    private function seedSecond(): void
    {
        $b = $this->second;
        $end = now('Europe/Istanbul')->toImmutable()->subDay()->startOfDay();
        $this->row($b, 'meta_campaign_snapshot', ['campaign_id' => 'c9', 'metadata' => json_encode(['name' => 'Marka Bilinirlik', 'objective' => 'OUTCOME_AWARENESS', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => '20'])]);
        for ($day = 0; $day < 56; $day++) {
            $this->row($b, 'meta_campaign_daily', ['reporting_date' => $end->subDays($day)->toDateString(), 'campaign_id' => 'c9', 'spend' => 20, 'impressions' => 8000, 'clicks' => 40, 'reach' => 6000, 'frequency' => 1.3, 'currency' => 'TRY', 'metadata' => json_encode(['inline_link_clicks' => 30])]);
        }
    }

    /** @param array<string, mixed> $values */
    private function row(DigitalAsset $asset, string $table, array $values): void
    {
        $resource = $this->resources[$asset->id];
        DB::table($table)->insert($values + [
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'account_id' => substr((string) $resource->external_id, 4),
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'Europe/Istanbul',
            'record_fingerprint' => hash('sha256', $asset->id.$table.json_encode($values)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
