<?php

namespace Tests\Feature\Analyst;

use App\Ai\Agents\Analyst\ChannelAnalystAgent;
use App\Enums\CustomerStatus;
use App\Jobs\DraftGbpPostJob;
use App\Jobs\DraftReviewReplyJob;
use App\Livewire\Operator\Workspace\MapsTab;
use App\Models\AiProduction;
use App\Models\AnalystDecision;
use App\Models\AnalystRun;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Intel\MapGridPoint;
use App\Models\Intel\MapGridRun;
use App\Models\Run;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\WebsiteUrlAudit;
use App\Services\Analyst\AnalystEngine;
use App\Services\Analyst\AnalystPack;
use App\Services\Analyst\AnalystRegistry;
use App\Services\Analyst\Maps\MapsAnalyst;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Brand workspace › Harita: Business Profile pack, AI decisions, ADR-073 draft actions and the tab. */
final class MapsAnalystTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $gbp;

    private DigitalAsset $site;

    private CoreExternalResource $resource;

    private BrandOffering $implant;

    private MapGridRun $grid;

    private int $runId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Atlas Ağız ve Diş']);
        $this->brand->sectors()->attach(ServiceCategory::query()->where('code', 'dental')->value('id'));
        $offerings = app(BrandOfferingService::class);
        $this->implant = $offerings->create($this->brand, 'İmplant');
        $offerings->create($this->brand, 'Diş Beyazlatma');
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => 'active', 'module_id' => 'website',
            'name' => 'atlas.test', 'domain' => 'atlas.test', 'primary_url' => 'https://atlas.test/']);
        $this->gbp = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'name' => 'Atlas Çankaya']);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $this->resource = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => 'google_business_profile',
            'external_id' => 'locations/22', 'parent_external_id' => 'accounts/11', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        $binding = CoreAssetBinding::factory()->create(['digital_asset_id' => $this->gbp->id, 'external_resource_id' => $this->resource->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $this->runId = (int) Run::query()->create(['digital_asset_id' => $this->gbp->id, 'core_asset_binding_id' => $binding->id, 'module_id' => 'google-business-profile', 'status' => 'completed',
            'started_at' => now(), 'finished_at' => now(), 'metadata' => []])->id;
        $row = fn (array $data): array => $data + ['digital_asset_id' => $this->gbp->id, 'external_resource_id' => $this->resource->id, 'run_id' => $this->runId,
            'location_name' => 'locations/22', 'created_at' => now(), 'updated_at' => now()];

        DB::table('gbp_location_snapshots')->insert($row(['title' => 'Atlas Ağız ve Diş', 'place_id' => 'place-atlas', 'primary_category' => 'Diş kliniği', 'website_uri' => 'https://atlas.test/',
            'phone_numbers' => json_encode(['primaryPhone' => '0312 000 00 00']), 'regular_hours' => json_encode(['periods' => [['openDay' => 'MONDAY'], ['openDay' => 'TUESDAY']]]),
            'storefront_address' => json_encode(['locality' => 'Çankaya', 'administrativeArea' => 'Ankara']),
            'profile' => json_encode(['description' => 'Kısa açıklama']), 'average_rating' => 4.6, 'total_review_count' => 40, 'captured_at' => now()]));
        DB::table('gbp_service_snapshots')->insert($row(['service_items' => json_encode([['freeFormServiceItem' => ['label' => ['displayName' => 'Diş beyazlatma']]]]), 'captured_at' => now()]));

        // Performance: last 28 days maps 100/day, before 80/day (+%25); actions 6/day vs 5/day (+%20).
        for ($day = 1; $day <= 56; $day++) {
            $current = $day <= 28;
            foreach (['BUSINESS_IMPRESSIONS_MOBILE_MAPS' => $current ? 100 : 80, 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH' => 50, 'CALL_CLICKS' => $current ? 2 : 1,
                'BUSINESS_DIRECTION_REQUESTS' => 3, 'WEBSITE_CLICKS' => 1] as $metric => $value) {
                DB::table('gbp_performance_daily')->insert($row(['reporting_date' => now('UTC')->subDays($day)->toDateString(), 'metric' => $metric, 'value' => $value, 'collected_at' => now()]));
            }
        }
        foreach (['implant fiyatları' => 400, 'diş beyazlatma' => 200, 'diş hekimi' => 150, 'atlas diş' => 900] as $keyword => $impressions) {
            DB::table('gbp_search_keywords_monthly')->insert($row(['month_start' => now()->startOfMonth()->subMonth()->toDateString(), 'search_keyword' => $keyword,
                'search_keyword_hash' => hash('sha256', $keyword), 'impressions' => $impressions, 'collected_at' => now()]));
        }
        foreach ([['r-new', 'FIVE', 'Çok memnun kaldım', 5, null], ['r-bad', 'ONE', 'Çok bekledim', 20, null],
            ['r-replied', 'FOUR', 'İyi', 100, ['comment' => 'Teşekkürler', 'updateTime' => now()->subDays(100)->addHours(10)->toIso8601String()]]] as [$id, $stars, $comment, $days, $reply]) {
            DB::table('gbp_reviews')->insert($row(['review_id' => $id, 'star_rating' => $stars, 'comment' => $comment, 'create_time' => now()->subDays($days), 'update_time' => now()->subDays($days),
                'reviewer' => json_encode(['displayName' => 'Müşteri']), 'review_reply' => $reply !== null ? json_encode($reply) : null, 'raw_payload' => '{}', 'collected_at' => now()]));
        }
        DB::table('gbp_posts')->insert($row(['post_name' => 'localPosts/1', 'summary' => 'Diş beyazlatma hakkında', 'state' => 'LIVE', 'create_time' => now()->subDays(40), 'raw_payload' => '{}', 'collected_at' => now()]));
        DB::table('gbp_media')->insert($row(['media_name' => 'media/1', 'media_format' => 'PHOTO', 'category' => 'COVER', 'create_time' => now()->subDays(200), 'raw_payload' => '{}', 'collected_at' => now()]));

        // Map grid: "implant" — we are in the top 3 at 1 of 3 cells; a competitor leads everywhere.
        $this->grid = MapGridRun::query()->create(['brand_id' => $this->brand->id, 'keyword' => 'implant', 'center_lat' => 39.9, 'center_lng' => 32.8, 'grid_size' => 3, 'spacing_km' => 1,
            'status' => MapGridRun::STATUS_COMPLETED, 'points_total' => 3, 'points_done' => 3, 'arp' => 4.5, 'atrp' => 9.3, 'solv' => 33.33, 'started_at' => now(), 'completed_at' => now()]);
        foreach ([[0, 2], [1, 6], [2, null]] as [$col, $ours]) {
            $results = [['rank' => 1, 'title' => 'Rakip Diş', 'cid' => '1111', 'ours' => false]];
            if ($ours !== null) {
                $results[] = ['rank' => $ours, 'title' => 'Atlas Ağız ve Diş', 'cid' => '9000', 'ours' => true];
            }
            MapGridPoint::query()->create(['map_grid_run_id' => $this->grid->id, 'row' => 0, 'col' => $col, 'lat' => 39.9, 'lng' => 32.8, 'status' => 'done', 'our_rank' => $ours, 'results' => $results]);
        }
        WebsiteUrlAudit::query()->create(['digital_asset_id' => $this->site->id, 'brand_id' => $this->brand->id, 'status' => 'completed', 'computed_at' => now(),
            'site_checks' => ['website:url:gbp_nap_consistency' => ['state' => 'fail', 'finding' => 'Telefon farklı (Profil: 3120000000; site: 3121112233).']]]);
    }

    public function test_maps_pack_and_durum_numbers_come_from_stored_data(): void
    {
        $pack = app(MapsAnalyst::class)->buildPack($this->brand);

        $this->assertTrue($pack->hasData());
        $stats = collect($pack->stats)->keyBy('id');
        $this->assertSame([2800, 25], [$stats['maps_views']['value'], $stats['maps_views']['delta_pct']]);
        $this->assertSame([168, 20, '56 / 84 / 28'], [$stats['actions']['value'], $stats['actions']['delta_pct'], $stats['actions']['note']]);
        $this->assertSame(['4,6', '40 yorum'], [$stats['rating']['display'], $stats['rating']['note']], 'Google’s own rating and count');
        $this->assertSame(2, $stats['unanswered_reviews']['value']);
        $this->assertSame(['%33', 33], [$stats['grid_top3']['display'], $stats['grid_top3']['value']]);
        $this->assertGreaterThan(0, $stats['profile_standards']['evaluated']);
        $this->assertSame($stats['profile_standards']['passed'].'/'.$stats['profile_standards']['evaluated'], $stats['profile_standards']['display']);

        $loc = $pack->fact('loc:'.$this->gbp->id);
        $this->assertSame(['Atlas Ağız ve Diş', 'Çankaya, Ankara', 40, 2, 1, 2, 10, 40], [$loc['name'], $loc['area'], $loc['reviews'], $loc['unanswered'], $loc['reviews_30d'] - 1, $loc['reviews_90d'], $loc['median_reply_hours'], $loc['last_post_days']]);
        $this->assertSame(200, $loc['last_photo_days']);

        $implantKw = $pack->fact(MapsAnalyst::keywordRef($this->gbp->id, 'implant fiyatları'));
        $this->assertSame(['İmplant', 'profilde yok', 400], [$implantKw['service'], $implantKw['status'], $implantKw['impressions']]);
        $this->assertSame('profilde var', $pack->fact(MapsAnalyst::keywordRef($this->gbp->id, 'diş beyazlatma'))['status']);
        $this->assertSame('markada hizmet yok', $pack->fact(MapsAnalyst::keywordRef($this->gbp->id, 'diş hekimi'))['status']);
        $this->assertFalse($pack->has(MapsAnalyst::keywordRef($this->gbp->id, 'atlas diş')), 'branded searches are left out');
        $this->assertSame(['profilde yok', 400], [$pack->fact('svc:'.$this->implant->id)['status'], $pack->fact('svc:'.$this->implant->id)['impressions']]);

        $services = $pack->fact('std:'.$this->gbp->id.':gbp:services_complete');
        $this->assertNotNull($services, 'failing standard with the value to add');
        $this->assertStringContainsString('Ekle: İmplant', (string) $services['display']);
        $this->assertTrue($pack->has('std:'.$this->gbp->id.':gbp:nap_matches_site'));
        $this->assertTrue($pack->has('rev:'.$this->reviewId('r-bad')));
        $this->assertFalse($pack->has('rev:'.$this->reviewId('r-replied')));
        $this->assertSame(['implant', 33.3, 4.5], [$pack->fact('grid:'.$this->grid->id)['text'], $pack->fact('grid:'.$this->grid->id)['top3_pct'], $pack->fact('grid:'.$this->grid->id)['position']]);
        $this->assertSame(['Rakip Diş', 3], [$pack->fact('comp:'.$this->grid->id.':1')['name'], $pack->fact('comp:'.$this->grid->id.':1')['top3_cells']]);
        $this->assertSame('fail', $pack->fact('nap:'.$this->site->id)['status']);
        $this->assertNotSame([], array_filter(array_keys($pack->facts()), fn (string $id): bool => str_starts_with($id, 'adv:'.$this->gbp->id.':')), 'GBP advisor rules are candidate facts');
        $this->assertLessThan(AnalystPack::DEFAULT_TOKEN_BUDGET, $pack->tokens());
    }

    public function test_ai_decisions_are_validated_stored_rendered_and_actions_work(): void
    {
        $this->enableAi();
        $loc = 'loc:'.$this->gbp->id;
        $kw = MapsAnalyst::keywordRef($this->gbp->id, 'implant fiyatları');
        $std = 'std:'.$this->gbp->id.':gbp:services_complete';
        $grid = 'grid:'.$this->grid->id;
        $nap = 'nap:'.$this->site->id;
        $card = fn (string $key, string $title, string $why, string $type, string $target, array $refs = []): array => ['key' => $key, 'title_tr' => $title, 'why_tr' => $why,
            'priority' => 1, 'effort' => 'low', 'impact' => ['estimate' => '+arama', 'basis' => 'görüntüleme'], 'evidence_refs' => $refs ?: [$target], 'action' => ['type' => $type, 'params' => ['target' => $target]]];
        ChannelAnalystAgent::fake([['decisions' => [
            $card('reply:'.$loc, 'Yanıtsız yorumları yanıtla', '2 yorum yanıt bekliyor.', 'reply_reviews', $loc),
            $card('post:'.$kw, 'İmplant gönderisi hazırla', 'Son gönderi 40 gün önce, implant araması 400 gösterim aldı.', 'prepare_post', $kw, [$kw, $loc]),
            $card('service:'.$std, 'İmplantı hizmet olarak ekle', 'İmplant aramaları 400 gösterim alıyor ama profilde hizmet yok.', 'add_service', $std, [$std, $kw]),
            $card('grid:'.$grid, 'İmplant grid açığını kapat', 'İmplant için ilk-3 payı %33.', 'open_grid', $grid),
            $card('nap:'.$nap, 'Sitedeki telefonu profil ile eşle', 'Site ile profilde telefon farklı; profil 40 yorumlu.', 'fix_nap', $nap),
            $card('bad-number', 'Uydurma', 'Harita görüntüleme 99999 oldu.', 'upload_photos', $loc),
            $card('qa', 'Soru-cevap bölümünü doldur', '2 yorum yanıt bekliyor.', 'fix_profile_field', $loc),
            $card('name', 'İşletme adına implant kelimesini ekle', 'İmplant araması 400 gösterim alıyor.', 'fix_profile_field', $loc),
        ]]]);

        $run = app(AnalystEngine::class)->queue($this->brand, 'maps', $this->admin);

        $run->refresh();
        $this->assertSame(AnalystRun::DONE, $run->status, (string) $run->error);
        $this->assertSame([8, 5], [$run->decisions_received, $run->decisions_kept]);
        $this->assertEqualsCanonicalizing(['bad-number', 'qa', 'name'], array_column($run->dropped, 'key'));
        $this->assertSame(1, AiProduction::query()->where('kind', AiRouteKeys::ANALYST_MAPS)->count());
        ChannelAnalystAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, $kw));

        $page = $this->actingAs($this->admin)->get(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'harita']))->assertOk()
            ->assertSee('data-workspace-tab="maps"', false)->assertDontSee('data-workspace-pending', false)
            ->assertSee('Harita görüntüleme (28g)')->assertSee('Yanıtsız yorumları yanıtla')->assertSee('2 yorum yanıt bekliyor.')->assertDontSee('Uydurma');
        $page->assertSee(e(route('operator.gbp', ['assetId' => $this->gbp->id, 'tab' => 'profile'])), false);
        $page->assertSee(e(route('operator.market.map-rankings', ['brand' => $this->brand->id, 'run' => $this->grid->id])), false);
        $page->assertSee(e(route('operator.website', ['assetId' => $this->site->id, 'tab' => 'scorecard'])), false);
        foreach (AnalystDecision::query()->get() as $decision) {
            $this->assertLessThanOrEqual(160, mb_strlen($decision->why));
        }
        $this->get(route('operator.gbp', ['assetId' => $this->gbp->id, 'tab' => 'profile']))->assertOk();
        $this->get(route('operator.market.map-rankings', ['brand' => $this->brand->id, 'run' => $this->grid->id]))->assertOk();

        // ADR-073 flows: reply drafts for the unanswered reviews, an AI post draft on the searched service.
        Queue::fake();
        $reply = AnalystDecision::query()->where('action_type', 'reply_reviews')->sole();
        Livewire::actingAs($this->admin)->test(MapsTab::class, ['brandId' => $this->brand->id])
            ->call('runDecisionAction', $reply->id)->assertSet('noticeTone', 'success')->assertSee('2 yorum için yanıt taslağı hazırlanıyor');
        Queue::assertPushed(DraftReviewReplyJob::class, 2);
        Queue::assertPushed(DraftReviewReplyJob::class, fn (DraftReviewReplyJob $job): bool => $job->reviewId === $this->reviewId('r-bad'));

        $post = AnalystDecision::query()->where('action_type', 'prepare_post')->sole();
        Livewire::actingAs($this->admin)->test(MapsTab::class, ['brandId' => $this->brand->id])
            ->call('runDecisionAction', $post->id)->assertSet('noticeTone', 'success')->assertSee('Gönderi taslağı hazırlanıyor');
        Queue::assertPushed(DraftGbpPostJob::class, fn (DraftGbpPostJob $job): bool => $job->assetId === $this->gbp->id && $job->topic === 'implant fiyatları');

        // A link card has nothing to run; done stores the baseline.
        $link = AnalystDecision::query()->where('action_type', 'open_grid')->sole();
        Livewire::actingAs($this->admin)->test(MapsTab::class, ['brandId' => $this->brand->id])
            ->call('runDecisionAction', $link->id)->assertSet('noticeTone', 'error')
            ->call('markDecisionDone', $link->id);
        $this->assertSame(2800, $link->fresh()->baseline['metric']['maps_views_28d']);
    }

    public function test_brand_without_business_profile_gets_one_line_and_no_ai_call(): void
    {
        $this->enableAi();
        CoreAssetBinding::query()->update(['status' => CoreAssetBinding::STATUS_DISABLED]);
        ChannelAnalystAgent::fake()->preventStrayPrompts();

        $run = app(AnalystEngine::class)->queue($this->brand, 'maps', $this->admin);

        $this->assertSame([AnalystRun::SKIPPED, 'Veri yok: İşletme Profili bağlı değil.'], [$run->fresh()->status, $run->fresh()->error]);
        ChannelAnalystAgent::assertNeverPrompted();
        Livewire::actingAs($this->admin)->test(MapsTab::class, ['brandId' => $this->brand->id])->assertSee('Veri yok: İşletme Profili bağlı değil.')->assertDontSee('Harita görüntüleme');

        CoreAssetBinding::query()->update(['status' => CoreAssetBinding::STATUS_ACTIVE]);
        DB::table('gbp_location_snapshots')->delete();
        $this->assertSame('Veri yok: İşletme Profili verisi henüz toplanmadı.', app(MapsAnalyst::class)->buildPack($this->brand)->missing);
    }

    public function test_maps_channel_is_live_with_its_route_and_tab(): void
    {
        $this->assertContains('maps', app(AnalystRegistry::class)->liveChannels());
        $this->assertTrue(app(AiRouteRegistry::class)->has(AiRouteKeys::ANALYST_MAPS));
        $this->actingAs($this->admin)->get(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'harita']))->assertOk()
            ->assertSee('data-workspace-tab="maps"', false)->assertDontSee('Hazırlanıyor');
    }

    private function reviewId(string $googleId): int
    {
        return (int) DB::table('gbp_reviews')->where('review_id', $googleId)->value('id');
    }

    private function enableAi(): void
    {
        config(['moxdop.anthropic.api_key' => implode('-', ['sk', 'ant', 'fake', 'maps'])]);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
    }
}
