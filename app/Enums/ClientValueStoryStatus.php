<?php

namespace App\Enums;

/**
 * Client Value Story aggregate status (Prompt 58).
 */
enum ClientValueStoryStatus: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Unavailable = 'unavailable';
}
