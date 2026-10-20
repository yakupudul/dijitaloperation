<?php

namespace App\Services\GoogleAds\Desk;

use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Services\Ads\AdServiceStats;
use App\Services\Alerts\AlarmCauses;
use App\Services\BrandSetup\BrandSetupStatus;
use App\Services\GoogleAds\GoogleAdsChanges;
use App\Services\GoogleAds\GoogleAdsLeadQuality;
use App\Services\GoogleAds\GoogleAdsScreen;
use App\Services\GoogleAds\GoogleAdsSuggestions;
use App\Services\Meta\MetaDesk;
use App\Services\Repair\RepairDesk;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Google Ads menüsü (yakup 2026-10-19, "İşletme profilleri gibi"): every operational brand's Google Ads accounts in one
 * place. Durum (one row per account: 30-day numbers, data freshness, open alarms, failing system checks and what waits
 * on each tab), Arama terimleri, Ayarlar ve bütçe, Reklamlar ve kampanyalar, Ölçüm and Hizmet karşılaştırma read the
 * same suggestions, alerts and collected rows the account pages read. Nothing is written here: approvals go through
 * the Onarım masası paths (RepairDesk) or the Editor file, exactly as on the account page.
 */
final class GoogleAdsDesk
{
    /** Route => tab label, in menu order. */
    public const array TABS = [
        'operator.google-ads-desk' => 'Durum',
        'operator.google-ads-terms' => 'Arama terimleri',
        'operator.google-ads-changes' => 'Ayarlar ve bütçe',
        'operator.google-ads-ads' => 'Reklamlar ve kampanyalar',
        'operator.google-ads-measurement' => 'Ölçüm',
        'operator.google-ads-services' => 'Hizmet karşılaştırma',
    ];

    /** Numbers window (days), compared with the window before it. */
    public const int DAYS = 30;

    /** A spending account whose newest daily row is older than this many days counts as "veri gelmiyor". */
    public const int FRESH_DAYS = 3;

    /** Suggestion types per tab. */
    public const array TERM_TYPES = ['ads_negative'];

    public const array CHANGE_TYPES = [GoogleAdsChanges::TYPE, 'ads_check', GoogleAdsSuggestions::ADVICE_TYPE];

    public const array AD_TYPES = ['ads_campaign', 'ads_rsa', 'ads_budget_split', 'ads_experiments'];

    /** System checks shown on Ölçüm. */
    public const array MEASUREMENT_CHECKS = ['conversion_tracking', 'conversion_goals', 'landing'];

    /** Alarm kinds that put an account on the red strip whatever their severity. */
    public const array STRIP_KINDS = ['ads_data_gap', 'delivery_stopped', 'conversions_stopped', 'budget_account_blocked', 'budget_exhausted', 'budget_no_spend_today', 'spend_spike'];

    public function __construct(
        private readonly GoogleAdsScreen $screen,
        private readonly GoogleAdsSuggestions $suggestions,
        private readonly GoogleAdsLeadQuality $leadQuality,
    ) {}

    /** @return Collection<int, DigitalAsset> operational Google Ads accounts with a brand, by brand name */
    public function accounts(?int $brandId = null): Collection
    {
        return DigitalAsset::query()->operational()->with('brand:id,name')->where('type', 'google_ads')->whereNotNull('brand_id')
            ->when($brandId !== null, fn (Builder $q): Builder => $q->where('brand_id', $brandId))
            ->get()->sortBy(fn (DigitalAsset $a): string => mb_strtolower((string) $a->brand?->name).'|'.$a->id)->values();
    }

    /** @return array<int, string> brand id => name, brands with a Google Ads account */
    public function brandOptions(): array
    {
        return $this->accounts()->pluck('brand.name', 'brand_id')->filter()->sort()->all();
    }

