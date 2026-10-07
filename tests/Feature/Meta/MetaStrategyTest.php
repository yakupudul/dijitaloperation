<?php

namespace Tests\Feature\Meta;

use App\Ai\Agents\MetaStrategyPlanAgent;
use App\Jobs\Meta\DraftMetaStrategyPlanJob;
use App\Livewire\Operator\Meta\StrategyPage;
use App\Models\AiTask;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\Ads\AdServiceStats;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Meta\MetaCampaignServices;
use App\Services\Meta\MetaStrategy;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\SeedsMetaAccount;
use Tests\TestCase;

/**
 * Strateji öner: winners of other brands for one service, city and result type (threshold, cheapest first), the
 * rule-built recipe, library saves and the plan draft that lands in the brand's Meta Yapılacaklar.
 */
class MetaStrategyTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMetaAccount;

    private BrandOffering $implant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Http::preventStrayRequests();
        config(['moxdop.anthropic.api_key' => 'test-anthropic-value', 'moxdop-mcp.token' => '']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $this->seedMetaAccount();
        $services = app(MetaCampaignServices::class);
        $services->sync($this->asset->load('brand'));
        $id = collect($services->offerings($this->brand))->firstWhere('name', 'Diş İmplantı')['id'];
        $this->implant = BrandOffering::query()->findOrFail($id);
        app(AdServiceStats::class)->refresh($this->asset);
    }

    /**
     * Other brands' implant campaigns in Ankara: three winners (form 30, 40, 80), one under the spend threshold, one in
     * USD and one message campaign.
     */
    private function otherBrands(): void
    {
        $rows = [
            ['Atlas', 3000, 100, 'leads', 'TRY', true, 'Atlas implant'],
            ['Beta', 4000, 100, 'leads', 'TRY', false, 'Beta implant'],
            ['Gama', 8000, 100, 'leads', 'TRY', true, 'Gama implant'],
            ['Delta', 1500, 100, 'leads', 'TRY', true, 'Delta küçük'],
            ['Epsilon', 9000, 300, 'leads', 'USD', true, 'Epsilon dolar'],
            ['Zeta', 5000, 200, 'messages', 'TRY', true, 'Zeta mesaj'],
        ];
        foreach ($rows as $i => [$name, $spend, $results, $type, $currency, $video, $campaign]) {
            $brand = Brand::factory()->create(['customer_id' => $this->brand->customer_id, 'name' => $name, 'sector_id' => $this->brand->sector_id]);
            $brand->serviceAreas()->create(['name' => 'Şube', 'country_code' => 'TR', 'city_name' => 'Ankara', 'normalized_key' => 'tr-ankara-'.$i, 'status' => 'active', 'physical_branch' => true]);
            $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'meta_ads', 'name' => $name.' Meta']);
            $profile = ['objective' => 'Potansiyel müşteri', 'optimization' => ['LEAD_GENERATION'], 'destination' => 'form', 'budget' => 100.0 + $i * 10, 'budget_level' => 'campaign',
                'adsets' => 1, 'ads' => 2, 'video_ads' => $video ? 1 : 0,
                'targeting' => ['locations' => ['Ankara +15 km'], 'age' => '30–65+', 'genders' => 'Tümü', 'interests' => ['Diş hekimliği', $name.' ilgisi'], 'audiences' => 0, 'excluded' => 0,
                    'placements' => 'Advantage+ yerleşim', 'advantage' => false],
                'best_ad' => ['title' => $name.' implant başlığı', 'body' => $name.' kliniğinde implant muayenesi için randevu alın.', 'video' => $video, 'form' => true, 'link_path' => '', 'results' => 50, 'cpr' => 30.0]];
            DB::table('ad_campaign_stats')->insert(['channel' => 'meta', 'digital_asset_id' => $asset->id, 'brand_id' => $brand->id, 'campaign_id' => 'x'.$i, 'name' => $campaign,
                'status' => 'live', 'result_type' => $type, 'spend' => $spend, 'results' => $results, 'cpr' => round($spend / $results, 2), 'prev_cpr' => null,
                'service_state' => 'confirmed', 'services' => json_encode([['id' => 999, 'name' => 'İmplant', 'status' => 'confirmed', 'service_id' => $this->implant->service_catalog_item_id]]),
                'alerts' => '[]', 'currency' => $currency, 'period_end' => '2026-10-29', 'profile' => json_encode($profile, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_refresh_keeps_each_campaigns_recipe(): void
    {
        $profile = json_decode((string) DB::table('ad_campaign_stats')->where('campaign_id', 'c1')->value('profile'), true);

        $this->assertSame(['LEAD_GENERATION'], $profile['optimization']);
        $this->assertSame(150.0, (float) $profile['budget']);
        $this->assertSame(['Ankara'], $profile['targeting']['locations']);
        $this->assertSame('Diş İmplantı Ankara', $profile['best_ad']['title']);
        $this->assertSame(1, $profile['ads']);
    }

    public function test_winners_pass_the_threshold_cheapest_first_and_build_the_recipe(): void
    {
        $this->otherBrands();
        $view = app(MetaStrategy::class)->strategy(['brand' => (string) $this->brand->id]);

        $this->assertSame((int) $this->implant->service_catalog_item_id, $view['service_id'], 'the brand’s main service by default');
        $this->assertSame(['Ankara', 'Ankara'], [$view['city'], $view['scope']]);
        $this->assertSame(['Atlas implant', 'Beta implant', 'Gama implant'], array_column($view['winners'], 'name'), 'under 2.000 TL, USD and message campaigns are left out');
        $this->assertSame(1, $view['other_currency']);
        $this->assertSame([40.0, 3, 110.0], [$view['recipe']['cpr'], $view['recipe']['count'], $view['recipe']['budget']]);
        $lines = array_column($view['recipe']['lines'], 'value', 'label');
        $this->assertSame('Form (3/3)', $lines['Optimizasyon']);
        $this->assertSame('Anında form (3/3)', $lines['Sonuç yeri']);
        $this->assertSame('Diş hekimliği', $lines['Ortak ilgi alanları']);
        $this->assertStringStartsWith('video (2/3)', $lines['En iyi reklam']);
        $this->assertSame(['c1'], array_column($view['own'], 'campaign_id'));
        $this->assertSame(17.2, $view['own'][0]['diff'], 'own 46,88 against the recipe 40');

        $messages = app(MetaStrategy::class)->strategy(['brand' => (string) $this->brand->id, 'type' => 'messages']);
        $this->assertSame(['Zeta mesaj'], array_column($messages['winners'], 'name'));
        $this->assertSame('tüm şehirler', $messages['scope'], 'one winner in the city is not enough for a city recipe');
    }

    public function test_page_shows_recipe_winners_and_saves_to_the_library(): void
    {
        $this->otherBrands();
        $this->actingAs($this->admin)->get(route('operator.meta-strategy', ['marka' => $this->brand->id]))->assertOk()
            ->assertSee('Strateji öner')->assertSee('Kazanan tarifi')->assertSee('Atlas implant')->assertSee('Atlas implant başlığı')->assertSee('Panorama Ankara için plan taslağı iste');
        $this->actingAs($this->admin)->get(route('operator.meta-desk'))->assertSee(route('operator.meta-strategy'));

        $atlas = (int) DB::table('ad_campaign_stats')->where('campaign_id', 'x0')->value('id');
        $page = Livewire::actingAs($this->admin)->test(StrategyPage::class, ['brand' => (string) $this->brand->id])
            ->call('save', $atlas, 'text')->assertSee('Reklam metni kütüphaneye kaydedildi.')->assertSee('Metin kütüphanede')
            ->call('save', $atlas, 'text')->assertSee('Zaten kütüphanede.')
            ->call('save', $atlas, 'targeting');
        $items = DB::table('ad_library_items')->orderBy('id')->get();
        $this->assertSame(['text', 'targeting'], $items->pluck('kind')->all());
        $this->assertSame('Atlas implant başlığı', $items[0]->title);
        $this->assertSame(['Ankara +15 km'], json_decode($items[1]->payload, true)['targeting']['locations']);

        $page->set('type', 'purchases')->assertSee('eşiği geçen kampanya yok')->set('type', 'bogus')->assertSet('type', 'leads');
    }

    public function test_plan_request_queues_a_draft_that_lands_in_yapilacaklar(): void
    {
        $this->otherBrands();
        Queue::fake();
        Livewire::actingAs($this->admin)->test(StrategyPage::class, ['brand' => (string) $this->brand->id])->call('requestPlan')->assertSee('Plan taslağı hazırlanıyor');
        Queue::assertPushed(DraftMetaStrategyPlanJob::class, fn (DraftMetaStrategyPlanJob $job): bool => $job->assetId === $this->asset->id && $job->type === 'leads');

        MetaStrategyPlanAgent::fake([[
            'summary' => 'Ankara’da implant için anında formlu tek kampanya.', 'campaign_name' => 'İmplant · Ankara · Form',
            'structure' => 'Potansiyel müşteri hedefi, form optimizasyonu, 1 set, 3 reklam.',
            'adsets' => [['name' => 'Ankara 30+', 'audience' => 'Ankara +15 km, 30–65+, Advantage+ yerleşim']],
            'ads' => [['angle' => 'Süreç', 'headline' => 'İmplant muayenesi', 'primary_text' => 'Muayene için randevu alın.']],
            'watch' => ['İlk 3 günde form kalitesine bakın.'],
        ]]);
        $serviceId = (int) $this->implant->service_catalog_item_id;
        (new DraftMetaStrategyPlanJob($this->brand->id, $this->asset->id, $serviceId, 'leads', 'Ankara'))->handle(app(MetaStrategy::class), app(AiTaskQueue::class));

        $this->assertSame('ready', Cache::get(MetaStrategy::planStateKey($this->brand->id, $serviceId, 'leads'))['status']);
        $plan = Suggestion::query()->where('decision_key', 'like', 'meta:'.$this->asset->id.':plan:%')->sole();
        $this->assertSame('Strateji planı · Diş İmplantı · form', $plan->title);
        $this->assertStringContainsString('Günlük bütçe: 110 TRY (kazananların ortancası)', $plan->action['text']);
        $this->assertStringContainsString('Beklenen form başı maliyet: yaklaşık 40 TRY', $plan->action['text']);
        $this->assertStringContainsString('1) Süreç · İmplant muayenesi', $plan->action['text']);
        $this->assertSame(Suggestion::OPEN, $plan->status);
        MetaStrategyPlanAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Atlas implant başlığı') && ! str_contains($prompt->prompt, 'Atlas implant"'));
    }

    public function test_with_claude_the_plan_waits_in_the_queue_and_without_winners_it_fails(): void
    {
        $this->otherBrands();
        config(['moxdop-mcp.token' => 'test-mcp-token']);
        MetaStrategyPlanAgent::fake()->preventStrayPrompts();
        $serviceId = (int) $this->implant->service_catalog_item_id;

        (new DraftMetaStrategyPlanJob($this->brand->id, $this->asset->id, $serviceId, 'leads', 'Ankara'))->handle(app(MetaStrategy::class), app(AiTaskQueue::class));
        $this->assertSame('running', Cache::get(MetaStrategy::planStateKey($this->brand->id, $serviceId, 'leads'))['status']);
        $this->assertSame(1, AiTask::query()->where('operation', 'meta.strategy_plan')->count());

        (new DraftMetaStrategyPlanJob($this->brand->id, $this->asset->id, $serviceId, 'purchases', 'Ankara'))->handle(app(MetaStrategy::class), app(AiTaskQueue::class));
        $this->assertSame('failed', Cache::get(MetaStrategy::planStateKey($this->brand->id, $serviceId, 'purchases'))['status']);
    }
}
