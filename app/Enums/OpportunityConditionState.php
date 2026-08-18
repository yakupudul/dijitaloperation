<?php

namespace App\Enums;

enum OpportunityConditionState: string
{
    case True = 'true';
    case False = 'false';
    case Unknown = 'unknown';
    case Blocked = 'blocked';
}
