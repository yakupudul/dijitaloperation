<?php

namespace App\Services\MetaAds;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\Integrations\Meta\MetaApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Meta country + city (region) performance with results (read-only Insights).
 *
 * Collects ad-level daily rows twice — once broken down by country, once by region — with spend, clicks and the
 * canonical result actions (lead, purchase, messaging). Meta cannot combine country and region in one breakdown,
 * so a region row gets the ad's country when that ad delivered in exactly one country that day. The table keeps
 * campaign / ad set / ad names so the AI can read "which service, which area, which audience converted".
 */
final class MetaGeoResults
{
    public const string TABLE = 'meta_geo_results_daily';

    private const array LEAD_TYPES = ['lead', 'onsite_conversion.lead_grouped', 'leadgen_grouped', 'offsite_conversion.fb_pixel_lead'];

    private const array PURCHASE_TYPES = ['omni_purchase', 'purchase', 'offsite_conversion.fb_pixel_purchase', 'onsite_web_purchase'];

    private const array MESSAGE_TYPES = ['onsite_conversion.messaging_conversation_started_7d'];

    private const int MAX_PAGES = 40;

    public function __construct(
        private readonly MetaAdsSpecialistBindingResolver $bindings,
        private readonly MetaApiClient $client,
    ) {}

    /** Collects the last N days (first run: 30). @return int rows stored */
    public function collect(DigitalAsset $asset, ?int $days = null): int
    {
        $binding = $this->bindings->resolve((string) $asset->id);
        if (! $binding->isReal()) {
            throw new RuntimeException('Meta reklam hesabı bağlı değil.');
        }
        $integration = CoreExternalResource::query()->with('integration')->find((int) $binding->externalResourceId)?->integration;
        if (! $integration instanceof CoreIntegration) {
            throw new RuntimeException('Meta bağlantısı bulunamadı.');
        }
        $accountId = (string) $binding->accountId;
        $act = (string) ($binding->actId ?: 'act_'.$accountId);
        $hasRows = DB::table(self::TABLE)->where('digital_asset_id', $asset->id)->where('account_id', $accountId)->exists();
        $days ??= $hasRows ? 3 : 30;
        $end = CarbonImmutable::now((string) ($binding->timezone ?: config('app.timezone')))->subDay()->startOfDay();
        $start = $end->subDays(max(1, $days) - 1);

        $stored = 0;
        for ($sliceStart = $start; $sliceStart->lte($end); $sliceStart = $sliceStart->addDays(7)) {
            $sliceEnd = $sliceStart->addDays(6)->min($end);
            $range = ['since' => $sliceStart->toDateString(), 'until' => $sliceEnd->toDateString()];
            $countries = $this->fetch($integration, $act, $range, 'country');
            $regions = $this->fetch($integration, $act, $range, 'region');
            $stored += $this->store((int) $asset->id, $accountId, $range, $countries, $regions);
        }

        return $stored;
    }

