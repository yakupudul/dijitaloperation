<?php

namespace App\Enums;

/**
 * Bounded primary operational source for a Task.
 * No unrestricted morphTo. Finding/Opportunity/Evidence are never Task sources.
 */
enum TaskSourceKind: string
{
    case Recommendation = 'recommendation';
    case ClientRequest = 'client_request';
    case Direct = 'direct';
}
