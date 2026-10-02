<?php

namespace App\Enums;

enum FindingConditionState: string
{
    case True = 'true';
    case False = 'false';
    case Unknown = 'unknown';
    case Blocked = 'blocked';
}
