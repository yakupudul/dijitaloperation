<?php

namespace App\Enums;

/**
 * Service Scope awareness for a Client Request.
 *
 * Missing Service classification is UNCLASSIFIED — never OUTSIDE_CURRENT_SCOPE.
 * MIXED is reserved for multi-service Requests (Prompt 42 uses at most one Service).
 */
enum ClientRequestScopeState: string
{
    case InScope = 'in_scope';
    case OutsideCurrentScope = 'outside_current_scope';
    case Unclassified = 'unclassified';
    case NotApplicable = 'not_applicable';
    case Mixed = 'mixed';

    public function isOutsideCurrentScope(): bool
    {
        return $this === self::OutsideCurrentScope;
    }

    /**
     * Frozen Blade still uses a boolean outside-scope badge.
     */
    public function presentationInScope(): bool
    {
        return $this === self::InScope;
    }
}
