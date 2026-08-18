<?php

namespace App\Enums;

/**
 * Task execution scopes a Playbook revision may apply to (Prompt 43).
 */
enum PlaybookExecutionScopeKind: string
{
    case Customer = 'customer';
    case Brand = 'brand';
    case DigitalAsset = 'digital_asset';
}
