<?php

namespace App\Enums;

enum OfferingNameKind: string
{
    case Primary = 'primary';
    case Alias = 'alias';
    case FormerPrimary = 'former_primary';
    case LegacyBic = 'legacy_bic';
}
