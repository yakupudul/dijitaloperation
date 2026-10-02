<?php

namespace App\Support\GoogleAds;

/**
 * Google Ads age range and gender criterion ids are fixed by Google (not account specific), so they are labelled
 * here instead of being looked up.
 */
final class DemographicLabels
{
    private const AGE = [
        '503001' => '18–24', '503002' => '25–34', '503003' => '35–44', '503004' => '45–54',
        '503005' => '55–64', '503006' => '65+', '503999' => 'Belirsiz yaş',
    ];

    private const GENDER = ['10' => 'Erkek', '11' => 'Kadın', '20' => 'Belirsiz cinsiyet'];

    public static function age(?string $criterionId): string
    {
        return self::AGE[(string) $criterionId] ?? 'Yaş #'.$criterionId;
    }

    public static function gender(?string $criterionId): string
    {
        return self::GENDER[(string) $criterionId] ?? 'Cinsiyet #'.$criterionId;
    }
}
