<?php

namespace App\Services\Operator;

use App\Models\Brand;
use App\Models\BrandTarget;
use App\Models\DigitalAsset;
use App\Models\GbpReview;
use App\Models\SiteScore;
use App\Services\Goals\BrandTargets;
use App\Services\Goals\TargetImpact;
use App\Services\Repair\RepairDesk;
use App\Support\SchemaState;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Marka sayfası (Aşama 6, prototype approved by yakup 2026-10-22): one page per brand without tabs. On top the month's
 * goal and how far the brand is, under it one card per channel (web, Google Ads, İşletme profili, Meta): its state,
 * two or three numbers of the period, the three findings that bring the most and one "Hepsini çöz" that sends the
 * channel's ready work. Everything is read from what the panel already keeps; nothing is computed by AI.
 */
final class BrandPageReader
{
    /** Card channel => [title, desk route]. */
    public const array CARDS = [
        'web' => ['Web sitesi', 'operator.repair'],
        'google_ads' => ['Google Ads', 'operator.google-ads-desk'],
        'gbp' => ['İşletme profili', 'operator.gbp-desk'],
        'meta' => ['Meta', 'operator.meta-desk'],
    ];

    /** Card channel => suggestion channel (OpenWork). */
    public const array WORK_CHANNELS = ['web' => 'search', 'google_ads' => 'google_ads', 'gbp' => 'maps', 'meta' => 'meta'];

    public const array STATES = ['ok' => 'Yolunda', 'look' => 'Bakılacak', 'bad' => 'Kayıpta', 'none' => 'Bağlı değil'];

    public const int FINDINGS = 3;

    /** The desk rows of one brand, read once per reader. */
    private ?Collection $rows = null;

    private ?int $rowsBrand = null;

    public function __construct(private readonly RepairDesk $desk, private readonly TargetImpact $impact, private readonly PortfolioReader $portfolio, private readonly BrandOverviewReader $overview) {}

    /** The card channel a desk row belongs to. */
    public static function channelOf(array $row): string
    {
        $kind = (string) ($row['kind'] ?? '');

        return match (true) {
            in_array($kind, [RepairDesk::ADS_NEGATIVE, RepairDesk::ADS_CHANGE], true) => 'google_ads',
            in_array($kind, [RepairDesk::GBP_DESCRIPTION, RepairDesk::GBP_FIELDS], true) => 'gbp',
            ($row['channel'] ?? '') === 'meta' => 'meta',
            default => 'web',
        };
    }

    /**
     * @return array{goal: array<string, mixed>, target: ?BrandTarget, gain: ?float, channels: array<string, array{goal: ?int, mtd: int, label: string}>}
     */
    public function goal(Brand $brand): array
    {
        $target = SchemaState::hasTable('brand_targets') ? BrandTarget::query()->where('brand_id', $brand->id)->first() : null;
        $progress = BrandTargets::progress($target);
        $channels = [];
        foreach ($progress['channels'] as $channel => $values) {
            $channels[$channel] = $values + ['label' => BrandTargets::CHANNELS[$channel]];
        }
        $gain = $this->packages($brand)->sum(fn (array $p): float => (float) ($this->impact->forPackage($p) ?? 0));

        return ['goal' => $progress, 'target' => $target, 'gain' => $gain > 0 ? round($gain, 1) : null, 'channels' => $channels];
    }

    /**
     * One card per channel. `kpis` are BrandOverviewReader::kpis() of the page's period.
     *
     * @param  list<array<string, mixed>>  $kpis
     * @return list<array{key: string, title: string, state: string, state_label: string, line: string, metrics: list<array{label: string, value: string, delta: ?int}>, findings: list<array{title: string, line: string, count: int, url: ?string}>, ready: int, manual: int, desk_url: string}>
     */
    public function cards(Brand $brand, array $kpis): array
    {
        $health = $this->health($brand);
        $assets = DigitalAsset::query()->where('brand_id', $brand->id)->whereIn('type', array_values(PortfolioReader::CHANNEL_TYPES))
            ->orderBy('id')->get(['id', 'type', 'name', 'domain', 'status']);
        $kpi = collect($kpis)->keyBy('key');
        $rows = $this->rows($brand);
        $cards = [];
        foreach (self::CARDS as $key => [$title, $deskRoute]) {
            $channelRows = $rows->filter(fn (array $r): bool => self::channelOf($r) === $key);
            $state = $health[$key] ?? 'none';
            $cards[] = [
                'key' => $key,
                'title' => $title,
                'state' => $state,
                'state_label' => self::STATES[$state],
                'line' => $this->assetLine($key, $assets->where('type', PortfolioReader::CHANNEL_TYPES[$key])->values()),
                'metrics' => $this->metrics($key, $kpi, $brand),
                'findings' => $this->findings($brand, $key),
                'ready' => $channelRows->filter(fn (array $r): bool => RepairDesk::lane($r) === RepairDesk::LANE_READY && ($r['risk'] ?? '') !== RepairDesk::HIGH)->count(),
                'manual' => $channelRows->filter(fn (array $r): bool => RepairDesk::lane($r) === RepairDesk::LANE_MANUAL)->count(),
                'desk_url' => route($deskRoute, ['marka' => $brand->id]),
            ];
        }

        return $cards;
    }

