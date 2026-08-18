<?php

namespace App\Enums;

enum IntentClassificationStatus: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';
    case Failed = 'failed';
}
