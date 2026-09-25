<?php

namespace App\Services\Brain;

/** Turkish labels shared by the Brain screens. */
final class BrainLabels
{
    public const array CHANNELS = ['website' => 'Web sitesi', 'google_ads' => 'Google Ads', 'meta_ads' => 'Meta', 'google_business_profile' => 'İşletme Profili'];

    /** How sure the Brain is about the method behind a recommendation. */
    public const array BASIS = ['rule' => 'Kural', 'observational' => 'Başarılı markalarda gözlendi', 'validated' => 'Etkisi kanıtlandı'];

    public static function channel(?string $channel): string
    {
        return self::CHANNELS[$channel] ?? (string) $channel;
    }

    public static function basis(?string $basis): string
    {
        return self::BASIS[$basis] ?? (string) $basis;
    }
}
