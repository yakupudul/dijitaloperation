<?php

namespace App\Enums;

/**
 * Prompt 52 never establishes causality. Only this status is stored.
 */
enum BrandExperienceCausalityStatus: string
{
    case CausalityNotEstablished = 'causality_not_established';
}
