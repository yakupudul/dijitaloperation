<?php

namespace App\Services\Integrations\Google;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforces Google Business Profile API content-storage retention.
 * Provider content (reviews, media, posts, profile snapshots) is refreshed on collection and never
 * kept as an indefinite archive. The business's own metrics — daily performance and monthly search
 * keywords — are the agency's long-term query/keyword record and are deliberately not purged here
 * (owner decision 2026-09: keyword and query data is never deleted).
 */
final class GoogleBusinessProfileRetentionService
{
    /** @var array<string, string> */
    private const TABLE_TIMESTAMP = [
        'gbp_location_snapshots' => 'captured_at',
        'gbp_reviews' => 'collected_at',
        'gbp_media' => 'collected_at',
        'gbp_posts' => 'collected_at',
        'gbp_attribute_snapshots' => 'captured_at',
        'gbp_service_snapshots' => 'captured_at',
        'gbp_place_action_links' => 'collected_at',
        'gbp_verification_snapshots' => 'captured_at',
    ];

    public function purgeExpired(): int
    {
        $days = max(1, min(30, (int) config('moxdop-gbp-collector.content_retention_days', 30)));
        $cutoff = now()->subDays($days);
        $deleted = 0;

        foreach (self::TABLE_TIMESTAMP as $table => $column) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $deleted += DB::table($table)->where($column, '<', $cutoff)->delete();
        }

        return $deleted;
    }
}
