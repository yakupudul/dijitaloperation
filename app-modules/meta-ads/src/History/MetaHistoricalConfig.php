<?php

namespace MoxDop\MetaAds\History;

use App\Support\Integrations\Meta\MetaApiConfig;

/**
 * Central constants for the Meta Ads historical store.
 * Marketing API v26.0 provider-available history for aggregate daily facts is up to
 * ~37 months (error 3018 beyond that) — see docs/implementation/META_FOUNDATION_PASS_AUDIT.md.
 */
final class MetaHistoricalConfig
{
    /** Provider aggregate retention window, in months, for daily Insights history. */
    public const int HISTORY_MONTHS = 37;

    /**
     * Trailing days re-fetched on every import to absorb provider-side attribution
     * corrections that land after the original day's data was first collected.
     * Configurable via `moxdop.meta.history_correction_window_days`.
     */
    public const int CORRECTION_WINDOW_DAYS = 7;

    /** Insights `time_range` request window size, in days, per historical fetch. */
    public const int CHUNK_DAYS = 30;

    /** Historical backfills paginate deeper than a live bound-collect request. */
    public const int HISTORY_MAX_PAGES = 50;

    /** Attempts (including the first) before a retryable Meta error is surfaced. */
    public const int MAX_RETRY = 3;

    /**
     * Insights fields requested for historical daily facts + actions.
     * Mirrors MoxDop\MetaAds\Collection\MetaAdsBoundCollector::INSIGHT_FIELDS.
     */
    public const string INSIGHT_FIELDS = 'account_id,account_name,account_currency,campaign_id,campaign_name,adset_id,adset_name,ad_id,ad_name,impressions,reach,frequency,clicks,inline_link_clicks,ctr,cpc,cpm,spend,actions,action_values,attribution_setting,outbound_clicks,inline_link_click_ctr,date_start,date_stop';

    public static function correctionWindowDays(): int
    {
        return max(0, (int) config('moxdop.meta.history_correction_window_days', self::CORRECTION_WINDOW_DAYS));
    }

    /** Never less than the live-collection pagination cap. */
    public static function historyMaxPages(): int
    {
        return max(MetaApiConfig::maxPaginationPages(), self::HISTORY_MAX_PAGES);
    }
}