    /**
     * @param  array{since: string, until: string}  $range
     * @return list<array<string, mixed>>
     */
    private function fetch(CoreIntegration $integration, string $act, array $range, string $breakdown): array
    {
        $payload = $this->client->get($integration, $act.'/insights', [
            'level' => 'ad',
            'fields' => 'campaign_id,campaign_name,adset_id,adset_name,ad_id,ad_name,spend,impressions,clicks,actions,action_values,account_currency,date_start',
            'breakdowns' => $breakdown,
            'time_increment' => 1,
            'time_range' => json_encode($range, JSON_THROW_ON_ERROR),
            'use_unified_attribution_setting' => 'true',
            'limit' => 500,
        ]);
        $rows = [];
        for ($page = 1; ; $page++) {
            foreach ((array) ($payload['data'] ?? []) as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            $next = data_get($payload, 'paging.next');
            if (! is_string($next) || $next === '' || $page >= self::MAX_PAGES) {
                break;
            }
            $payload = $this->client->getAbsolute($integration, $next);
        }

        return $rows;
    }

    /**
     * @param  array{since: string, until: string}  $range
     * @param  list<array<string, mixed>>  $countries
     * @param  list<array<string, mixed>>  $regions
     */
    private function store(int $assetId, string $accountId, array $range, array $countries, array $regions): int
    {
        $countryOf = [];
        foreach ($countries as $row) {
            if ((float) ($row['spend'] ?? 0) > 0 || (int) ($row['impressions'] ?? 0) > 0) {
                $countryOf[($row['date_start'] ?? '').'|'.($row['ad_id'] ?? '')][strtoupper((string) ($row['country'] ?? ''))] = true;
            }
        }
        $records = [];
        $now = now();
        foreach ([['country', $countries], ['region', $regions]] as [$level, $rows]) {
            foreach ($rows as $row) {
                $adId = (string) ($row['ad_id'] ?? '');
                $date = (string) ($row['date_start'] ?? '');
                if ($adId === '' || $date === '') {
                    continue;
                }
                if ($level === 'country') {
                    $country = strtoupper((string) ($row['country'] ?? ''));
                    $region = '';
                } else {
                    $set = array_keys($countryOf[$date.'|'.$adId] ?? []);
                    $country = count($set) === 1 ? (string) $set[0] : '';
                    $region = mb_substr((string) ($row['region'] ?? ''), 0, 120);
                }
                $key = implode('|', [$level, $date, $adId, $country, $region]);
                $records[$key] = [
                    'digital_asset_id' => $assetId,
                    'account_id' => $accountId,
                    'reporting_date' => $date,
                    'level' => $level,
                    'ad_id' => mb_substr($adId, 0, 40),
                    'ad_name' => $this->text($row['ad_name'] ?? null),
                    'adset_id' => $this->id($row['adset_id'] ?? null),
                    'adset_name' => $this->text($row['adset_name'] ?? null),
                    'campaign_id' => $this->id($row['campaign_id'] ?? null),
                    'campaign_name' => $this->text($row['campaign_name'] ?? null),
                    'country' => mb_substr($country, 0, 8),
                    'region' => $region,
                    'spend' => round((float) ($row['spend'] ?? 0), 2),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'leads' => $this->action((array) ($row['actions'] ?? []), self::LEAD_TYPES),
                    'purchases' => $this->action((array) ($row['actions'] ?? []), self::PURCHASE_TYPES),
                    'purchase_value' => $this->action((array) ($row['action_values'] ?? []), self::PURCHASE_TYPES),
                    'messages' => $this->action((array) ($row['actions'] ?? []), self::MESSAGE_TYPES),
                    'currency' => mb_substr((string) ($row['account_currency'] ?? ''), 0, 8) ?: null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::transaction(function () use ($assetId, $accountId, $range, $records): void {
            DB::table(self::TABLE)->where('digital_asset_id', $assetId)->where('account_id', $accountId)
                ->whereBetween('reporting_date', [$range['since'], $range['until']])->delete();
            foreach (array_chunk(array_values($records), 300) as $chunk) {
                DB::table(self::TABLE)->insert($chunk);
            }
        });

        return count($records);
    }

    /**
     * First matching action type in priority order (Meta repeats the same result under several aliases).
     *
     * @param  array<int, mixed>  $actions
     * @param  list<string>  $types
     */
    private function action(array $actions, array $types): float
    {
        $values = [];
        foreach ($actions as $action) {
            if (is_array($action) && isset($action['action_type'])) {
                $values[(string) $action['action_type']] = (float) ($action['value'] ?? 0);
            }
        }
        foreach ($types as $type) {
            if (isset($values[$type])) {
                return round($values[$type], 2);
            }
        }

        return 0.0;
    }

    private function text(mixed $value): ?string
    {
        return filled($value) ? mb_substr((string) $value, 0, 300) : null;
    }

    private function id(mixed $value): ?string
    {
        return filled($value) ? mb_substr((string) $value, 0, 40) : null;
    }
}
