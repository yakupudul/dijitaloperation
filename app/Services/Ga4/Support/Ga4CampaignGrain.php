<?php

namespace App\Services\Ga4\Support;

use Illuminate\Database\Query\Builder;

/**
 * `ga4_campaign_daily` grain guard. Rows collected before the grain was expanded with
 * sessionCampaignId/sessionSource/sessionMedium carry NULLs in those columns; once a day has
 * been re-collected at the expanded grain, the legacy rows for that resource/property/day
 * describe the same sessions and must not be summed alongside them.
 */
final class Ga4CampaignGrain
{
    public static function excludeSupersededLegacyRows(Builder $query): Builder
    {
        return $query->where(function (Builder $outer): void {
            $outer->whereNotNull('ga4_campaign_daily.sessionCampaignId')
                ->orWhereNotExists(function (Builder $expanded): void {
                    $expanded->selectRaw('1')
                        ->from('ga4_campaign_daily as expanded_grain')
                        ->whereColumn('expanded_grain.external_resource_id', 'ga4_campaign_daily.external_resource_id')
                        ->whereColumn('expanded_grain.property_id', 'ga4_campaign_daily.property_id')
                        ->whereColumn('expanded_grain.reporting_date', 'ga4_campaign_daily.reporting_date')
                        ->whereNotNull('expanded_grain.sessionCampaignId');
                });
        });
    }
}
