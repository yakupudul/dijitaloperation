<?php

namespace App\Enums;

enum OfferingNameProvenance: string
{
    case PrimaryOperator = 'primary_operator';
    case ConfirmedAlias = 'confirmed_alias';
    case LegacyBic = 'legacy_bic';
    case FormerPrimary = 'former_primary';
}
