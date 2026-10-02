<?php

namespace App\Enums;

enum OpportunityCommercialScopeState: string
{
    case InCurrentScope = 'in_current_scope';
    case OutsideCurrentScope = 'outside_current_scope';
    case ServiceScopeUnknown = 'service_scope_unknown';
    case NotServiceRelevant = 'not_service_relevant';
}
