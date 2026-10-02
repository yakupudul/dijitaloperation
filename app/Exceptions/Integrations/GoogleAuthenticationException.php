<?php

namespace App\Exceptions\Integrations;

use RuntimeException;

/**
 * Normalized AUTHENTICATION failure for Collection Engine / Prompt 12.
 */
class GoogleAuthenticationException extends RuntimeException
{
    public const string CATEGORY = 'AUTHENTICATION';

    public function category(): string
    {
        return self::CATEGORY;
    }
}
