<?php

namespace App\Services\GoogleAds\Support;

enum GoogleAdsBindingMode: string
{
    case DemoCatalog = 'demo_catalog';
    case RealBound = 'real_bound';
    case NotConnected = 'not_connected';
    case ActionRequired = 'action_required';
}
