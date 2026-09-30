<?php

namespace App\Services\Collection\Providers\GoogleAds;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign language targeting (campaign_criterion type LANGUAGE), kept on the campaign snapshot as
 * `language_codes`: "all" = no language criterion (every language), else a comma list of ISO codes; a language
 * constant outside the map stays as its numeric id. The column is outside the dataset contract, so snapshot upserts
 * never overwrite it.
 */
final class GoogleAdsCampaignLanguages
{
    /** Google Ads language constant id => ISO 639-1 code. */
    public const array CODES = [
        '1000' => 'en', '1001' => 'de', '1002' => 'fr', '1003' => 'es', '1004' => 'it', '1010' => 'nl', '1014' => 'pt',
        '1019' => 'ar', '1027' => 'he', '1030' => 'pl', '1031' => 'ru', '1032' => 'ro', '1036' => 'uk', '1037' => 'tr',
        '1064' => 'fa',
    ];

    /**
     * @param  list<array<string, mixed>>  $rows  GAQL rows (campaign.id, campaign_criterion.language.language_constant)
     * @return int campaign rows updated
     */
    public static function store(int $resourceId, string $customerId, array $rows): int
    {
        if (! Schema::hasColumn('google_ads_campaign_snapshot', 'language_codes')) {
            return 0;
        }
        $byCampaign = [];
        foreach ($rows as $row) {
            $campaign = (string) (data_get($row, 'campaign.id') ?? '');
            $constant = (string) (data_get($row, 'campaignCriterion.language.languageConstant') ?? data_get($row, 'campaign_criterion.language.language_constant') ?? '');
            if ($campaign === '' || $constant === '') {
                continue;
            }
            $id = (string) preg_replace('/\D/', '', $constant);
            $byCampaign[$campaign][] = self::CODES[$id] ?? $id;
        }
        $base = DB::table('google_ads_campaign_snapshot')->where('external_resource_id', $resourceId)->where('customer_id', $customerId);
        $updated = (clone $base)->update(['language_codes' => 'all']);
        foreach ($byCampaign as $campaign => $codes) {
            $codes = array_values(array_unique($codes));
            sort($codes);
            (clone $base)->where('campaign_id', (string) $campaign)->update(['language_codes' => mb_substr(implode(',', $codes), 0, 120)]);
        }

        return $updated;
    }
}
