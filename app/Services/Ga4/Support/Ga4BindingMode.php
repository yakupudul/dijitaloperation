<?php

namespace App\Services\Ga4\Support;

enum Ga4BindingMode: string
{
    /** Demo catalog asset id (e.g. `ga4-atlas`) — Demo Mode fixtures only. */
    case DemoCatalog = 'DEMO_CATALOG';

    /** Numeric Digital Asset with an active `ga4` CoreAssetBinding ready to read. */
    case RealBound = 'REAL_BOUND';

    /** Numeric Digital Asset with no active `ga4` CoreAssetBinding at all. */
    case NotConnected = 'NOT_CONNECTED';

    /** Binding exists but is not usable yet (resource/integration/auth not ready). */
    case ActionRequired = 'ACTION_REQUIRED';
}
