<?php

namespace App\Enums;

enum ProspectSalesIntelligenceStatus: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';
    case Failed = 'failed';
}