    /**
     * Durum: one row per account.
     *
     * @param  Collection<int, DigitalAsset>  $accounts
     * @return list<array<string, mixed>>
     */
    public function status(Collection $accounts): array
    {
        $overviews = $accounts->isEmpty() ? [] : $this->screen->overviews($accounts, self::DAYS);
        $ids = $accounts->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $alerts = AssetAlert::query()->active()->whereIn('digital_asset_id', $ids ?: [0])->orderBy('id')->get()->groupBy('digital_asset_id');
        $open = Suggestion::query()->where('channel', GoogleAdsSuggestions::CHANNEL)->where('target_type', GoogleAdsSuggestions::TARGET)
            ->whereIn('target_id', $ids ?: [0])->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK])
            ->groupBy('target_id', 'action_type')->selectRaw('target_id, action_type, COUNT(*) as n')->get()->groupBy('target_id');
        $fresh = now()->subDays(self::FRESH_DAYS)->toDateString();

        $rows = [];
        $unused = [];
        foreach ($accounts as $asset) {
            $id = (int) $asset->id;
            $overview = $overviews[$id] ?? null;
            $current = $overview['current'] ?? null;
            $lastDate = $overview['last_date'] ?? null;
            $state = match (true) {
                $overview === null => 'unbound',
                $lastDate === null => 'nodata',
                ($current['cost'] ?? null) === null && $lastDate < now()->subDays(self::DAYS)->toDateString() => 'idle',
                $lastDate < $fresh => 'late',
                default => 'ok',
            };
            $counts = $open->get($id, collect())->pluck('n', 'action_type')->map(fn ($n): int => (int) $n)->all();
            $checks = $this->suggestions->checkStates($asset);
            // An unbound account of a brand whose Google Ads channel is "Kullanılmıyor" raises no "Hesap bağlı değil".
            if ($state === 'unbound' && $asset->brand !== null) {
                $unused[(int) $asset->brand_id] ??= app(BrandSetupStatus::class)->channelUnused($asset->brand, 'google_ads');
            }
            $rows[] = [
                'asset' => $asset, 'brand' => (string) $asset->brand?->name, 'state' => $state, 'last_date' => $lastDate,
                'unused' => $state === 'unbound' && ($unused[(int) $asset->brand_id] ?? false),
                'currency' => $overview['currency'] ?? null, 'current' => $current, 'previous' => $overview['previous'] ?? null,
                'change' => ['cost' => self::change($current['cost'] ?? null, $overview['previous']['cost'] ?? null),
                    'conversions' => self::change($current['conversions'] ?? null, $overview['previous']['conversions'] ?? null),
                    'cpa' => self::change($current['cpa'] ?? null, $overview['previous']['cpa'] ?? null)],
                'alerts' => $alerts->get($id, collect())->map(fn (AssetAlert $a): array => ['kind' => (string) $a->kind, 'severity' => (string) $a->severity,
                    'title' => (string) $a->title, 'message' => (string) $a->message])->values()->all(),
                'checks' => $checks === null ? null : array_values(array_filter($checks['items'], fn (array $c): bool => $c['state'] === 'fail')),
                'checked_at' => $checks['at'] ?? null,
                'waiting' => [
                    'terms' => array_sum(array_intersect_key($counts, array_flip(self::TERM_TYPES))),
                    'changes' => (int) ($counts[GoogleAdsChanges::TYPE] ?? 0),
                    'ads' => array_sum(array_intersect_key($counts, array_flip(self::AD_TYPES))),
                ],
            ];
        }

        return $rows;
    }

    /**
     * The red strip: only what loses money or leaves an account blind now, one row per account (AlarmCauses): the
     * most basic cause leads, the account's other alarm titles are in `others`. An unbound account of a brand whose
     * Google Ads channel is "Kullanılmıyor" (status() `unused`) raises nothing.
     *
     * @param  list<array<string, mixed>>  $rows  status()
     * @return list<array{asset_id: int, brand: string, title: string, message: string, severity: string, others: list<string>}>
     */
    public static function problems(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $assetId = (int) $row['asset']->id;
            $kinds = array_column($row['alerts'], 'kind');
            $found = [];
            if ($row['state'] === 'unbound' && ! ($row['unused'] ?? false)) {
                $found[] = ['kind' => AlarmCauses::UNBOUND, 'severity' => 'high', 'title' => 'Hesap bağlı değil',
                    'message' => 'Bu Google Ads varlığına bağlı hesap yok ya da erişim kopmuş; veri toplanamıyor.'];
            } elseif ($row['state'] === 'late' && ! in_array('ads_data_gap', $kinds, true)) {
                $found[] = ['kind' => AlarmCauses::LATE, 'severity' => 'critical', 'title' => 'Veri / harcama gelmiyor',
                    'message' => 'Son veri '.CarbonImmutable::parse((string) $row['last_date'])->format('d.m.Y').' günü. Hesap erişimi, ödeme ya da kampanya durumu kontrol edilmeli.'];
            }
            foreach ($row['alerts'] as $alert) {
                if (in_array($alert['kind'], self::STRIP_KINDS, true) || $alert['severity'] === 'critical') {
                    $found[] = $alert;
                }
            }
            if ($found === []) {
                continue;
            }
            $ordered = AlarmCauses::ordered($found, fn (array $a): string => (string) $a['kind'], fn (array $a): string => (string) $a['severity']);
            $lead = $ordered->first();
            $out[] = ['asset_id' => $assetId, 'brand' => $row['brand'],
                'severity' => $ordered->contains(fn (array $a): bool => $a['severity'] === 'critical') ? 'critical' : (string) $lead['severity'],
                'title' => (string) $lead['title'], 'message' => (string) $lead['message'],
                'others' => $ordered->slice(1)->pluck('title')->map(fn ($title): string => (string) $title)->unique()->values()->all()];
        }
        usort($out, fn (array $a, array $b): int => [self::rank($a['severity']), $a['brand']] <=> [self::rank($b['severity']), $b['brand']]);

        return $out;
    }

    /**
     * Failing system checks shared by most accounts (shown once above the table, like İşletme profilleri).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{label: string, missing: int}>
     */
    public static function sharedGaps(array $rows, float $share = 0.8, int $minRows = 3): array
    {
        $checked = array_values(array_filter($rows, fn (array $r): bool => $r['checks'] !== null));
        if (count($checked) < $minRows) {
            return [];
        }
        $missing = [];
        foreach ($checked as $row) {
            foreach ($row['checks'] as $check) {
                $missing[$check['id']] ??= ['label' => $check['label'], 'missing' => 0];
                $missing[$check['id']]['missing']++;
            }
        }

        return array_filter($missing, fn (array $m): bool => $share <= $m['missing'] / count($checked));
    }

    /**
     * Opened account: six months of spend / conversions, the costliest campaigns of the window and what MoxDOP wrote.
     *
     * @return array{months: list<array{month: string, cost: float, conversions: float}>, campaigns: list<array<string, mixed>>, work: list<array<string, mixed>>}
     */
    public function detail(DigitalAsset $asset): array
    {
        $months = [];
        $ctx = $this->screen->context($asset);
        if ($ctx !== null) {
            $from = $ctx['end']->subMonths(5)->startOfMonth()->toDateString();
            foreach ($ctx['scope']->daily('google_ads_account_daily', $from, $ctx['end']->toDateString())
                ->selectRaw('reporting_date, SUM(cost_amount) as cost, SUM(conversions) as conversions')->groupBy('reporting_date')->get() as $day) {
                $month = substr((string) $day->reporting_date, 0, 7);
                $months[$month] ??= ['month' => $month, 'cost' => 0.0, 'conversions' => 0.0];
                $months[$month]['cost'] += (float) $day->cost;
                $months[$month]['conversions'] += (float) $day->conversions;
            }
            krsort($months);
        }
        try {
            $campaigns = array_slice($this->screen->analysis($asset, 'campaign', self::DAYS), 0, 6);
        } catch (Throwable $error) {
            report($error);
            $campaigns = [];
        }

        return ['months' => array_values($months), 'campaigns' => $campaigns, 'work' => $this->work((int) $asset->id)];
    }

    /** @return list<array{at: ?string, label: string, status: string, status_label: string, error: ?string}> */
    public function work(int $assetId, int $limit = 12): array
    {
        return ExternalWriteAction::query()->where('digital_asset_id', $assetId)->where('channel', ExternalWriteAction::CHANNEL_GOOGLE_ADS)
            ->latest('id')->limit($limit)->get()
            ->map(fn (ExternalWriteAction $a): array => ['at' => ($a->finished_at ?? $a->created_at)?->timezone('Europe/Istanbul')->format('d.m.Y H:i'),
                'label' => (string) (data_get($a->request_payload, 'label') ?: (RepairDesk::WRITE_LABELS[$a->action] ?? $a->action)),
                'status' => (string) $a->status, 'status_label' => $a->statusLabel(), 'error' => $a->status === 'failed' ? mb_substr((string) $a->error, 0, 200) : null])
            ->all();
    }

    /**
     * Open suggestions of the given types over the accounts in scope, costliest / most urgent first.
     *
     * @param  Collection<int, DigitalAsset>  $accounts
     * @param  list<string>  $types
     * @return Collection<int, Suggestion>
     */
    public function open(Collection $accounts, array $types): Collection
    {
        return Suggestion::query()->where('channel', GoogleAdsSuggestions::CHANNEL)->where('target_type', GoogleAdsSuggestions::TARGET)
            ->whereIn('target_id', $accounts->pluck('id')->all() ?: [0])->whereIn('action_type', $types)
            ->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK])
            ->orderBy('priority')->orderBy('id')->limit(1000)->get()
            ->sortByDesc(fn (Suggestion $s): float => (float) data_get($s->action, 'cost', 0))->sortBy('priority')->values();
    }

    /**
     * Approved drafts waiting for the Editor file or for "Editor'a aktardım", per account.
     *
     * @param  Collection<int, DigitalAsset>  $accounts
     * @return list<array{asset: DigitalAsset, brand: string, drafts: int, batches: int}>
     */
    public function editorWaiting(Collection $accounts): array
    {
        $out = [];
        foreach ($accounts as $asset) {
            $drafts = $this->suggestions->editorDrafts($asset)->count();
            $batches = $this->suggestions->editorBatches($asset)->count();
            if ($drafts + $batches > 0) {
                $out[] = ['asset' => $asset, 'brand' => (string) $asset->brand?->name, 'drafts' => $drafts, 'batches' => $batches];
            }
        }

        return $out;
    }

    /**
     * Ölçüm: per account the conversion setup (primary actions, weak primaries, last conversion), the measurement
     * checks and whether last month's real results (form / uygun / randevu / satış) were entered.
     *
     * @param  Collection<int, DigitalAsset>  $accounts
     * @return list<array<string, mixed>>
     */
    public function measurement(Collection $accounts): array
    {
        $lastMonth = now()->subMonthNoOverflow()->format('Y-m');
        $out = [];
        foreach ($accounts as $asset) {
            $actions = $this->screen->conversionActions($asset);
            $enabled = array_values(array_filter($actions, fn (array $a): bool => $a['status'] === null || $a['status'] === 'ENABLED'));
            $primary = array_values(array_filter($enabled, fn (array $a): bool => $a['primary']));
            $lastConversion = collect($primary)->pluck('last_conversion')->filter()->max();
            $categories = array_values(array_unique(array_filter(array_column($enabled, 'category'))));
            $checks = $this->suggestions->checkStates($asset);
            $quality = $this->leadQuality->month($asset, $lastMonth);
            $out[] = [
                'asset' => $asset, 'brand' => (string) $asset->brand?->name, 'actions' => count($enabled), 'primary' => $primary,
                'weak_primary' => array_values(array_filter($primary, fn (array $a): bool => in_array($a['category'], ['PAGE_VIEW', 'ENGAGEMENT'], true))),
                'has_phone' => (bool) array_intersect($categories, ['PHONE_CALL_LEAD']) || collect($enabled)->contains(fn (array $a): bool => (bool) preg_match('/(call|ara|telefon|phone)/iu', $a['name'])),
                'last_conversion' => $lastConversion,
                'checks' => $checks === null ? null : array_values(array_filter($checks['items'], fn (array $c): bool => in_array($c['id'], self::MEASUREMENT_CHECKS, true))),
                'quality_month' => $lastMonth,
                'quality_entered' => collect($quality)->contains(fn (array $r): bool => $r['entry'] !== null),
                'quality_campaigns' => count($quality),
            ];
        }

        return $out;
    }

    /**
     * Hizmet karşılaştırma: per catalog service, every brand's Google Ads cost per conversion of the last 30 days
     * (AdServiceStats, keyword → hizmet) with the median of the brands and each brand's distance to it.
     *
     * @return list<array{service_id: int, name: string, median: ?float, brands: list<array{brand_id: int, brand: string, city: string, spend: float, results: float, cost: float, diff: ?float}>}>
     */
    public function services(?int $brandId = null): array
    {
        $costs = AdServiceStats::brandCosts('google_ads');
        $names = MetaDesk::serviceNames(array_map(fn (string $k): int => (int) explode('|', $k)[0], array_keys($costs)));
        $brandIds = collect($costs)->flatten(1)->pluck('brand_id')->unique()->all();
        $brandNames = Brand::query()->whereIn('id', $brandIds ?: [0])->pluck('name', 'id')->all();
        $out = [];
        foreach ($costs as $key => $brands) {
            [$serviceId, $type] = explode('|', $key);
            if ($type !== 'conversions') {
                continue;
            }
            if ($brandId !== null && ! in_array($brandId, array_column($brands, 'brand_id'), true)) {
                continue;
            }
            $merged = [];
            foreach ($brands as $b) {
                $merged[$b['brand_id']] ??= ['brand_id' => $b['brand_id'], 'brand' => (string) ($brandNames[$b['brand_id']] ?? '#'.$b['brand_id']), 'city' => $b['city'], 'spend' => 0.0, 'results' => 0.0];
                $merged[$b['brand_id']]['spend'] += $b['spend'];
                $merged[$b['brand_id']]['results'] += $b['results'];
            }
            $merged = array_map(fn (array $b): array => $b + ['cost' => round($b['spend'] / max($b['results'], 0.01), 2)], array_values($merged));
            $median = count($merged) >= 2 ? AdServiceStats::median(array_column($merged, 'cost')) : null;
            $merged = array_map(fn (array $b): array => $b + ['diff' => $median !== null && $median > 0 ? round(($b['cost'] / $median - 1) * 100, 1) : null], $merged);
            usort($merged, fn (array $a, array $b): int => $a['cost'] <=> $b['cost']);
            $out[] = ['service_id' => (int) $serviceId, 'name' => $names[(int) $serviceId] ?? 'Hizmet #'.$serviceId, 'median' => $median, 'brands' => $merged];
        }
        usort($out, fn (array $a, array $b): int => [count($b['brands']), $a['name']] <=> [count($a['brands']), $b['name']]);

        return $out;
    }

    /** Last time the cross-brand ad numbers were rebuilt. */
    public static function servicesAt(): ?string
    {
        $at = DB::table('ad_service_stats')->where('channel', 'google_ads')->max('period_end');

        return $at !== null ? (string) $at : null;
    }

    public static function change(?float $now, ?float $before): ?float
    {
        return $now !== null && $before !== null && $before > 0 ? round(($now / $before - 1) * 100, 1) : null;
    }

    private static function rank(string $severity): int
    {
        return ['critical' => 0, 'high' => 1, 'medium' => 2][$severity] ?? 3;
    }
}
