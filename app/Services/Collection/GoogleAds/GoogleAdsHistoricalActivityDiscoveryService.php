<?php

namespace App\Services\Collection\GoogleAds;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsClientFactory;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsHistoricalActivityGaqlBuilder;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsProviderErrorMapper;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Low-cost lifetime activity probe used before detailed Google Ads backfill.
 *
 * It intentionally requests one account row per month. The returned activity map
 * lets the planner avoid scanning years of empty daily/search-term data while still
 * finding an account whose last ads ran one or two (or many) years ago.
 */
final class GoogleAdsHistoricalActivityDiscoveryService
{
    public function __construct(
        private readonly GoogleAdsClientFactory $client,
        private readonly GoogleAdsHistoricalActivityGaqlBuilder $gaql,
        private readonly GoogleAdsProviderErrorMapper $errors,
    ) {}

    /** @return array<string,mixed> */
    public function discover(CoreExternalResource $resource): array
    {
        $resource->loadMissing('integration');
        $integration = $resource->integration;
        if (! $integration instanceof CoreIntegration) {
            throw new RuntimeException('Google Ads historical activity discovery requires an active Google integration.');
        }

        $metadata = is_array($resource->metadata) ? $resource->metadata : [];
        $customerId = preg_replace('/\D+/', '', (string) $resource->external_id) ?? '';
        if ($customerId === '') {
            throw new RuntimeException('Google Ads historical activity discovery requires a customer ID.');
        }

        $loginCustomerId = preg_replace('/\D+/', '', (string) (
            $metadata['login_customer_id']
            ?? $metadata['manager_customer_id']
            ?? $customerId
        )) ?? $customerId;
        if ($loginCustomerId === '') {
            $loginCustomerId = $customerId;
        }

        $timezone = $metadata['time_zone'] ?? $metadata['timezone'] ?? 'UTC';
        $timezone = is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
        $currency = isset($metadata['currency_code']) && is_string($metadata['currency_code'])
            ? strtoupper($metadata['currency_code'])
            : 'XXX';

        return $this->discoverAccount(
            $integration,
            $customerId,
            $loginCustomerId,
            $timezone,
            $currency,
            (int) $resource->id,
        );
    }

