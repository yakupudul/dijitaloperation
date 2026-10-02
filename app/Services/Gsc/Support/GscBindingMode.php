<?php

namespace App\Services\Gsc\Support;

enum GscBindingMode: string
{
    /** Demo catalog asset id (e.g. `gsc-atlas`) — Demo Mode fixtures only. */
    case DemoCatalog = 'DEMO_CATALOG';

    /** Numeric Digital Asset with an active `search_console` CoreAssetBinding ready to read. */
    case RealBound = 'REAL_BOUND';

    /** Numeric Digital Asset with no active `search_console` CoreAssetBinding at all. */
    case NotConnected = 'NOT_CONNECTED';

    /** Binding exists but is not usable yet (resource/integration/auth not ready). */
    case ActionRequired = 'ACTION_REQUIRED';
}
