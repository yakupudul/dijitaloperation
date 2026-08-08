<?php

namespace App\Contracts\Integrations;

use App\Models\CoreAssetBinding;
use App\Models\Run;

/**
 * Module-owned Binding collector. Credentials always come from the Integration
 * behind the bound External Resource — never from CoreConnection.
 */
interface CollectsBoundProviderData
{
    public function capability(): string;

    public function moduleId(): string;

    public function collect(CoreAssetBinding $binding): Run;
}
