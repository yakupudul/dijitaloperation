<?php

namespace App\Services\Advisor;

use App\Enums\SeoTaskType;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\SeoPlan;
use App\Models\SeoTask;
use Illuminate\Support\Collection;

/**
 * One work list across SEO Görevleri and every advisor channel: the "what matters this week" view behind
 * the dashboard box and the brand page. Both engines score on the same scale (severity base 100–1000 plus
 * impact), so items are merged by priority; at most $perBrand items per brand keep the list balanced.
 */
final class AdvisorWorkQueue
{
    public function __construct(private readonly AdvisorChannels $channels) {}

    /**
     * @return list<array{source: string, id: int, channel: string, brand: ?string, brand_id: ?int, asset: ?string, title: string, reason: string, severity: string, severity_label: string, severity_color: string, priority: float, impact: ?string, url: ?string}>
     */
    public function top(int $limit = 5, ?int $brandId = null, int $perBrand = 2): array
    {
        $rows = collect()
            ->merge($this->seoTasks($brandId, $limit * 6))
            ->merge($this->advisorItems($brandId, $limit * 6))
            ->sortByDesc('priority')
            ->values();

        $out = [];
        $perBrandCount = [];
        foreach ($rows as $row) {
            $key = (string) ($row['brand_id'] ?? 0);
            if ($brandId === null && ($perBrandCount[$key] ?? 0) >= $perBrand) {
                continue;
            }
            $perBrandCount[$key] = ($perBrandCount[$key] ?? 0) + 1;
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * One status line per connected channel of a brand: open / urgent counts and the latest run.
     *
     * @return list<array{channel: string, asset: string, url: ?string, open: int, urgent: int, last_run_at: mixed, summary: ?string, running: bool}>
     */
    public function brandChannels(Brand $brand): array
    {
        $assets = DigitalAsset::query()->where('brand_id', $brand->id)->where('status', 'active')->orderBy('id')->get();
        $lines = [];
        foreach ($assets as $asset) {
            if ($asset->type === 'website') {
                $open = SeoTask::query()->open()->where('digital_asset_id', $asset->id)->where('type', '!=', SeoTaskType::Question->value);
                $plan = SeoPlan::query()->where('digital_asset_id', $asset->id)->orderByDesc('id')->first();
                $done = SeoPlan::query()->where('digital_asset_id', $asset->id)->where('status', SeoPlan::STATUS_COMPLETED)->orderByDesc('id')->first();
                $lines[] = [
                    'channel' => 'Web / SEO',
                    'asset' => $asset->domain ?: $asset->name,
                    'url' => route('operator.website', ['assetId' => $asset->id, 'tab' => 'seo']),
                    'open' => (clone $open)->count(),
                    'urgent' => (clone $open)->whereIn('severity', ['critical', 'high'])->count(),
                    'last_run_at' => $done?->completed_at,
                    'summary' => $done?->summary_text,
                    'running' => in_array($plan?->status, [SeoPlan::STATUS_QUEUED, SeoPlan::STATUS_RUNNING], true),
                ];
            }
            $channel = $this->channels->forAssetType((string) $asset->type);
            if ($channel === null) {
                continue;
            }
            $open = AdvisorItem::query()->open()->where('digital_asset_id', $asset->id);
            $plan = AdvisorPlan::query()->where('digital_asset_id', $asset->id)->orderByDesc('id')->first();
            $done = AdvisorPlan::query()->where('digital_asset_id', $asset->id)->where('status', AdvisorPlan::STATUS_COMPLETED)->orderByDesc('id')->first();
            if ($asset->type === 'website' && $plan === null) {
                continue; // cross-channel line only once it has run
            }
            $lines[] = [
                'channel' => $channel->label(),
                'asset' => $asset->name,
                'url' => $channel->assetUrl($asset->id),
                'open' => (clone $open)->count(),
                'urgent' => (clone $open)->whereIn('severity', ['critical', 'high'])->count(),
                'last_run_at' => $done?->completed_at,
                'summary' => $done?->summary_text,
                'running' => in_array($plan?->status, [AdvisorPlan::STATUS_QUEUED, AdvisorPlan::STATUS_RUNNING], true),
            ];
        }

        return $lines;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function seoTasks(?int $brandId, int $limit): Collection
    {
        return SeoTask::query()
            ->with(['brand', 'digitalAsset'])
            ->open()
            ->where('type', '!=', SeoTaskType::Question->value)
            ->when($brandId !== null, fn ($query) => $query->where('brand_id', $brandId))
            ->orderByDesc('priority_score')
            ->limit($limit)
            ->get()
            ->map(fn (SeoTask $task): array => [
                'source' => 'seo',
                'id' => $task->id,
                'channel' => 'Web / SEO · '.$task->type->label(),
                'brand' => $task->brand?->name,
                'brand_id' => $task->brand_id,
                'asset' => $task->digitalAsset?->domain ?: $task->digitalAsset?->name,
                'title' => (string) $task->title,
                'reason' => (string) $task->reason,
                'severity' => (string) $task->severity,
                'severity_label' => $task->severityLabel(),
                'severity_color' => $task->severityColor(),
                'priority' => (float) $task->priority_score,
                'impact' => $task->estimated_extra_clicks ? '+'.number_format((float) $task->estimated_extra_clicks, 0, ',', '.').' tık / 90 gün' : null,
                'url' => route('operator.website', ['assetId' => $task->digital_asset_id, 'tab' => 'seo']),
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function advisorItems(?int $brandId, int $limit): Collection
    {
        $channels = $this->channels->all();

        return AdvisorItem::query()
            ->with(['brand', 'digitalAsset'])
            ->open()
            ->when($brandId !== null, fn ($query) => $query->where('brand_id', $brandId))
            ->orderByDesc('priority_score')
            ->limit($limit)
            ->get()
            ->map(fn (AdvisorItem $item): array => [
                'source' => 'advisor',
                'id' => $item->id,
                'channel' => ($channels[$item->channel] ?? null)?->label() ?? $item->channel,
                'brand' => $item->brand?->name,
                'brand_id' => $item->brand_id,
                'asset' => $item->digitalAsset?->name,
                'title' => (string) $item->title,
                'reason' => (string) $item->reason,
                'severity' => (string) $item->severity,
                'severity_label' => $item->severityLabel(),
                'severity_color' => $item->severityColor(),
                'priority' => (float) $item->priority_score,
                'impact' => $item->impact_label,
                'url' => isset($channels[$item->channel]) ? $channels[$item->channel]->assetUrl($item->digital_asset_id) : null,
            ]);
    }
}
