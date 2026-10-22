<?php

namespace App\Services\Operator;

use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Ads\SupersededAdAccounts;
use App\Services\DataStatus\DataStatus;
use App\Services\DataStatus\DataStatusReader;
use App\Services\Work\OpenWork;
use App\Support\SchemaState;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The list signals of Müşteriler / Markalar / Dijital varlıklar, computed once per render in a fixed number of queries:
 *
 *  - Açık iş: open work packages (OpenWork — the same brand's work of one type is one item), the same packages the brand
 *    Özet "Açık işler" box counts (BrandOverviewReader::openWork). Per asset a suggestion belongs to its target asset,
 *    else to the website of its page, else to the brand's first asset of the channel (as the Özet link resolves it).
 *  - Veri durumu: the DataStatusReader state of every bound source of the brand's active assets; the worst tone wins,
 *    exactly as the brand Özet asset card summarises it. A data problem is a source in tone bad (Erişim sorunu) or warn
 *    (Gecikmiş / İlk veri yükleniyor).
 *  - Dikkat: only a real exception (audit 8: 12 of 13 brands were red because every site has a priority-1 title row):
 *    a stopped or blocked source, or an open high / critical alarm (spend stopped, site down, conversions fell) — with
 *    the reason text. Critical suggestions are work, not an exception: they stay a number (Kritik counts check types,
 *    not rows: every (asset, action type) with a critical open suggestion counts once).
 */
final class PortfolioSignalsReader
{
    /** Suggestion priority at or under this value is critical (1 = high in every channel's scale). */
    public const int CRITICAL_PRIORITY = 1;

    /** Channel dots of the lists, in this order: source capability => short label. */
    public const array CHANNELS = [
        'search_console' => 'GSC',
        'ga4' => 'GA4',
        'google_business_profile' => 'Profil',
        'google_ads' => 'Ads',
        'meta_ads' => 'Meta',
    ];

    private const array TONE_RANK = ['bad' => 3, 'warn' => 2, 'muted' => 1, 'ok' => 0];

    /** Suggestion target type => the asset types its target id points at. */
    private const array TARGET_TYPES = ['site' => 'website', 'gbp' => 'google_business_profile', 'google_ads' => 'google_ads', 'meta' => 'meta_ads'];

    /** Suggestion channel => the asset types that carry it when the suggestion names no target. */
    private const array CHANNEL_TYPES = [
        'search' => ['website'],
        'maps' => ['google_business_profile', 'gbp'],
        'google_ads' => ['google_ads'],
        'meta' => ['meta_ads'],
    ];

    public function __construct(private readonly DataStatusReader $statuses) {}

    /**
     * Signals per brand and per asset. The brands' digitalAssets are loaded here when missing.
     *
     * @param  Collection<int, Brand>  $brands
     * @return array{brands: array<int, array<string, mixed>>, assets: array<int, array<string, mixed>>}
     */
    public function forBrands(Collection $brands): array
    {
        if ($brands->isEmpty()) {
            return ['brands' => [], 'assets' => []];
        }
        $brands = (new EloquentCollection($brands->all()))->loadMissing('digitalAssets');
        $assets = $brands->flatMap(fn (Brand $brand) => $brand->digitalAssets)
            ->reject(fn (DigitalAsset $asset): bool => in_array($asset->type, ['domain', 'hosting'], true))
            ->values();
        $statuses = $assets->isEmpty() ? [] : $this->statuses->forAssets($assets);
        [$brandWork, $assetWork] = $this->openWork($brands, $assets);
        $alarms = $this->alarms($assets);

        $assetSignals = [];
        foreach ($assets as $asset) {
            $assetSignals[(int) $asset->id] = $this->assetSignal($asset, $statuses[(int) $asset->id] ?? [], $assetWork[(int) $asset->id] ?? ['total' => 0, 'critical' => 0], $alarms[(int) $asset->id] ?? []);
        }

        $brandSignals = [];
        foreach ($brands as $brand) {
            $brandAssets = $assets->where('brand_id', $brand->id);
            $brandAlarms = $brandAssets->filter(fn (DigitalAsset $a): bool => ($a->status?->value ?? 'active') === 'active' && ! SupersededAdAccounts::isSuperseded((int) $a->id))
                ->flatMap(fn (DigitalAsset $a): array => $alarms[(int) $a->id] ?? [])->values()->all();
            $brandSignals[(int) $brand->id] = $this->brandSignal($brandAssets, $assetSignals, $statuses, $brandWork[(int) $brand->id] ?? ['total' => 0, 'critical' => 0, 'by_channel' => []], $brandAlarms);
        }

        return ['brands' => $brandSignals, 'assets' => $assetSignals];
    }

    /**
     * Customer signals: the sum over the customer's brands, the reason naming the first brand that needs attention.
     *
     * @param  Collection<int, Customer>  $customers
     * @return array{customers: array<int, array<string, mixed>>, brands: array<int, array<string, mixed>>}
     */
    public function forCustomers(Collection $customers): array
    {
        $customers = (new EloquentCollection($customers->all()))->loadMissing('brands.digitalAssets');
        $brands = $customers->flatMap(fn (Customer $customer) => $customer->brands)->values();
        $signals = $this->forBrands($brands);

        $out = [];
        foreach ($customers as $customer) {
            $rows = $customer->brands->map(fn (Brand $brand): array => ['name' => (string) $brand->name] + ($signals['brands'][(int) $brand->id] ?? self::emptyBrand()));
            $attention = $rows->firstWhere('needs_attention', true);
            $flagged = $rows->where('needs_attention', true)->count();
            $out[(int) $customer->id] = [
                'open_work' => (int) $rows->sum('open_work'),
                'critical' => (int) $rows->sum('critical'),
                'data_issues' => (int) $rows->sum('data_issues'),
                'reconnect' => (int) $rows->sum('reconnect'),
                'needs_attention' => $attention !== null,
                'attention_brands' => $flagged,
                'reason' => $attention === null ? null
                    : ($rows->count() > 1 ? $attention['name'].': ' : '').$attention['reason'].($flagged > 1 ? ' · +'.($flagged - 1).' marka' : ''),
            ];
        }

        return ['customers' => $out, 'brands' => $signals['brands']];
    }

    /** @return array<string, mixed> the signal of a brand without assets or work */
    public static function emptyBrand(): array
    {
        return ['open_work' => 0, 'critical' => 0, 'open_by_channel' => [], 'data_issues' => 0, 'reconnect' => 0, 'worst_tone' => 'muted',
            'channels' => self::emptyChannels(), 'needs_attention' => false, 'reason' => null];
    }

    /** @return array<string, array{label: string, tone: string, title: string}> */
    private static function emptyChannels(): array
    {
        $out = [];
        foreach (self::CHANNELS as $capability => $label) {
            $out[$capability] = ['label' => $label, 'tone' => 'none', 'title' => __('data_status.sources.'.$capability, [], 'tr').': varlık yok'];
        }

        return $out;
    }

    /**
     * @param  list<DataStatus>  $statuses
     * @param  array{total: int, critical: int}  $work
     * @return array<string, mixed>
     */
    private function assetSignal(DigitalAsset $asset, array $statuses, array $work, array $alarms = []): array
    {
        $sources = [];
        foreach ($statuses as $status) {
            $sources[$status->capability] = ['label' => $status->sourceLabel(), 'state' => $status->state, 'state_label' => $status->label(),
                'tone' => $status->tone(), 'detail' => $status->detail(), 'reconnect' => $status->state === DataStatus::ACCESS_PROBLEM,
                'reason' => $status->reason, 'age_days' => $status->ageDays];
        }
        $worst = collect($sources)->sortByDesc(fn (array $s): int => self::TONE_RANK[$s['tone']] ?? 0)->first();
        $problems = collect($sources)->filter(fn (array $s): bool => in_array($s['tone'], ['bad', 'warn'], true));
        $reconnect = collect($sources)->where('reconnect', true)->count();
        $active = ($asset->status?->value ?? 'active') === 'active' && ! SupersededAdAccounts::isSuperseded((int) $asset->id);

        return [
            'open_work' => $work['total'],
            'critical' => $work['critical'],
            'sources' => $sources,
            'worst_tone' => $worst['tone'] ?? 'muted',
            'data_state' => $worst['state'] ?? null,
            'data_label' => $worst === null ? 'Veri kaynağı yok' : ($problems->isEmpty() && $worst['tone'] === 'ok' ? 'Güncel' : $worst['label'].': '.$worst['state_label']),
            'data_issues' => $problems->count(),
            'reconnect' => $reconnect,
            // Dikkat only for a real exception (yakup 2026-10-20: 13/13 red meant nothing): a stopped or blocked source or an
            // open high alarm. A late source stays an amber dot, a critical suggestion a number.
            'needs_attention' => $active && ($problems->contains('tone', 'bad') || $alarms !== []),
            'reason' => $active ? $this->reason($problems, $work['critical'], $alarms) : null,
        ];
    }

    /**
     * @param  Collection<int, DigitalAsset>  $assets
     * @param  array<int, array<string, mixed>>  $assetSignals
     * @param  array<int, list<DataStatus>>  $statuses
     * @param  array{total: int, critical: int, by_channel: array<string, int>}  $work
     * @return array<string, mixed>
     */
    private function brandSignal(Collection $assets, array $assetSignals, array $statuses, array $work, array $alarms = []): array
    {
        $channels = self::emptyChannels();
        $problems = collect();
        foreach ($assets as $asset) {
            $active = ($asset->status?->value ?? 'active') === 'active' && ! SupersededAdAccounts::isSuperseded((int) $asset->id);
            foreach ($statuses[(int) $asset->id] ?? [] as $status) {
                if (! isset($channels[$status->capability])) {
                    continue;
                }
                $tone = $active ? $status->tone() : 'muted';
                $current = $channels[$status->capability]['tone'];
                if ($current === 'none' || (self::TONE_RANK[$tone] ?? 0) > (self::TONE_RANK[$current] ?? 0)) {
                    $channels[$status->capability] = ['label' => self::CHANNELS[$status->capability], 'tone' => $tone,
                        'title' => $status->sourceLabel().': '.($active ? $status->label() : 'Pasif varlık').($status->detail() !== '' ? ' · '.$status->detail() : '')];
                }
                if ($active && in_array($status->tone(), ['bad', 'warn'], true)) {
                    $problems->push(['label' => $status->sourceLabel(), 'state_label' => $status->label(), 'tone' => $status->tone(),
                        'reconnect' => $status->state === DataStatus::ACCESS_PROBLEM]);
                }
            }
        }
        $tones = collect($channels)->pluck('tone')->reject(fn (string $t): bool => $t === 'none');
        $worst = $tones->sortByDesc(fn (string $t): int => self::TONE_RANK[$t] ?? 0)->first() ?? 'muted';

        return [
            'open_work' => $work['total'],
            'critical' => $work['critical'],
            'open_by_channel' => $work['by_channel'],
            'data_issues' => $problems->count(),
            'reconnect' => $problems->where('reconnect', true)->count(),
            'worst_tone' => $worst,
            'channels' => $channels,
            'needs_attention' => $problems->contains('tone', 'bad') || $alarms !== [],
            'reason' => $this->reason($problems, $work['critical'], $alarms),
        ];
    }

    /**
     * Open high / critical alarms per asset (titles), the exceptions besides a broken source.
     *
     * @param  Collection<int, DigitalAsset>  $assets
     * @return array<int, list<string>>
     */
    private function alarms(Collection $assets): array
    {
        if ($assets->isEmpty() || ! SchemaState::hasTable('asset_alerts')) {
            return [];
        }

        return AssetAlert::query()->active()->whereIn('digital_asset_id', $assets->pluck('id')->all())->whereIn('severity', ['high', 'critical'])
            ->orderByRaw("CASE WHEN severity = 'critical' THEN 0 ELSE 1 END")->orderBy('id')->get(['digital_asset_id', 'title'])
            ->groupBy('digital_asset_id')->map(fn (Collection $group): array => $group->pluck('title')->map(fn ($t): string => (string) $t)->all())->all();
    }

    /**
     * Short reason next to the Dikkat badge: reconnect first, then an alarm, then the worst late source, then critical
     * suggestions.
     *
     * @param  Collection<int, array<string, mixed>>  $problems
     * @param  list<string>  $alarms
     */
    private function reason(Collection $problems, int $critical, array $alarms = []): ?string
    {
        $parts = [];
        $reconnect = $problems->where('reconnect', true);
        if ($reconnect->isNotEmpty()) {
            $parts[] = 'Yeniden bağlanmalı: '.$reconnect->pluck('label')->unique()->implode(', ');
        }
        if ($alarms !== []) {
            $parts[] = $alarms[0].(count($alarms) > 1 ? ' · +'.(count($alarms) - 1).' alarm' : '');
        }
        $late = $problems->where('reconnect', false)->sortByDesc(fn (array $p): int => self::TONE_RANK[$p['tone']] ?? 0)->values();
        if ($late->isNotEmpty()) {
            $parts[] = $late[0]['label'].': '.$late[0]['state_label'].($late->count() > 1 ? ' · +'.($late->count() - 1).' kaynak' : '');
        }
        if ($critical > 0) {
            $parts[] = $critical.' kritik öneri';
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * Actionable suggestions per brand (total, critical, by channel label) and per asset, in two grouped queries.
     *
     * @param  Collection<int, Brand>  $brands
     * @param  Collection<int, DigitalAsset>  $assets
     * @return array{0: array<int, array{total: int, critical: int, by_channel: array<string, int>}>, 1: array<int, array{total: int, critical: int}>}
     */
    private function openWork(Collection $brands, Collection $assets): array
    {
        $critical = 'CASE WHEN priority <= '.self::CRITICAL_PRIORITY.' THEN 1 ELSE 0 END';
        $rows = app(OpenWork::class)->query()
            ->whereIn('brand_id', $brands->pluck('id')->all())
            ->select(['brand_id', 'channel', 'action_type', 'target_type', 'target_id', 'page_id', 'action->check as check_id', 'action->standard_id as standard_id'])
            ->selectRaw($critical.' as is_critical, count(*) as n')
            ->groupBy('brand_id', 'channel', 'action_type', 'target_type', 'target_id', 'page_id', 'action->check', 'action->standard_id')
            ->groupByRaw($critical)
            ->toBase()
            ->get();
        $pageIds = $rows->pluck('page_id')->filter()->unique()->values()->all();
        $pageSites = $pageIds === [] ? collect() : Page::query()->whereIn('id', $pageIds)->pluck('website_asset_id', 'id');
        $assetById = $assets->keyBy('id');
        $firstOfType = $assets->sortBy('id')->groupBy('brand_id')->map(fn (Collection $group) => $group->unique('type')->pluck('id', 'type'));

        $brandWork = [];
        $assetWork = [];
        // Critical counts one per (asset, check type): 6 000 pages missing a title on one site are one critical issue.
        $brandCritical = [];
        $assetCritical = [];
        // Open work counts packages (OpenWork): the same brand's work of one type is one item, on the asset too.
        $brandPackages = [];
        $assetPackages = [];
        foreach ($rows as $row) {
            $brandId = (int) $row->brand_id;
            $isCritical = (int) $row->is_critical === 1;
            $label = (string) (Suggestion::CHANNEL_LABELS[$row->channel] ?? $row->channel);
            $type = OpenWork::type((string) $row->channel, $row->action_type, OpenWork::checkOf($row->check_id, $row->standard_id));
            $brandWork[$brandId] ??= ['total' => 0, 'critical' => 0, 'by_channel' => []];
            if (! isset($brandPackages[$brandId][$type])) {
                $brandPackages[$brandId][$type] = true;
                $brandWork[$brandId]['total']++;
                $brandWork[$brandId]['by_channel'][$label] = ($brandWork[$brandId]['by_channel'][$label] ?? 0) + 1;
            }

            $assetId = $this->assetFor($row, $brandId, $assetById, $pageSites, $firstOfType);
            $check = (string) ($row->action_type ?? '');
            if ($isCritical) {
                $brandCritical[$brandId][($assetId !== null ? 'a'.$assetId : 'c'.$row->channel).'|'.$check] = true;
            }
            if ($assetId !== null) {
                $assetWork[$assetId] ??= ['total' => 0, 'critical' => 0];
                if (! isset($assetPackages[$assetId][$type])) {
                    $assetPackages[$assetId][$type] = true;
                    $assetWork[$assetId]['total']++;
                }
                if ($isCritical) {
                    $assetCritical[$assetId][$check] = true;
                }
            }
        }
        foreach ($brandCritical as $brandId => $groups) {
            $brandWork[$brandId]['critical'] = count($groups);
        }
        foreach ($assetCritical as $assetId => $groups) {
            $assetWork[$assetId]['critical'] = count($groups);
        }

        return [$brandWork, $assetWork];
    }

    /**
     * @param  Collection<int|string, DigitalAsset>  $assetById
     * @param  Collection<int|string, mixed>  $pageSites
     * @param  Collection<int|string, Collection<string, int>>  $firstOfType
     */
    private function assetFor(object $row, int $brandId, Collection $assetById, Collection $pageSites, Collection $firstOfType): ?int
    {
        $target = $row->target_id !== null ? (int) $row->target_id : null;
        if ($target !== null && isset(self::TARGET_TYPES[$row->target_type]) && ($asset = $assetById->get($target)) !== null && (int) $asset->brand_id === $brandId) {
            return $target;
        }
        if ($row->page_id !== null && isset($pageSites[$row->page_id]) && $assetById->has((int) $pageSites[$row->page_id])) {
            return (int) $pageSites[$row->page_id];
        }
        foreach (self::CHANNEL_TYPES[$row->channel] ?? [] as $type) {
            $id = $firstOfType->get($brandId)?->get($type);
            if ($id !== null) {
                return (int) $id;
            }
        }

        return null;
    }
}
