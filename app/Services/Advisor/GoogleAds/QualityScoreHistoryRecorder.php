<?php

namespace App\Services\Advisor\GoogleAds;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps a daily copy of each keyword's Quality Score and its three components. The keyword snapshot only holds
 * the latest value, so without this copy a drop is invisible. One row per keyword per collection day (upsert).
 */
final class QualityScoreHistoryRecorder
{
    /** @return int rows written */
    public function record(): int
    {
        if (! Schema::hasTable('google_ads_keyword_snapshot') || ! Schema::hasTable('google_ads_quality_score_history')) {
            return 0;
        }
        $written = 0;
        DB::table('google_ads_keyword_snapshot')
            ->select(['id', 'digital_asset_id', 'customer_id', 'ad_group_id', 'criterion_id', 'last_collected_at', 'metadata'])
            ->orderBy('id')
            ->chunk(1000, function ($rows) use (&$written): void {
                $batch = [];
                foreach ($rows as $row) {
                    $meta = GoogleAdsAdvisorInputCollector::decode($row->metadata);
                    if (! is_numeric($meta['quality_score'] ?? null) || (string) $row->ad_group_id === '') {
                        continue;
                    }
                    $observed = CarbonImmutable::parse((string) $row->last_collected_at)->setTimezone((string) config('app.timezone'))->toDateString();
                    $key = $row->customer_id."\0".$row->ad_group_id."\0".$row->criterion_id."\0".$observed;
                    $batch[$key] = [
                        'digital_asset_id' => $row->digital_asset_id,
                        'customer_id' => (string) $row->customer_id,
                        'ad_group_id' => (string) $row->ad_group_id,
                        'criterion_id' => (string) $row->criterion_id,
                        'keyword_text' => mb_substr((string) ($meta['keyword_text'] ?? ''), 0, 255) ?: null,
                        'observed_on' => $observed,
                        'quality_score' => (int) $meta['quality_score'],
                        'ad_relevance' => self::component($meta['ad_relevance'] ?? null),
                        'landing_page_experience' => self::component($meta['landing_page_experience'] ?? null),
                        'expected_ctr' => self::component($meta['expected_ctr'] ?? null),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                if ($batch !== []) {
                    DB::table('google_ads_quality_score_history')->upsert(
                        array_values($batch),
                        ['customer_id', 'ad_group_id', 'criterion_id', 'observed_on'],
                        ['digital_asset_id', 'keyword_text', 'quality_score', 'ad_relevance', 'landing_page_experience', 'expected_ctr', 'updated_at'],
                    );
                    $written += count($batch);
                }
            });

        $keep = (int) config('moxdop-advisor.google_ads.quality_history.retention_days', 400);
        DB::table('google_ads_quality_score_history')->where('observed_on', '<', now()->subDays($keep)->toDateString())->delete();

        return $written;
    }

    private static function component(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 30) : null;
    }
}
