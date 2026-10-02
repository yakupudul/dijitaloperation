<?php

namespace App\Services\MetaAds\Support;

enum MetaAdsBindingMode: string
{
    case DemoCatalog = 'demo_catalog';
    case RealBound = 'real_bound';
    case NotConnected = 'not_connected';
    case ActionRequired = 'action_required';
}
