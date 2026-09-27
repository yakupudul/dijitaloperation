<?php

namespace Tests\Feature\Work;

use App\Contracts\Collection\ActivityTierReader;
use App\Jobs\DraftGoogleAdsAdCopyJob;
use App\Livewire\Operator\Work\AdvisorInboxPage;
use App\Livewire\Operator\Work\CommandCenterPage;
use App\Livewire\Operator\Work\SeoInboxPage;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\SeoPlan;
use App\Models\SeoTask;
use App\Models\User;
use App\Services\CommandCenter\CommandCenter;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Komuta merkezi topic → assets inbox: grouping, bulk actions, alert aging, paused-account suppression, pre-filters. */
final class CommandCenterInboxTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $adsA;

    private DigitalAsset $adsB;

    private DigitalAsset $meta;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas Diş']);
        $this->adsA = $this->asset('google_ads', 'Atlas Ads Kadıköy');
        $this->adsB = $this->asset('google_ads', 'Atlas Ads Şişli');
        $this->meta = $this->asset('meta_ads', 'Atlas Meta');
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => 'active', 'domain' => 'atlas.test', 'name' => 'atlas.test', 'module_id' => 'website']);
    }

    public function test_same_problem_on_several_accounts_is_one_topic_listing_every_asset(): void
    {
        foreach ([$this->adsA, $this->adsB, $this->meta] as $asset) {
            $this->alert($asset, 'budget_exhausted', 'critical', ($asset->type === 'meta_ads' ? 'Meta Ads' : 'Google Ads').' bütçesi bitti');
        }
        $this->alert($this->site, 'site_down', 'critical', 'Site erişilemiyor');

        $items = app(CommandCenter::class)->items();
        $budget = $items->where('topic', 'alert:budget_exhausted');
        $this->assertCount(3, $budget);
        $this->assertSame(['ads'], $budget->pluck('area')->unique()->values()->all());
        $this->assertSame('site', $items->firstWhere('topic', 'alert:site_down')['area']);

        $page = Livewire::test(CommandCenterPage::class)->call('selectTopic', 'alert:budget_exhausted')
            ->assertSee('Bütçe / bakiye bitti')->assertSee('Atlas Ads Kadıköy')->assertSee('Atlas Ads Şişli')->assertSee('Atlas Meta')
            ->assertSee('Acil');
        $nav = collect($page->viewData('nav')['urgent']);
        $this->assertSame(1, $nav->where('topic', 'alert:budget_exhausted')->count(), 'one row per topic, not per account');
        $this->assertSame(3, $nav->firstWhere('topic', 'alert:budget_exhausted')['assets']);
        $this->assertSame('Bütçe / bakiye bitti', $page->viewData('selected')['label']);
    }

    public function test_bulk_snooze_and_done_act_on_a_whole_topic(): void
    {
        $plan = fn (DigitalAsset $asset): AdvisorPlan => AdvisorPlan::query()->create(['channel' => 'google_ads', 'brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $asset->id, 'status' => 'completed', 'completed_at' => now()]);
        $first = $this->advisor($plan($this->adsA), $this->adsA, 'negative-keywords', 'Boşa harcanan terimler A');
        $second = $this->advisor($plan($this->adsB), $this->adsB, 'negative-keywords', 'Boşa harcanan terimler B');
        $alerts = [$this->alert($this->adsA, 'budget_low', 'high', 'Google Ads bütçesi bitmek üzere'), $this->alert($this->adsB, 'budget_low', 'high', 'Google Ads bütçesi bitmek üzere')];

        Livewire::test(CommandCenterPage::class)
            ->call('selectTopic', 'advisor:negative-keywords')->call('selectAll')
            ->assertSet('bulkIds', ['advisor:'.$first->id, 'advisor:'.$second->id])
            ->call('act', 'snooze', null, 3)->assertSet('bulkIds', []);

        foreach ([$first, $second] as $item) {
            $this->assertSame('skipped', $item->fresh()->status->value);
            $this->assertTrue($item->fresh()->snoozed_until->between(now()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()));
        }

        Livewire::test(CommandCenterPage::class)->call('selectTopic', 'alert:budget_low')->call('selectAll')->call('act', 'done');
        foreach ($alerts as $alert) {
            $this->assertNotNull($alert->fresh()->resolved_at);
        }
        $this->assertCount(0, app(CommandCenter::class)->items()->whereIn('source', ['advisor', 'alert']));
    }

    public function test_a_condition_open_for_eleven_days_moves_to_long_running_but_critical_ones_stay(): void
    {
        $budget = $this->alert($this->adsA, 'budget_exhausted', 'critical', 'Google Ads bütçesi bitti', now()->subDays(11));
        $down = $this->alert($this->site, 'site_down', 'critical', 'Site erişilemiyor', now()->subDays(11));
        $fresh = $this->alert($this->adsB, 'budget_exhausted', 'critical', 'Google Ads bütçesi bitti', now()->subDays(2));

        $items = app(CommandCenter::class)->items()->keyBy('key');
        $this->assertTrue($items['alert:'.$budget->id]['aged']);
        $this->assertFalse($items['alert:'.$down->id]['aged'], 'site down never ages out');
        $this->assertFalse($items['alert:'.$fresh->id]['aged']);

        $center = app(CommandCenter::class);
        $this->assertSame($center->items()->count() - 1, $center->summary()['total'], 'aged items leave the badge');
        $this->assertNotContains('alert:'.$budget->id, array_column($center->top(), 'key'), 'and the dashboard');

        $page = Livewire::test(CommandCenterPage::class)->assertSee('Uzun süredir devam eden');
        $aged = collect($page->viewData('nav')['aged']);
        $this->assertSame(['aged:alert:budget_exhausted'], $aged->pluck('key')->all());
        $this->assertSame(1, collect($page->viewData('nav')['urgent'])->firstWhere('topic', 'alert:budget_exhausted')['assets']);
        $page->call('selectTopic', 'aged:alert:budget_exhausted')->assertSee('Atlas Ads Kadıköy')->assertSet('showAged', true);

        config(['moxdop-command-center.aging.days' => 20]);
        $this->assertFalse(app(CommandCenter::class)->items()->firstWhere('key', 'alert:'.$budget->id)['aged'], 'threshold is configurable');
    }

    public function test_a_changed_or_returning_condition_resurfaces_with_a_fresh_age(): void
    {
        $alert = $this->alert($this->adsA, 'budget_low', 'high', 'Google Ads bütçesi bitmek üzere', now()->subDays(12));
        $other = $this->alert($this->adsB, 'budget_low', 'high', 'Google Ads bütçesi bitmek üzere', now()->subDays(12));
        $this->assertTrue($this->find('alert:'.$alert->id)['aged']);

        // The same alert gets worse: severity changes → fingerprint changes → back on top with a fresh age.
        $alert->forceFill(['severity' => 'critical'])->save();
        $item = $this->find('alert:'.$alert->id);
        $this->assertFalse($item['aged']);
        $this->assertTrue($item['since']->gte(now()->subMinute()));

        // It disappears (resolved) and comes back later: fresh again, even though the source row keeps its old date.
        $this->assertTrue($this->find('alert:'.$other->id)['aged']);
        $other->forceFill(['resolved_at' => now()])->save();
        $this->assertNull($this->find('alert:'.$other->id));
        $this->assertNotNull(DB::table('inbox_item_states')->where('item_key', 'alert:'.$other->id)->value('gone_at'));
        $this->travel(1)->days();
        $other->forceFill(['resolved_at' => null])->save();
        $this->assertFalse($this->find('alert:'.$other->id)['aged']);

        // Unchanged, it ages again after the threshold.
        $this->travel(11)->days();
        $this->assertTrue($this->find('alert:'.$other->id)['aged']);
    }

    public function test_budget_items_of_dormant_or_paused_accounts_are_hidden_behind_a_note(): void
    {
        $reader = new class implements ActivityTierReader
        {
            /** @var list<int> */
            public array $paused = [];

            /** @var array<int, string> */
            public array $tiers = [];

            public function forAsset(int $digitalAssetId): ?array
            {
                return isset($this->tiers[$digitalAssetId]) || in_array($digitalAssetId, $this->paused, true)
                    ? ['tier' => $this->tiers[$digitalAssetId] ?? 'idle', 'last_active_on' => null, 'operator_paused' => in_array($digitalAssetId, $this->paused, true)]
                    : null;
            }

            public function pause(int $digitalAssetId, User $by): bool
            {
                $this->paused[] = $digitalAssetId;

                return true;
            }
        };
        $reader->tiers = [$this->adsA->id => 'dormant', $this->adsB->id => 'active'];
        $this->app->instance(ActivityTierReader::class, $reader);

        $dormant = $this->alert($this->adsA, 'budget_exhausted', 'critical', 'Google Ads bütçesi bitti');
        $stale = $this->alert($this->adsA, 'stale_data', 'medium', 'Veri güncel değil');
        $active = $this->alert($this->adsB, 'budget_exhausted', 'critical', 'Google Ads bütçesi bitti');
        $meta = $this->alert($this->meta, 'delivery_stopped', 'critical', 'Meta Ads reklamları harcama yapmadı');

        $center = app(CommandCenter::class);
        $keys = $center->items()->pluck('key')->all();
        $this->assertNotContains('alert:'.$dormant->id, $keys, 'dormant account: budget alert hidden');
        $this->assertContains('alert:'.$stale->id, $keys, 'non-budget topics stay');
        $this->assertContains('alert:'.$active->id, $keys);
        $this->assertContains('alert:'.$meta->id, $keys);
        $this->assertSame(['alert:'.$dormant->id], $center->suppressed()->pluck('key')->all());

        // "Hesap duraklatıldı (müşteri kararı)" on the Meta item records the pause; its budget item leaves the inbox.
        Livewire::test(CommandCenterPage::class)
            ->assertSee('Duraklatılmış hesaplar')
            ->call('selectTopic', 'alert:delivery_stopped')->call('openItem', 'alert:'.$meta->id)
            ->assertSee('Hesap duraklatıldı (müşteri kararı)')
            ->call('pauseAccount', 'alert:'.$meta->id)
            ->assertSee('duraklatıldı olarak işaretlendi');
        $this->assertSame([$this->meta->id], $reader->paused);
        $this->assertNotContains('alert:'.$meta->id, app(CommandCenter::class)->items()->pluck('key')->all());
        $this->assertSame(2, app(CommandCenter::class)->suppressed()->count());
    }

    public function test_without_an_activity_reader_nothing_is_suppressed_and_pause_says_so(): void
    {
        $alert = $this->alert($this->adsA, 'budget_exhausted', 'critical', 'Google Ads bütçesi bitti');
        $this->assertContains('alert:'.$alert->id, app(CommandCenter::class)->items()->pluck('key')->all());

        Livewire::test(CommandCenterPage::class)->call('pauseAccount', 'alert:'.$alert->id)->assertSee('Duraklatma henüz kaydedilemiyor');
        $this->assertContains('alert:'.$alert->id, app(CommandCenter::class)->items()->pluck('key')->all());
    }

    public function test_danisman_and_seo_menu_routes_open_the_inbox_pre_filtered_and_old_screens_stay_reachable(): void
    {
        $plan = AdvisorPlan::query()->create(['channel' => 'google_ads', 'brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->adsA->id, 'status' => 'completed', 'completed_at' => now()]);
        $this->advisor($plan, $this->adsA, 'negative-keywords', 'Boşa harcanan terimler');
        $seoPlan = SeoPlan::query()->create(['brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->site->id, 'status' => 'completed', 'completed_at' => now()]);
        SeoTask::query()->create([
            'customer_id' => $this->brand->customer_id, 'brand_id' => $this->brand->id, 'digital_asset_id' => $this->site->id,
            'task_key' => hash('sha256', 'map'), 'type' => 'fix', 'rule_id' => 'service-page-mapping', 'severity' => 'high',
            'priority_score' => 500, 'title' => 'İmplant hizmetinin sayfası yok', 'reason' => 'r', 'evidence' => [], 'checklist' => [], 'status' => 'open',
            'first_seen_plan_id' => $seoPlan->id, 'last_seen_plan_id' => $seoPlan->id,
        ]);

        $this->get(route('operator.ads_advisor'))->assertOk()->assertSee('Danışman')->assertSee('Negatif kelime önerisi')->assertDontSee('Sayfası olmayan hizmet')
            ->assertSee(route('operator.ads_advisor.detailed', absolute: false), false);
        $this->get(route('operator.seo_tasks'))->assertOk()->assertSee('SEO Görevleri')->assertSee('Sayfası olmayan hizmet')->assertDontSee('Negatif kelime önerisi')
            ->assertSee(route('operator.seo_tasks.detailed', absolute: false), false);
        Livewire::test(AdvisorInboxPage::class)->assertSet('area', 'ads');
        Livewire::test(SeoInboxPage::class)->assertSet('area', 'seo')->call('setArea', '')->assertSee('Negatif kelime önerisi');

        $this->get(route('operator.ads_advisor.detailed'))->assertOk()->assertSee('Tüm hesapları incele');
        $this->get(route('operator.seo_tasks.detailed'))->assertOk()->assertSee('Tüm planları yenile');
    }

    public function test_editor_export_still_downloads_from_the_danisman_inbox(): void
    {
        Http::fake();
        $plan = AdvisorPlan::query()->create(['channel' => 'google_ads', 'brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->adsA->id, 'status' => 'completed', 'completed_at' => now()]);
        $negatives = $this->advisor($plan, $this->adsA, 'negative-keywords', 'Negatif liste', ['terms' => [['term' => 'ücretsiz', 'campaigns' => ['Arama']]]]);
        $manual = $this->advisor($plan, $this->adsA, 'ad-strength', 'Reklam gücü zayıf', ['ad_group' => 'x']);

        Livewire::test(AdvisorInboxPage::class)
            ->set('bulkIds', ['advisor:'.$negatives->id, 'advisor:'.$manual->id])
            ->assertSee('Google Ads Editor dosyası indir')
            ->call('exportEditor')
            ->assertFileDownloaded()
            ->assertSee('Elle yapılacak (1)')
            ->assertSee('Reklam gücü zayıf');

        Http::assertNothingSent();
        $this->assertNotNull($negatives->fresh()->exported_at);
        $this->assertNull($manual->fresh()->exported_at);
        $this->assertSame('open', $negatives->fresh()->status->value);

        Livewire::test(CommandCenterPage::class)->set('bulkIds', ['advisor:'.$negatives->id])->assertDontSee('Google Ads Editor dosyası indir');
    }

    public function test_ai_draft_button_queues_the_same_draft_as_the_danisman_panel(): void
    {
        Queue::fake();
        $plan = AdvisorPlan::query()->create(['channel' => 'google_ads', 'brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->adsA->id, 'status' => 'completed', 'completed_at' => now()]);
        $weak = $this->advisor($plan, $this->adsA, 'weak-ad-strength', 'Zayıf reklam gücü');

        Livewire::test(AdvisorInboxPage::class)
            ->call('openItem', 'advisor:'.$weak->id)->assertSee('AI metin taslağı hazırla')
            ->call('requestDraft', 'advisor:'.$weak->id)->assertSee('taslağı hazırlanıyor');
        Queue::assertPushed(DraftGoogleAdsAdCopyJob::class);
        $this->assertSame('queued', $weak->fresh()->draft_status);
    }

    public function test_asset_query_parameter_shows_only_that_assets_work(): void
    {
        $this->alert($this->adsA, 'budget_exhausted', 'critical', 'Google Ads bütçesi bitti');
        $this->alert($this->adsB, 'budget_exhausted', 'critical', 'Google Ads bütçesi bitti');
        $this->alert($this->site, 'site_down', 'critical', 'Site erişilemiyor');

        $page = Livewire::withQueryParams(['asset' => $this->adsA->id])->test(CommandCenterPage::class)
            ->assertSet('asset', $this->adsA->id)
            ->assertSee('Varlık: Atlas Ads Kadıköy')->assertSee('Atlas Ads Kadıköy')->assertDontSee('Atlas Ads Şişli')->assertDontSee('Site erişilemiyor');
        $this->assertSame(1, $page->viewData('selected')['count']);

        $page->call('clearFilter', 'asset')->assertSee('Atlas Ads Şişli');
        $this->get(route('operator.command-center', ['asset' => $this->adsB->id]))->assertOk()->assertSee('Atlas Ads Şişli')->assertDontSee('Atlas Ads Kadıköy');
    }

    public function test_drawer_shows_details_and_links_to_the_asset(): void
    {
        $alert = $this->alert($this->adsA, 'budget_exhausted', 'critical', 'Google Ads bütçesi bitti');

        Livewire::test(CommandCenterPage::class)
            ->call('openItem', 'alert:'.$alert->id)
            ->assertSet('openKey', 'alert:'.$alert->id)
            ->assertSee('Hesap limiti doldu.')
            ->assertSee(route('operator.google-ads.overview', ['assetId' => $this->adsA->id]), false)
            ->call('closeItem')->assertSet('openKey', null)
            ->call('moveTopic', 1);
    }

    private function find(string $key): ?array
    {
        return app(CommandCenter::class)->items()->firstWhere('key', $key);
    }

    private function asset(string $type, string $name): DigitalAsset
    {
        return DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => $type, 'status' => 'active', 'name' => $name, 'module_id' => $type]);
    }

    private function alert(DigitalAsset $asset, string $kind, string $severity, string $title, mixed $since = null): AssetAlert
    {
        return AssetAlert::query()->create([
            'digital_asset_id' => $asset->id, 'brand_id' => $asset->brand_id, 'alert_key' => hash('sha256', $kind), 'kind' => $kind,
            'severity' => $severity, 'title' => $title, 'message' => 'Hesap limiti doldu.', 'first_detected_at' => $since ?? now(), 'last_detected_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $evidence */
    private function advisor(AdvisorPlan $plan, DigitalAsset $asset, string $rule, string $title, array $evidence = []): AdvisorItem
    {
        return AdvisorItem::query()->create([
            'channel' => 'google_ads', 'customer_id' => $this->brand->customer_id, 'brand_id' => $this->brand->id, 'digital_asset_id' => $asset->id,
            'item_key' => hash('sha256', $title.$asset->id), 'category' => 'waste', 'rule_id' => $rule, 'severity' => 'high', 'priority_score' => 700, 'impact_amount' => 300,
            'title' => $title, 'reason' => 'r', 'evidence' => $evidence, 'checklist' => [], 'status' => 'open', 'currency' => 'TRY',
            'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id,
        ]);
    }
}
