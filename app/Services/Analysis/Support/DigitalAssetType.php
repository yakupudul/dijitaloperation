<?php

namespace App\Services\Analysis\Support;

enum DigitalAssetType: string
{
    case Website = 'website';
    case GoogleAds = 'google_ads';
    case MetaAds = 'meta_ads';
    case Unsupported = 'unsupported';
}
