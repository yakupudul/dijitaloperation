<?php

namespace App\Enums;

/**
 * Channel uses audited DigitalAsset type keys — not free-text marketing labels.
 * Channel ≠ Provider; DigitalAsset relation is stored separately when applicable.
 */
enum BrandExperienceChannel: string
{
    case Website = 'website';
    case GoogleBusinessProfile = 'google_business_profile';
    case GoogleAds = 'google_ads';
    case MetaAds = 'meta_ads';
    case Instagram = 'instagram';
    case Ga4 = 'ga4';
    case Gsc = 'gsc';

    public static function tryFromDigitalAssetType(?string $type): ?self
    {
        if ($type === null || $type === '') {
            return null;
        }

        return self::tryFrom($type);
    }
}
