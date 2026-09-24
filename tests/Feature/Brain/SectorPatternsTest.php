<?php

namespace Tests\Feature\Brain;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Settings\SectorPatternsPage;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Brain\RuleEffectiveness;
use App\Services\Brain\SectorPatternReader;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 7f / ADR-066: sector patterns are aggregates over ≥ 2 active brands of the same sector.
 */
final class SectorPatternsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Brand> */
    private array $brands = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        foreach (['a' => ['health', CustomerStatus::Active], 'b' => ['health', CustomerStatus::Active], 'c' => ['health', CustomerStatus::Active], 'passive' => ['health', CustomerStatus::Inactive], 'solo' => ['construction', CustomerStatus::Active]] as $key => [$sector, $status]) {
            $this->brands[$key] = Brand::factory()->create(['name' => 'Marka '.strtoupper($key), 'sector' => $sector, 'customer_id' => Customer::factory()->create(['status' => $status])->id]);
        }
    }

    public function test_sectors_queries_gaps_and_problems_are_aggregates(): void
    {
        $this->demand('a', 'implant fiyatları', 900);
        $this->demand('b', 'implant fiyatları', 300);
        $this->demand('passive', 'implant fiyatları', 5000);
        $this->demand('a', 'zirkonyum kaplama', 100);
        $this->demand('c', 'diş beyazlatma', 50);
        $this->demand('a', 'marka a klinik', 50, true);
        $this->demand('b', 'marka a klinik', 50, true);
        $this->item('a', 'negative-keywords', 'open');
        $this->item('b', 'negative-keywords', 'open');
        $this->item('a', 'budget-waste', 'open');

        $reader = app(SectorPatternReader::class);
        $this->assertSame([['code' => 'health', 'label' => $reader->sectors()[0]['label'], 'brands' => 3]], $reader->sectors(), 'passive customer and single-brand sector are left out');

        $health = $reader->forSector('health', $this->brands['c']->id);
        $this->assertSame(['implant fiyatları'], array_column($health['queries'], 'query'), 'one-brand and branded queries are not patterns');
        $this->assertSame(1200, $health['queries'][0]['impressions'], 'passive brand not counted');
        $this->assertSame(['implant fiyatları'], array_column($health['gaps'], 'query'));
        $this->assertSame([], $reader->forSector('health', $this->brands['a']->id)['gaps'], 'only one other brand has it: not a pattern for A');
        $this->assertSame([['rule_id' => 'negative-keywords', 'channel' => 'google_ads', 'brands' => 2, 'open' => 2]], $health['problems']);
        $this->assertFalse($reader->forSector('construction')['available']);

        config(['moxdop-advisor.sector_patterns.min_brands' => 1]);
        $this->assertSame(2, SectorPatternReader::minBrands(), 'never below two brands');

        Livewire::test(SectorPatternsPage::class)
            ->assertSee('implant fiyatları')
            ->assertSee('negative-keywords')
            ->assertDontSee('zirkonyum kaplama')
            ->set('brand', $this->brands['c']->id)
            ->assertSee('Sektörde sık, bu markada yok (1)');
        $this->get(route('operator.settings.sector-patterns'))->assertOk();
    }

    public function test_rule_weight_uses_the_sector_once_it_has_enough_outcomes(): void
    {
        foreach (range(1, 5) as $i) {
            $this->item('a', 'landing-page-issues', 'done', true);
            $this->item('solo', 'landing-page-issues', 'done', false);
        }
        $effect = app(RuleEffectiveness::class);

        $this->assertSame(1.0, $effect->weight('landing-page-issues'), 'agency-wide: half improved');
        $this->assertSame(1.2, $effect->weight('landing-page-issues', 'health'));
        $this->assertSame(0.8, $effect->weight('landing-page-issues', 'construction'));
        $this->assertSame(1.0, $effect->weight('landing-page-issues', 'retail'), 'no sector data → agency-wide');
        $this->assertSame(480.0, $effect->reweigh([['rule_id' => 'landing-page-issues', 'priority_score' => 400]], 'health')[0]['priority_score']);

        $rules = app(SectorPatternReader::class)->forSector('health')['rules'];
        $this->assertSame(['landing-page-issues', 100, 50], [$rules[0]['rule_id'], $rules[0]['rate'], $rules[0]['global_rate']]);
    }

    private function demand(string $brand, string $text, int $impressions, bool $branded = false): void
    {
        DB::table('brand_demand_queries')->insert([
            'brand_id' => $this->brands[$brand]->id, 'query' => $text, 'query_key' => hash('sha256', $text), 'is_branded' => $branded,
            'gsc_impressions' => $impressions, 'gsc_clicks' => (int) ($impressions / 10), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function item(string $brand, string $rule, string $status, ?bool $improved = null): void
    {
        $model = $this->brands[$brand];
        $asset = DigitalAsset::factory()->create(['brand_id' => $model->id, 'type' => 'google_ads']);
        $plan = AdvisorPlan::query()->create(['channel' => 'google_ads', 'brand_id' => $model->id, 'customer_id' => $model->customer_id, 'digital_asset_id' => $asset->id, 'status' => 'completed', 'completed_at' => now()]);
        AdvisorItem::query()->create([
            'channel' => 'google_ads', 'customer_id' => $model->customer_id, 'brand_id' => $model->id, 'digital_asset_id' => $asset->id,
            'item_key' => uniqid('k', true), 'category' => 'waste', 'rule_id' => $rule, 'severity' => 'medium', 'priority_score' => 400,
            'title' => 't', 'reason' => 'r', 'evidence' => [], 'checklist' => [], 'status' => $status, 'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id,
            'outcome' => $improved === null ? null : ['status' => 'measured', 'before' => 100, 'after' => $improved ? 40 : 120, 'good_direction' => 'down'],
        ]);
    }
}
