<?php

namespace Tests\Feature\MetaAds;

use App\Services\MetaAds\MetaAdsProfessionalWorkspaceReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/** Meta-reported leads, purchase value / ROAS and average daily reach on the Meta overview. */
final class MetaOutcomesTest extends TestCase
{
    use RefreshDatabase;

    public function test_leads_purchase_value_and_reach_are_summarised_without_double_counting(): void
    {
        $provenance = ['contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        $action = fn (string $type, float $count, ?float $amount, string $date = '2026-09-02'): array => $provenance + [
            'digital_asset_id' => 1, 'external_resource_id' => 2, 'account_id' => 'act_9', 'reporting_date' => $date, 'entity_level' => 'ad',
            'entity_id' => 'ad-1', 'action_type' => $type, 'action_value' => $count, 'currency' => 'TRY',
            'record_fingerprint' => md5($type.$date).md5($date.$type), 'metadata' => json_encode($amount === null ? [] : ['action_value_amount' => $amount]),
        ];
        DB::table('meta_typed_action_daily')->insert([
            $action('lead', 8, null),
            $action('onsite_conversion.lead_grouped', 8, null),
            $action('omni_purchase', 2, 1500),
            $action('purchase', 2, 1500),
            $action('link_click', 50, null),
        ]);
        foreach (['2026-09-01' => [1000, '1.200000'], '2026-09-02' => [2000, '1.800000']] as $date => [$reach, $frequency]) {
            DB::table('meta_account_daily')->insert($provenance + [
                'digital_asset_id' => 1, 'external_resource_id' => 2, 'account_id' => 'act_9', 'reporting_date' => $date, 'spend' => '250.000000',
                'impressions' => 100, 'clicks' => 5, 'reach' => $reach, 'frequency' => $frequency, 'currency' => 'TRY', 'record_fingerprint' => md5($date).md5($date),
            ]);
        }

        $service = app(MetaAdsProfessionalWorkspaceReadService::class);
        $outcomes = (new ReflectionMethod($service, 'outcomes'))->invoke($service, 1, 2, 'act_9', '2026-09-01', '2026-09-30', 500.0, 'TRY');

        $this->assertSame(8.0, $outcomes['leads']);
        $this->assertSame(62.5, $outcomes['cpl']);
        $this->assertSame(2.0, $outcomes['purchases']);
        $this->assertSame(1500.0, $outcomes['purchase_value']);
        $this->assertSame(3.0, $outcomes['roas']);
        $this->assertSame(1500.0, $outcomes['avg_daily_reach']);
        $this->assertSame(1.5, $outcomes['avg_frequency']);
    }
}
