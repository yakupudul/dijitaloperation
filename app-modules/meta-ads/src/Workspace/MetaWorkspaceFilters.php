<?php

namespace MoxDop\MetaAds\Workspace;

use App\Support\Integrations\ComparisonPeriod;
use Illuminate\Support\Facades\Session;

/**
 * Session-scoped Meta Expert Workspace filter bar state (per Digital Asset).
 */
final class MetaWorkspaceFilters
{
    public const string DELIVERY_DELIVERED = 'delivered';

    public const string DELIVERY_ACTIVE = 'active_now';

    public const string DELIVERY_PAUSED = 'paused';

    public const string DELIVERY_ALL = 'all';

    /**
     * @return array{
     *     period_preset: string,
     *     period_start: ?string,
     *     period_end: ?string,
     *     compare: bool,
     *     delivery: string,
     *     objective: string,
     *     search: string,
     *     trend_metric: string
     * }
     */
    public static function get(int $assetId): array
    {
        $stored = Session::get(self::key($assetId), []);

        return [
            'period_preset' => (string) ($stored['period_preset'] ?? ComparisonPeriod::PRESET_LAST_30),
            'period_start' => isset($stored['period_start']) ? (string) $stored['period_start'] : null,
            'period_end' => isset($stored['period_end']) ? (string) $stored['period_end'] : null,
            'compare' => (bool) ($stored['compare'] ?? true),
            'delivery' => (string) ($stored['delivery'] ?? self::DELIVERY_DELIVERED),
            'objective' => (string) ($stored['objective'] ?? ''),
            'search' => (string) ($stored['search'] ?? ''),
            'trend_metric' => (string) ($stored['trend_metric'] ?? 'spend'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function put(int $assetId, array $filters): void
    {
        Session::put(self::key($assetId), array_merge(self::get($assetId), $filters));
    }

    private static function key(int $assetId): string
    {
        return 'meta_workspace_filters.'.$assetId;
    }
}