    /**
     * The rows "Hepsini çöz" of a card sends: the channel's ready rows (high-risk ones stay for a single approval).
     *
     * @return list<int>
     */
    public function readyIds(Brand $brand, string $channel): array
    {
        return $this->rows($brand)
            ->filter(fn (array $r): bool => self::channelOf($r) === $channel && RepairDesk::lane($r) === RepairDesk::LANE_READY && ($r['risk'] ?? '') !== RepairDesk::HIGH)
            ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * The channel's open work that brings the most (OpenWork packages: the same kind of work is one line), with where
     * to act; desk rows and the checks' own suggestions (profile standards, Meta, Ads) alike.
     *
     * @return list<array{title: string, line: string, count: int, url: ?string}>
     */
    private function findings(Brand $brand, string $channel): array
    {
        try {
            $work = $this->overview->openWork($brand, self::WORK_CHANNELS[$channel], self::FINDINGS);
        } catch (Throwable $error) {
            report($error);

            return [];
        }

        return array_map(fn (array $i): array => ['title' => $i['title'], 'line' => $i['reason'], 'count' => $i['count'], 'url' => $i['url']], $work['items']);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(Brand $brand): Collection
    {
        if ($this->rowsBrand !== (int) $brand->id) {
            $this->rowsBrand = (int) $brand->id;
            try {
                $this->rows = $this->desk->rows((int) $brand->id);
            } catch (Throwable $error) {
                report($error);
                $this->rows = collect();
            }
        }

        return $this->rows;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function packages(Brand $brand): Collection
    {
        $rows = $this->rows($brand)->reject(fn (array $r): bool => RepairDesk::lane($r) === RepairDesk::LANE_MANUAL)->values();
        $channels = $rows->groupBy(fn (array $r): string => RepairDesk::packageKey($r))->map(fn (Collection $g): string => (string) ($g->first()['channel'] ?? ''));

        return $this->desk->packages($rows)->map(fn (array $p): array => $p + ['channel' => $channels[$p['key']] ?? '']);
    }

    /** @return array<string, string> */
    private function health(Brand $brand): array
    {
        try {
            return $this->portfolio->health([(int) $brand->id])[(int) $brand->id] ?? [];
        } catch (Throwable $error) {
            report($error);

            return [];
        }
    }

    /** @param  Collection<int, DigitalAsset>  $assets */
    private function assetLine(string $channel, Collection $assets): string
    {
        if ($assets->isEmpty()) {
            return 'Bu kanalda bağlı hesap yok.';
        }

        return match ($channel) {
            'web' => $assets->map(fn (DigitalAsset $a): string => (string) ($a->domain ?: $a->name))->implode(', '),
            'gbp' => $assets->count().' profil',
            default => $assets->count().' hesap',
        };
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $kpi
     * @return list<array{label: string, value: string, delta: ?int}>
     */
    private function metrics(string $channel, Collection $kpi, Brand $brand): array
    {
        $take = function (string $key, string $label) use ($kpi): ?array {
            $card = $kpi->get($key);

            return $card !== null && $card['value'] !== null ? ['label' => $label, 'value' => (string) $card['value'], 'delta' => $card['delta']] : null;
        };
        $out = match ($channel) {
            'web' => [$take('organic_clicks', 'Tık'), $take('web_conversions', 'Talep'), $this->siteScore($brand)],
            'google_ads' => [$take('ad_spend', 'Harcama'), $take('ad_conversions', 'Dönüşüm')],
            'gbp' => [$take('gbp_actions', 'Etkileşim'), $this->unanswered($brand)],
            default => [],
        };

        return array_values(array_filter($out));
    }

    /** @return array{label: string, value: string, delta: ?int}|null */
    private function siteScore(Brand $brand): ?array
    {
        if (! SchemaState::hasTable('site_scores')) {
            return null;
        }
        $siteIds = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->pluck('id');
        $score = SiteScore::query()->whereIn('digital_asset_id', $siteIds)->orderByDesc('id')->value('score');

        return $score !== null ? ['label' => 'Site skoru', 'value' => (string) $score, 'delta' => null] : null;
    }

    /** @return array{label: string, value: string, delta: ?int}|null */
    private function unanswered(Brand $brand): ?array
    {
        if (! SchemaState::hasTable('gbp_reviews')) {
            return null;
        }
        $profiles = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'google_business_profile')->pluck('id');
        if ($profiles->isEmpty()) {
            return null;
        }
        $count = GbpReview::query()->whereIn('digital_asset_id', $profiles)->whereNull('review_reply')->count();

        return ['label' => 'Yanıtsız yorum', 'value' => number_format($count, 0, ',', '.'), 'delta' => null];
    }
}
