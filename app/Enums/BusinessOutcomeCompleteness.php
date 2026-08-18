<?php

namespace App\Enums;

enum BusinessOutcomeCompleteness: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Unknown = 'unknown';
}
