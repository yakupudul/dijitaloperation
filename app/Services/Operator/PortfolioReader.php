<?php

namespace App\Services\Operator;

use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\BrandTarget;
use App\Models\DigitalAsset;
use App\Services\Goals\BrandTargets;
use App\Services\Repair\RepairDesk;
use App\Support\SchemaState;
use Throwable;

/**
 * Portföy (Aşama 5): every brand on one list — its goal and where this month stands against it, the health of its four
 * channels, eight weeks of organic clicks and the decisions waiting. By default the brand that needs attention most is
 * on top: behind its goal, a channel losing money or data, decisions piling up.
 */
final class PortfolioReader
{
    /** Channel => digital asset type. */
    public const array CHANNEL_TYPES = ['web' => 'website', 'google_ads' => 'google_ads', 'meta' => 'meta_ads', 'gbp' => 'google_business_profile'];

    public function __construct(private readonly TodayCockpitReader $cockpit, private readonly RepairDesk $desk) {}

    /**
     * @return list<array{id: int, name: string, logo: ?string, progress: array<string, mixed>, target: ?BrandTarget, channels: array<string, string>, clicks: list<int>, waiting: int, attention: int, reasons: list<string>}>
     */
    public function rows(string $sort = 'dikkat'): array
    {
        $brands = Brand::query()->operational()->orderBy('name')->get(['id', 'name', 'logo_url']);
        $targets = SchemaState::hasTable('brand_targets') ? BrandTarget::query()->whereIn('brand_id', $brands->modelKeys())->get()->keyBy('brand_id') : collect();
        $clicks = collect($this->cockpit->brands())->keyBy('id');
        $waiting = [];
        try {
            $waiting = array_map(fn (array $b): int => (int) $b['decide'], $this->desk->summary()['brands']);
        } catch (Throwable $error) {
            report($error);
        }
        $health = $this->health($brands->modelKeys());

        $rows = $brands->map(function (Brand $brand) use ($targets, $clicks, $waiting, $health): array {
            $target = $targets->get($brand->id);
            $progress = BrandTargets::progress($target);
            $channels = $health[$brand->id] ?? array_fill_keys(array_keys(self::CHANNEL_TYPES), 'none');
            $reasons = [];
            $score = 0;
            if ($progress['state'] === 'behind') {
                $score += min(60, abs((int) $progress['gap_pct']));
                $reasons[] = 'amacın %'.abs((int) $progress['gap_pct']).' gerisinde';
            }
            $bad = array_keys(array_filter($channels, fn (string $s): bool => $s === 'bad'));
            if ($bad !== []) {
                $score += 40 * count($bad);
                $reasons[] = implode(', ', array_map(fn (string $c): string => BrandTargets::CHANNELS[$c], $bad)).' kayıpta';
            }
            $score += 10 * count(array_filter($channels, fn (string $s): bool => $s === 'look'));
            $wait = (int) ($waiting[$brand->id] ?? 0);
            if ($wait > 0) {
                $score += min(20, intdiv($wait, 25) + 1);
            }
            if ($progress['state'] === 'no_goal') {
                $score += 5;
                $reasons[] = $target?->status === BrandTarget::UNMEASURED ? 'talep ölçülmüyor' : 'amaç onaylanmadı';
            }

            return [
                'id' => (int) $brand->id,
                'name' => (string) $brand->name,
                'logo' => $brand->logo_url ?: null,
                'progress' => $progress,
                'target' => $target,
                'channels' => $channels,
                'clicks' => (array) ($clicks[$brand->id]['clicks'] ?? []),
                'waiting' => $wait,
                'attention' => $score,
                'reasons' => $reasons,
            ];
        });

        return $rows->sortBy(match ($sort) {
            'ad' => [['name', 'asc']],
            'amac' => [fn (array $a, array $b): int => ($a['progress']['gap_pct'] ?? 999) <=> ($b['progress']['gap_pct'] ?? 999)],
            default => [['attention', 'desc'], ['name', 'asc']],
        })->values()->all();
    }

    /**
     * Each brand's channel health from its assets and active alarms: none (no asset), ok, look (an alarm to look at),
     * bad (money or data being lost).
     *
     * @param  list<int>  $brandIds
     * @return array<int, array<string, string>>
     */
    private function health(array $brandIds): array
    {
        $out = [];
        foreach ($brandIds as $id) {
            $out[$id] = array_fill_keys(array_keys(self::CHANNEL_TYPES), 'none');
        }
        $channelOf = array_flip(self::CHANNEL_TYPES);
        try {
            $assets = DigitalAsset::query()->operational()->whereIn('digital_assets.brand_id', $brandIds)->whereIn('type', array_values(self::CHANNEL_TYPES))
                ->get(['digital_assets.id', 'digital_assets.brand_id', 'type']);
            foreach ($assets as $asset) {
                $out[(int) $asset->brand_id][$channelOf[$asset->type]] = 'ok';
            }
            $alerts = AssetAlert::query()->active()->whereIn('digital_asset_id', $assets->modelKeys())
                ->whereIn('severity', ['critical', 'high'])->get(['digital_asset_id', 'kind', 'severity']);
            $assetById = $assets->keyBy('id');
            foreach ($alerts as $alert) {
                $asset = $assetById[$alert->digital_asset_id] ?? null;
                if ($asset === null) {
                    continue;
                }
                $channel = $channelOf[$asset->type];
                $state = in_array($alert->kind, TodayCockpitReader::LOSS_KINDS, true) ? 'bad' : 'look';
                if ($out[(int) $asset->brand_id][$channel] !== 'bad') {
                    $out[(int) $asset->brand_id][$channel] = $state;
                }
            }
        } catch (Throwable $error) {
            report($error);
        }

        return $out;
    }
}
