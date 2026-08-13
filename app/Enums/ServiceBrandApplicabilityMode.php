<?php

namespace App\Enums;

enum ServiceBrandApplicabilityMode: string
{
    case CustomerWide = 'customer_wide';
    case SpecificBrands = 'specific_brands';
}