    /**
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   has_activity:bool,
     *   active_months:int,
     *   first_activity_month:?string,
     *   last_activity_month:?string,
     *   granular_start:?string,
     *   granular_end:?string,
     *   older_history_exists:bool,
     *   discovery_start:string,
     *   discovery_end:string,
     *   granular_boundary:string
     * }
     */
    public function discoverAccount(
        CoreIntegration $integration,
        string $customerId,
        string $loginCustomerId,
        string $timezone,
        string $currency,
        int $externalResourceId,
    ): array {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $end = $today->subDay();
        $configuredStart = (string) config('moxdop-google-ads-history.discovery_start_date', '2000-01-01');

        try {
            $start = CarbonImmutable::createFromFormat('Y-m-d', $configuredStart, $timezone)->startOfDay();
        } catch (\Throwable) {
            $start = CarbonImmutable::create(2000, 1, 1, 0, 0, 0, $timezone);
        }

        if ($start->greaterThan($end)) {
            $start = $end;
        }

        $query = $this->gaql->monthly($start->toDateString(), $end->toDateString());
        $response = $this->client->searchStream($integration, $customerId, $query, $loginCustomerId);
        if (! $response->successful()) {
            $mapped = $this->errors->fromHttpResponse($response);
            throw new RuntimeException($mapped->errorMessage ?? 'Google Ads historical activity discovery failed.');
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Google Ads historical activity discovery returned a non-JSON response.');
        }

        $providerRows = [];
        foreach (array_is_list($json) ? $json : [$json] as $chunk) {
            if (! is_array($chunk)) {
                continue;
            }
            foreach ($chunk['results'] ?? [] as $row) {
                if (is_array($row)) {
                    $providerRows[] = $row;
                }
            }
        }

        $rows = [];
        foreach ($providerRows as $row) {
            $month = data_get($row, 'segments.month');
            if (! is_string($month) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $month) !== 1) {
                continue;
            }

            $impressions = $this->integer(data_get($row, 'metrics.impressions'));
            $clicks = $this->integer(data_get($row, 'metrics.clicks'));
            $costMicros = $this->integer(data_get($row, 'metrics.costMicros'));
            $conversions = $this->decimal(data_get($row, 'metrics.conversions'));
            $conversionValue = $this->decimal(data_get($row, 'metrics.conversionsValue'));
            $active = $impressions > 0
                || $clicks > 0
                || $costMicros > 0
                || (float) $conversions > 0
                || (float) $conversionValue > 0;

            $rows[] = [
                'digital_asset_id' => null,
                'external_resource_id' => $externalResourceId,
                'customer_id' => $customerId,
                'reporting_month' => $month,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'cost_micros' => $costMicros,
                'cost_amount' => $this->microsToAmount($costMicros),
                'conversions' => $conversions,
                'conversions_value' => $conversionValue,
                'currency' => $currency !== '' ? substr($currency, 0, 3) : 'XXX',
                'activity_detected' => $active,
                'source_timezone' => $timezone,
                'metadata' => [
                    'granularity' => 'MONTH',
                    'history_role' => 'lifetime_activity_discovery',
                ],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['reporting_month'], (string) $b['reporting_month']));
        $activeRows = array_values(array_filter($rows, static fn (array $row): bool => (bool) $row['activity_detected']));
        $firstMonth = $activeRows !== [] ? (string) $activeRows[0]['reporting_month'] : null;
        $lastMonth = $activeRows !== [] ? (string) $activeRows[array_key_last($activeRows)]['reporting_month'] : null;

        $lookbackMonths = max(1, (int) config('moxdop-google-ads-history.granular_lookback_months', 37));
        // Add one day so the first daily GAQL date is never on the ambiguous edge
        // of the provider's rolling granular lookback boundary.
        $granularBoundary = $today->subMonthsNoOverflow($lookbackMonths)->addDay();
        $granularStart = null;
        $granularEnd = null;
        $olderHistoryExists = false;

        if ($firstMonth !== null && $lastMonth !== null) {
            $first = CarbonImmutable::createFromFormat('Y-m-d', $firstMonth, $timezone)->startOfDay();
            $last = CarbonImmutable::createFromFormat('Y-m-d', $lastMonth, $timezone)->endOfMonth()->startOfDay();
            if ($last->greaterThan($end)) {
                $last = $end;
            }

            $olderHistoryExists = $first->lessThan($granularBoundary);
            $candidateStart = $first->greaterThan($granularBoundary) ? $first : $granularBoundary;
            if (! $last->lessThan($candidateStart)) {
                $granularStart = $candidateStart->toDateString();
                $granularEnd = $last->toDateString();
            }
        }

        return [
            'rows' => $rows,
            'has_activity' => $activeRows !== [],
            'active_months' => count($activeRows),
            'first_activity_month' => $firstMonth,
            'last_activity_month' => $lastMonth,
            'granular_start' => $granularStart,
            'granular_end' => $granularEnd,
            'older_history_exists' => $olderHistoryExists,
            'discovery_start' => $start->toDateString(),
            'discovery_end' => $end->toDateString(),
            'granular_boundary' => $granularBoundary->toDateString(),
        ];
    }

    private function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function decimal(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '0.000000';
        }

        return number_format((float) $value, 6, '.', '');
    }

    private function microsToAmount(int $micros): string
    {
        $negative = $micros < 0;
        $absolute = abs($micros);
        $whole = intdiv($absolute, 1_000_000);
        $fraction = $absolute % 1_000_000;

        return ($negative ? '-' : '').$whole.'.'.str_pad((string) $fraction, 6, '0', STR_PAD_LEFT);
    }
}
