<?php

namespace App\Support\Performance;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Synthetic-only benchmark fixtures. No real PII, credentials, or provider tokens.
 *
 * @phpstan-import-type Profile from BenchmarkProfiles
 */
final class SyntheticBenchmarkSeeder
{
    /**
     * @param  Profile  $profile
     * @return array{
     *     customers: list<Customer>,
     *     brands: list<Brand>,
     *     assets: list<DigitalAsset>,
     *     gsc_rows_inserted: int,
     *     ads_rows_inserted: int,
     *     primary_asset: DigitalAsset|null
     * }
     */
    public function seed(array $profile, int $seed = 65): array
    {
        mt_srand($seed);

        $customers = [];
        $brands = [];
        $assets = [];

        for ($c = 0; $c < (int) $profile['customers']; $c++) {
            $customer = Customer::factory()->create([
                'name' => sprintf('Bench Customer %03d', $c + 1),
                'primary_email' => sprintf('bench-customer-%03d@example.test', $c + 1),
            ]);
            $customers[] = $customer;

            for ($b = 0; $b < (int) $profile['brands_per_customer']; $b++) {
                $brand = Brand::factory()->create([
                    'customer_id' => $customer->id,
                    'name' => sprintf('Bench Brand %03d-%02d', $c + 1, $b + 1),
                ]);
                $brands[] = $brand;

                for ($a = 0; $a < (int) $profile['assets_per_brand']; $a++) {
                    $asset = DigitalAsset::factory()->create([
                        'brand_id' => $brand->id,
                        'name' => sprintf('Bench Asset %03d-%02d-%02d', $c + 1, $b + 1, $a + 1),
                        'type' => 'website',
                    ]);
                    $assets[] = $asset;
                }
            }
        }

        $primary = $assets[0] ?? null;
        $gscInserted = 0;
        $adsInserted = 0;

        if ($primary !== null && (int) $profile['gsc_rows'] > 0) {
            $gscInserted = $this->seedGscQueryDaily($primary, (int) $profile['gsc_rows'], (int) $profile['gsc_days']);
        }
        if ($primary !== null && (int) $profile['ads_rows'] > 0) {
            $adsInserted = $this->seedAdsSearchTermDaily($primary, (int) $profile['ads_rows'], (int) $profile['ads_days']);
        }

        return [
            'customers' => $customers,
            'brands' => $brands,
            'assets' => $assets,
            'gsc_rows_inserted' => $gscInserted,
            'ads_rows_inserted' => $adsInserted,
            'primary_asset' => $primary,
        ];
    }

    private function seedGscQueryDaily(DigitalAsset $asset, int $rows, int $days): int
    {
        if (! $this->tableExists('gsc_query_daily') || $rows <= 0) {
            return 0;
        }

        $days = max(1, $days);
        $now = Carbon::now('UTC');
        $batch = [];
        $inserted = 0;
        $siteUrl = 'https://bench.example.test/';

        for ($i = 0; $i < $rows; $i++) {
            $dayOffset = $i % $days;
            $date = $now->copy()->subDays($dayOffset)->toDateString();
            $batch[] = [
                'digital_asset_id' => $asset->id,
                'external_resource_id' => 1,
                'site_url' => $siteUrl,
                'reporting_date' => $date,
                'query' => 'bench query '.$i,
                'clicks' => ($i % 17) + 1,
                'impressions' => (($i % 17) + 1) * 10,
                'contract_version' => 1,
                'last_collection_run_id' => null,
                'last_dataset_run_id' => null,
                'first_collected_at' => $now,
                'last_collected_at' => $now,
                'source_timezone' => 'UTC',
                'record_fingerprint' => hash('sha256', 'gsc-'.$asset->id.'-'.$i),
                'metadata' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 500) {
                DB::table('gsc_query_daily')->insert($batch);
                $inserted += count($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            DB::table('gsc_query_daily')->insert($batch);
            $inserted += count($batch);
        }

        return $inserted;
    }

    private function seedAdsSearchTermDaily(DigitalAsset $asset, int $rows, int $days): int
    {
        if (! $this->tableExists('google_ads_search_term_daily') || $rows <= 0) {
            return 0;
        }

        $days = max(1, $days);
        $now = Carbon::now('UTC');
        $batch = [];
        $inserted = 0;
        $customerId = 'bench-ads-customer';

        for ($i = 0; $i < $rows; $i++) {
            $dayOffset = $i % $days;
            $date = $now->copy()->subDays($dayOffset)->toDateString();
            $batch[] = [
                'digital_asset_id' => $asset->id,
                'external_resource_id' => 1,
                'customer_id' => $customerId,
                'reporting_date' => $date,
                'search_term' => 'bench term '.$i,
                'impressions' => (($i % 23) + 1) * 5,
                'clicks' => ($i % 23) + 1,
                'cost_micros' => (($i % 23) + 1) * 1_000_000,
                'conversions' => $i % 5,
                'cost_amount' => (float) (($i % 23) + 1),
                'currency' => 'USD',
                'contract_version' => 1,
                'last_collection_run_id' => null,
                'last_dataset_run_id' => null,
                'first_collected_at' => $now,
                'last_collected_at' => $now,
                'source_timezone' => 'America/New_York',
                'record_fingerprint' => hash('sha256', 'ads-'.$asset->id.'-'.$i),
                'metadata' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 500) {
                DB::table('google_ads_search_term_daily')->insert($batch);
                $inserted += count($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            DB::table('google_ads_search_term_daily')->insert($batch);
            $inserted += count($batch);
        }

        return $inserted;
    }

    private function tableExists(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
