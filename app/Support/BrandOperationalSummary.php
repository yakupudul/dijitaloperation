<?php

namespace App\Support;

use App\Models\Brand;
use App\Models\CoreConnection;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Task;

/**
 * Deterministic Brand workspace metrics from live domain tables (no placeholders).
 */
final class BrandOperationalSummary
{
    /**
     * @return array{
     *     digital_assets: int,
     *     healthy_connected_assets: int,
     *     open_findings: int,
     *     open_recommendations: int,
     *     open_tasks: int
     * }
     */
    public static function for(Brand $brand): array
    {
        $assetIds = $brand->digitalAssets()->pluck('id');

        $healthyConnectedAssets = 0;
        if ($assetIds->isNotEmpty()) {
            $healthyConnectedAssets = (int) CoreConnection::query()
                ->select('digital_asset_id')
                ->whereIn('digital_asset_id', $assetIds)
                ->where('enabled', true)
                ->where(function ($query): void {
                    $query->whereNull('last_error')
                        ->orWhere('last_error', '');
                })
                ->groupBy('digital_asset_id')
                ->get()
                ->count();
        }

        $openFindings = $assetIds->isEmpty()
            ? 0
            : Finding::query()
                ->whereIn('digital_asset_id', $assetIds)
                ->where('status', 'open')
                ->count();

        $openRecommendations = $assetIds->isEmpty()
            ? 0
            : Recommendation::query()
                ->whereIn('digital_asset_id', $assetIds)
                ->where('status', 'open')
                ->count();

        $openTasks = Task::query()
            ->where('brand_id', $brand->id)
            ->whereIn('status', ['open', 'in_progress', 'blocked'])
            ->count();

        return [
            'digital_assets' => $assetIds->count(),
            'healthy_connected_assets' => $healthyConnectedAssets,
            'open_findings' => $openFindings,
            'open_recommendations' => $openRecommendations,
            'open_tasks' => $openTasks,
        ];
    }
}
