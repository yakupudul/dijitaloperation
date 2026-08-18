<?php

namespace App\Enums;

enum IntentPurchaseStage: string
{
    case HighIntent = 'high_intent';
    case Informational = 'informational';
    case Unknown = 'unknown';
}
