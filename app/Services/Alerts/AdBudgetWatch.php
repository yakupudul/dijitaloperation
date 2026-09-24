<?php

namespace App\Services\Alerts;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Services\Integrations\Meta\MetaApiClient;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Services\Operator\AssetRuntimeStatusReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Ads budget watch (read-only). Every two hours asks Google Ads / Meta for the account's billing and delivery state:
 * account status, spend limit and what is left of it, prepaid balance (Meta), today's spend in the account time zone,
 * campaigns whose daily budget is already used up, and (Meta) disapproved ads. The result is stored in
 * ad_budget_status and the asset's alerts are rescanned at once, so "budget ran out" reaches the phone the same hour.
 */
final class AdBudgetWatch
{
    /** Meta account_status values that stop delivery. */
    private const array META_BLOCKING_STATUS = [
        2 => 'Hesap devre dışı',
        3 => 'Ödenmemiş bakiye var',
        7 => 'Hesap risk incelemesinde',
        8 => 'Ödeme bekleniyor',
        9 => 'Ödeme için ek süre (grace period)',
        100 => 'Hesap kapanıyor',
        101 => 'Hesap kapalı',
    ];

    /** Currencies Meta reports without a minor unit. */
    private const array ZERO_DECIMAL = ['JPY', 'KRW', 'CLP', 'COP', 'CRC', 'HUF', 'ISK', 'IDR', 'PYG', 'TWD', 'VND'];

    public function __construct(
        private readonly GoogleAdsSpecialistBindingResolver $adsBindings,
        private readonly MetaAdsSpecialistBindingResolver $metaBindings,
        private readonly GoogleApiClient $google,
        private readonly MetaApiClient $meta,
    ) {}

