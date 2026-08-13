<?php

namespace App\Enums;

enum ServiceCadence: string
{
    case OneOff = 'one_off';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case OnDemand = 'on_demand';
    case Unspecified = 'unspecified';
}
