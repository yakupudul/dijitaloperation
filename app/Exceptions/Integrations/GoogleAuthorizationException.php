<?php

namespace App\Exceptions\Integrations;

use RuntimeException;

/**
 * Normalized AUTHORIZATION / scope failure (missing Connector scope).
 */
class GoogleAuthorizationException extends RuntimeException
{
    public const string CATEGORY = 'AUTHORIZATION';

    /**
     * @param  list<string>  $missingScopes
     */
    public function __construct(
        string $message,
        public readonly array $missingScopes = [],
        public readonly ?string $capability = null,
    ) {
        parent::__construct($message);
    }

    public function category(): string
    {
        return self::CATEGORY;
    }
}