    /** @return list<int> operational ad assets with an active Google Ads or Meta binding */
    public function eligibleAssetIds(): array
    {
        return DigitalAsset::query()->operational()->whereIn('type', ['google_ads', 'meta_ads'])
            ->whereIn('id', CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->select('digital_asset_id'))
            ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** Checks one asset, stores the state and rescans its alerts. */
    public function check(DigitalAsset $asset): void
    {
        $provider = (string) $asset->type === 'meta_ads' ? 'meta' : 'google_ads';
        $data = null;
        $error = null;
        try {
            $data = $provider === 'meta' ? $this->metaState($asset) : $this->googleState($asset);
        } catch (Throwable $exception) {
            $error = mb_substr($exception->getMessage(), 0, 300);
        }
        if ($data === null && $error === null) {
            return; // not bound to a real account
        }
        $existing = DB::table('ad_budget_status')->where('digital_asset_id', $asset->id)->first();
        $values = ['provider' => $provider, 'error' => $error, 'checked_at' => now(), 'updated_at' => now()];
        if ($data !== null) {
            $values['data'] = json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        if ($existing === null) {
            DB::table('ad_budget_status')->insert($values + ['digital_asset_id' => $asset->id, 'created_at' => now()]);
        } else {
            DB::table('ad_budget_status')->where('id', $existing->id)->update($values);
        }
        $runtime = app(AssetRuntimeStatusReader::class)->forAssets(collect([$asset]));
        app(AssetAlertScanner::class)->scan($asset, $runtime[(int) $asset->id] ?? []);
    }

    /** @return array<string, mixed>|null */
    private function googleState(DigitalAsset $asset): ?array
    {
        $binding = $this->adsBindings->resolve((string) $asset->id);
        if (! $binding->isReal()) {
            return null;
        }
        $resource = CoreExternalResource::query()->with('integration')->find((int) $binding->externalResourceId);
        $integration = $resource?->integration;
        if (! $integration instanceof CoreIntegration) {
            throw new RuntimeException('Google bağlantısı bulunamadı.');
        }
        $metadata = is_array($resource->metadata) ? $resource->metadata : [];
        $customerId = (string) $binding->customerId;
        $login = preg_replace('/\D+/', '', (string) ($metadata['login_customer_id'] ?? $metadata['manager_customer_id'] ?? $customerId)) ?: $customerId;
        $search = fn (string $query): array => $this->googleSearch($integration, $customerId, $login, $query);

        $customer = (array) (($search('SELECT customer.status, customer.currency_code, customer.time_zone FROM customer')[0] ?? [])['customer'] ?? []);
        $status = (string) ($customer['status'] ?? 'ENABLED');
        $timezone = (string) ($customer['timeZone'] ?? config('app.timezone'));
        $now = $this->localNow($timezone);

        $campaigns = [];
        $todaySpend = 0.0;
        foreach ($search("SELECT campaign.id, campaign.name, campaign_budget.amount_micros, metrics.cost_micros FROM campaign WHERE segments.date DURING TODAY AND campaign.status = 'ENABLED'") as $row) {
            $cost = (float) data_get($row, 'metrics.costMicros', 0) / 1_000_000;
            $budget = (float) data_get($row, 'campaignBudget.amountMicros', 0) / 1_000_000;
            $todaySpend += $cost;
            if ($budget > 0 && $cost >= $budget * 0.95) {
                $campaigns[] = ['name' => (string) data_get($row, 'campaign.name', ''), 'budget' => round($budget, 2), 'spend' => round($cost, 2)];
            }
        }

        $limit = null;
        try {
            $budgets = $search("SELECT account_budget.status, account_budget.approved_spending_limit_micros, account_budget.approved_spending_limit_type, account_budget.adjusted_spending_limit_micros, account_budget.amount_served_micros, account_budget.approved_end_date_time FROM account_budget WHERE account_budget.status = 'APPROVED'");
            foreach ($budgets as $row) {
                $micros = data_get($row, 'accountBudget.adjustedSpendingLimitMicros') ?? data_get($row, 'accountBudget.approvedSpendingLimitMicros');
                if ($micros === null) {
                    continue; // unlimited
                }
                $end = data_get($row, 'accountBudget.approvedEndDateTime');
                $limit = [
                    'cap' => round((float) $micros / 1_000_000, 2),
                    'spent' => round((float) data_get($row, 'accountBudget.amountServedMicros', 0) / 1_000_000, 2),
                    'ends_at' => $end !== null ? (string) $end : null,
                    'ended' => $end !== null && CarbonImmutable::parse((string) $end, $timezone)->lt($now),
                ];
                break;
            }
        } catch (Throwable) {
            // Accounts billed by card have no account budget; that is not an error.
        }

        return [
            'currency' => (string) ($customer['currencyCode'] ?? $binding->currency ?? ''),
            'blocked' => $status !== 'ENABLED' ? match ($status) {
                'SUSPENDED' => 'Hesap askıya alınmış',
                'CANCELED' => 'Hesap iptal edilmiş',
                'CLOSED' => 'Hesap kapalı',
                default => 'Hesap durumu: '.$status,
            } : null,
            'limit' => $limit,
            'balance' => null,
            'today_spend' => round($todaySpend, 2),
            'local_date' => $now->toDateString(),
            'local_hour' => (int) $now->format('G'),
            'capped_campaigns' => array_slice($campaigns, 0, 10),
            'capped_count' => count($campaigns),
            'disapproved_count' => 0,
            'disapproved' => [],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function googleSearch(CoreIntegration $integration, string $customerId, string $login, string $query): array
    {
        $response = $this->google->searchAds($integration, $customerId, $query, $login);
        if (! $response->successful()) {
            $message = (string) (data_get($response->json(), 'error.details.0.errors.0.message') ?? data_get($response->json(), 'error.message') ?? ('HTTP '.$response->status()));
            throw new RuntimeException('Google Ads: '.mb_substr($message, 0, 250));
        }

        return array_values(array_filter((array) ($response->json('results') ?? []), 'is_array'));
    }

    /** @return array<string, mixed>|null */
    private function metaState(DigitalAsset $asset): ?array
    {
        $binding = $this->metaBindings->resolve((string) $asset->id);
        if (! $binding->isReal()) {
            return null;
        }
        $integration = CoreExternalResource::query()->with('integration')->find((int) $binding->externalResourceId)?->integration;
        if (! $integration instanceof CoreIntegration) {
            throw new RuntimeException('Meta bağlantısı bulunamadı.');
        }
        $act = (string) ($binding->actId ?: 'act_'.$binding->accountId);
        $account = $this->meta->get($integration, $act, ['fields' => 'account_status,disable_reason,spend_cap,amount_spent,currency,timezone_name,is_prepay_account']);
        $currency = strtoupper((string) ($account['currency'] ?? $binding->currency ?? ''));
        $unit = in_array($currency, self::ZERO_DECIMAL, true) ? 1 : 100;
        $timezone = (string) ($account['timezone_name'] ?? $binding->timezone ?? config('app.timezone'));
        $now = $this->localNow($timezone);

        $status = (int) ($account['account_status'] ?? 1);
        $cap = (float) ($account['spend_cap'] ?? 0) / $unit;
        $limit = $cap > 0 ? ['cap' => round($cap, 2), 'spent' => round((float) ($account['amount_spent'] ?? 0) / $unit, 2), 'ends_at' => null, 'ended' => false] : null;

        $balance = null;
        if ((bool) ($account['is_prepay_account'] ?? false)) {
            try {
                $funding = $this->meta->get($integration, $act, ['fields' => 'funding_source_details']);
                $balance = $this->prepaidBalance((string) data_get($funding, 'funding_source_details.display_string', ''));
            } catch (Throwable) {
                // funding_source_details needs extra access on some accounts; the zero-spend check still covers it.
            }
        }

        $insights = $this->meta->get($integration, $act.'/insights', ['fields' => 'spend', 'date_preset' => 'today', 'level' => 'account']);
        $todaySpend = (float) data_get($insights, 'data.0.spend', 0);

        $disapproved = [];
        try {
            $ads = $this->meta->get($integration, $act.'/ads', [
                'fields' => 'name,effective_status',
                'effective_status' => json_encode(['DISAPPROVED', 'WITH_ISSUES']),
                'limit' => 50,
            ]);
            foreach ((array) ($ads['data'] ?? []) as $ad) {
                $disapproved[] = ['name' => (string) ($ad['name'] ?? ''), 'status' => (string) ($ad['effective_status'] ?? '')];
            }
        } catch (Throwable) {
            // Ad list is best effort; budget state is what matters here.
        }

        return [
            'currency' => $currency,
            'blocked' => self::META_BLOCKING_STATUS[$status] ?? null,
            'limit' => $limit,
            'balance' => $balance,
            'today_spend' => round($todaySpend, 2),
            'local_date' => $now->toDateString(),
            'local_hour' => (int) $now->format('G'),
            'capped_campaigns' => [],
            'capped_count' => 0,
            'disapproved_count' => count($disapproved),
            'disapproved' => array_slice($disapproved, 0, 10),
        ];
    }

    /** "Available balance (₺1.234,56 TRY)" → 1234.56; null when no amount is readable. */
    public function prepaidBalance(string $display): ?float
    {
        if (! preg_match('/(-?\d[\d.,]*)/u', $display, $match)) {
            return null;
        }
        $number = $match[1];
        $lastDot = strrpos($number, '.');
        $lastComma = strrpos($number, ',');
        $decimal = null;
        if ($lastDot !== false && $lastComma !== false) {
            $decimal = $lastDot > $lastComma ? '.' : ',';
        } elseif ($lastDot !== false || $lastComma !== false) {
            $sep = $lastDot !== false ? '.' : ',';
            $pos = (int) ($lastDot !== false ? $lastDot : $lastComma);
            $decimal = strlen($number) - $pos - 1 === 2 ? $sep : null;
        }
        $normalized = $decimal === null
            ? str_replace(['.', ','], '', $number)
            : str_replace($decimal, '.', str_replace($decimal === '.' ? ',' : '.', '', $number));

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function localNow(string $timezone): CarbonImmutable
    {
        try {
            return CarbonImmutable::now($timezone);
        } catch (Throwable) {
            return CarbonImmutable::now();
        }
    }
}
