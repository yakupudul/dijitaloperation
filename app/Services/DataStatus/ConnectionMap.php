<?php

namespace App\Services\DataStatus;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use Illuminate\Support\Collection;

/**
 * Bağlantı haritası (Bağlantı Düzeni 2.1): every brand on one row, every channel in one cell. A cell says in one word
 * whether the system sees that channel (Çalışıyor / Gecikmiş / Erişim sorunu / Bağlı değil / Yok) and, when it does
 * not, what is missing and who solves it. Read only: built from DataStatusReader and the site connector records.
 */
final class ConnectionMap
{
    /** @var array<string, string> column key => heading */
    public const array CHANNELS = [
        'site' => 'Site',
        'search_console' => 'Search Console',
        'ga4' => 'Analytics',
        'google_ads' => 'Google Ads',
        'google_business_profile' => 'İşletme Profili',
        'meta_ads' => 'Meta',
    ];

    private const array TONE_RANK = ['bad' => 3, 'warn' => 2, 'ok' => 1, 'muted' => 0];

    /** A site connector that has not answered for this many days is shown as late. */
    private const int CONNECTOR_SILENT_DAYS = 3;

    public function __construct(private readonly DataStatusReader $statuses) {}

    /**
     * @return list<array{brand_id: int, brand: string, logo: ?string, worst: string, cells: array<string, array{tone: string, label: string, detail: string, who: ?string, url: ?string, count: int}>}>
     */
    public function rows(): array
    {
        $brands = Brand::query()->operational()->orderBy('name')->get(['id', 'name', 'logo_url']);
        if ($brands->isEmpty()) {
            return [];
        }
        $assets = DigitalAsset::query()->operational()->whereIn('brand_id', $brands->pluck('id'))
            ->with(['assetBindings' => fn ($q) => $q->where('status', CoreAssetBinding::STATUS_ACTIVE)])->orderBy('id')->get();
        $statuses = $this->statuses->forAssets($assets);
        $connectors = CoreConnection::query()->where('type', 'wordpress_connector')->whereIn('digital_asset_id', $assets->pluck('id'))
            ->get(['id', 'digital_asset_id', 'enabled', 'last_success_at', 'last_error'])->keyBy('digital_asset_id');
        $byBrand = $assets->groupBy('brand_id');

        $rows = [];
        foreach ($brands as $brand) {
            /** @var Collection<int, DigitalAsset> $own */
            $own = $byBrand->get($brand->id, collect());
            $cells = ['site' => $this->siteCell($brand, $own->firstWhere('type', 'website'), $connectors)];
            foreach (array_slice(array_keys(self::CHANNELS), 1) as $capability) {
                $found = [];
                foreach ($own as $asset) {
                    foreach ($statuses[(int) $asset->id] ?? [] as $status) {
                        if ($status->capability === $capability) {
                            $found[] = [$asset, $status];
                        }
                    }
                }
                $cells[$capability] = $this->channelCell($brand, $capability, $found);
            }
            $worst = collect($cells)->sortByDesc(fn (array $c): int => self::TONE_RANK[$c['tone']])->first()['tone'];
            $rows[] = ['brand_id' => (int) $brand->id, 'brand' => (string) $brand->name, 'logo' => $brand->logo_url, 'worst' => $worst, 'cells' => $cells];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{ok: int, warn: int, bad: int}
     */
    public static function totals(array $rows): array
    {
        $totals = ['ok' => 0, 'warn' => 0, 'bad' => 0];
        foreach ($rows as $row) {
            foreach ($row['cells'] as $cell) {
                if (isset($totals[$cell['tone']])) {
                    $totals[$cell['tone']]++;
                }
            }
        }

        return $totals;
    }

    /**
     * @param  Collection<int, CoreConnection>  $connectors
     * @return array{tone: string, label: string, detail: string, who: ?string, url: ?string, count: int}
     */
    private function siteCell(Brand $brand, ?DigitalAsset $site, Collection $connectors): array
    {
        if ($site === null) {
            return $this->cell('muted', 'Yok', 'Bu markaya web sitesi eklenmemiş.', null, route('operator.brand.setup', ['brand' => $brand->id]));
        }
        $url = route('operator.website', ['assetId' => $site->id]);
        $connector = $connectors->get($site->id);
        if ($connector === null || ! $connector->enabled) {
            return $this->cell('warn', 'Yazma yok', 'Site bağlantısı kurulmamış: sistem siteyi okur ama düzeltme yazamaz. Bağlantı eklentisini kurmak senin işin.', 'Sen', $url);
        }
        if (filled($connector->last_error)) {
            return $this->cell('bad', 'Bağlantı koptu', 'Site bağlantısı son denemede cevap vermedi: '.mb_substr((string) $connector->last_error, 0, 140), 'Sen',
                route('operator.integrations.site-connector', ['connector' => $connector->id]));
        }
        if ($connector->last_success_at === null || $connector->last_success_at->lt(now()->subDays(self::CONNECTOR_SILENT_DAYS))) {
            return $this->cell('warn', 'Sessiz', 'Site bağlantısı '.($connector->last_success_at?->locale('tr')->diffForHumans() ?? 'hiç').' cevap verdi; sistem yeniden dener.', 'Sistem',
                route('operator.integrations.site-connector', ['connector' => $connector->id]));
        }

        return $this->cell('ok', 'Çalışıyor', 'Site okunuyor, düzeltmeler yazılabiliyor.', null, $url);
    }

    /**
     * @param  list<array{0: DigitalAsset, 1: DataStatus}>  $found
     * @return array{tone: string, label: string, detail: string, who: ?string, url: ?string, count: int}
     */
    private function channelCell(Brand $brand, string $capability, array $found): array
    {
        $name = self::CHANNELS[$capability];
        $bound = array_values(array_filter($found, fn (array $pair): bool => $pair[1]->isBound()));
        if ($bound === []) {
            $onSite = in_array($capability, ['search_console', 'ga4'], true);
            if ($found !== [] && $onSite) {
                return $this->cell('warn', 'Bağlı değil', $name.' bu markanın sitesine bağlanmamış; hesabı seçmek senin işin.', 'Sen',
                    route('operator.asset.sources', ['assetId' => $found[0][0]->id]));
            }

            return $this->cell('muted', 'Yok', $onSite ? 'Site eklenince bağlanır.' : $name.' hesabı bu markada yok. Varsa Varlıklar sekmesinden eklenir.', null,
                route('operator.brand', ['brand' => $brand->id, 'tab' => 'varliklar']));
        }
        usort($bound, fn (array $a, array $b): int => self::TONE_RANK[$this->tone($b[1])] <=> self::TONE_RANK[$this->tone($a[1])]);
        [$asset, $status] = $bound[0];
        $tone = $this->tone($status);
        $who = match (true) {
            $tone === 'ok' || $tone === 'muted' => null,
            in_array($status->state, [DataStatus::ACCESS_PROBLEM, DataStatus::ACCOUNT_BLOCKED, DataStatus::NOT_DELIVERING], true) => 'Sen',
            default => 'Sistem',
        };
        $detail = trim(($status->resourceName !== null ? $status->resourceName.': ' : '').$status->label().($status->detail() !== '' ? ' · '.$status->detail() : ''));
        if ($who === 'Sistem') {
            $detail .= ' Sistem veriyi kendisi yeniden çeker.';
        } elseif ($status->state === DataStatus::ACCESS_PROBLEM) {
            $detail .= ' Hesabı yeniden bağlamak senin işin.';
        }

        return $this->cell($tone, $tone === 'ok' ? 'Çalışıyor' : $status->label(), $detail, $who,
            $status->actionUrl ?? route('operator.asset.sources', ['assetId' => $asset->id]), count($bound));
    }

    /** Pasif / Durgun accounts are known and watched: grey, never a warning. */
    private function tone(DataStatus $status): string
    {
        return $status->tone();
    }

    /** @return array{tone: string, label: string, detail: string, who: ?string, url: ?string, count: int} */
    private function cell(string $tone, string $label, string $detail, ?string $who, ?string $url, int $count = 1): array
    {
        return ['tone' => $tone, 'label' => $label, 'detail' => $detail, 'who' => $who, 'url' => $url, 'count' => $count];
    }
}
