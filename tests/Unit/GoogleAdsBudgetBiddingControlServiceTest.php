<?php

namespace Tests\Unit;

use App\Services\GoogleAds\GoogleAdsBudgetBiddingControlService;
use Tests\TestCase;

class GoogleAdsBudgetBiddingControlServiceTest extends TestCase
{
    public function test_it_uses_canonical_account_kpis_and_classifies_budget_decisions(): void
    {
        $workspace = app(GoogleAdsBudgetBiddingControlService::class)->workspace(
            'demo',
            '2026-08-01',
            '2026-08-31',
            [
                [
                    'id' => '1',
                    'name' => 'Efficient constrained',
                    'type' => 'Search',
                    'status' => 'ENABLED',
                    'budget' => 100,
                    'spend' => 300,
                    'leads' => 10,
                    'impr_share' => 60,
                    'lost_is_budget' => 20,
                    'lost_is_rank' => 10,
                    'currency' => 'TRY',
                ],
                [
                    'id' => '2',
                    'name' => 'Expensive campaign',
                    'type' => 'Search',
                    'status' => 'ENABLED',
                    'budget' => 100,
                    'spend' => 400,
                    'leads' => 4,
                    'impr_share' => 80,
                    'lost_is_budget' => 5,
                    'lost_is_rank' => 8,
                    'currency' => 'TRY',
                ],
            ],
            ['currency' => 'TRY', 'budget_bidding' => ['strategies' => []]],
            [
                'identity' => ['currency' => 'TRY', 'reporting_timezone' => 'Europe/Istanbul'],
                'glance' => [
                    'spend' => ['raw' => 1000],
                    'conversions' => ['raw' => 20],
                ],
                'performance_trend' => ['labels' => [], 'spend' => [], 'leads' => []],
            ],
        );

        $this->assertSame(1000.0, $workspace['summary']['spend']);
        $this->assertSame(20.0, $workspace['summary']['provider_conversions']);
        $this->assertSame(50.0, $workspace['summary']['provider_cpa']);
        $this->assertSame('account_provider_cpa', $workspace['summary']['benchmark_source']);

        $byId = collect($workspace['campaigns'])->keyBy('id');
        $this->assertSame('scale', $byId['1']['decision_code']);
        $this->assertSame('reduce', $byId['2']['decision_code']);
        $this->assertFalse($workspace['pacing']['available']);
        $this->assertFalse($workspace['scenario']['available']);
    }
}
