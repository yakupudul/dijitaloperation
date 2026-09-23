<?php

namespace App\Enums;

enum AdvisorCategory: string
{
    case Waste = 'waste';
    case Growth = 'growth';
    case Measurement = 'measurement';
    case Landing = 'landing';
    case Ads = 'ads';
    case Quality = 'quality';
    case Change = 'change';
    case Audience = 'audience';
    case Profile = 'profile';

    public function label(): string
    {
        return match ($this) {
            self::Waste => 'İsraf',
            self::Growth => 'Büyüme',
            self::Measurement => 'Ölçüm',
            self::Landing => 'Açılış sayfası',
            self::Ads => 'Reklam & varlık',
            self::Quality => 'Kalite puanı',
            self::Change => 'Değişiklik etkisi',
            self::Audience => 'Kitle & teslimat',
            self::Profile => 'Profil',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Waste => 'error',
            self::Growth => 'success',
            self::Measurement => 'warning',
            self::Landing => 'info',
            self::Ads, self::Quality => 'primary',
            self::Change => 'light',
            self::Audience => 'info',
            self::Profile => 'warning',
        };
    }
}
