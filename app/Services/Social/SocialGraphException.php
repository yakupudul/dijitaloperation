<?php

namespace App\Services\Social;

use RuntimeException;
use Throwable;

final class SocialGraphException extends RuntimeException
{
    /** @param  string  $kind  auth | rate | transport | provider */
    public function __construct(string $message, public readonly string $kind = 'provider', public readonly int $graphCode = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** Worth trying again on the next run. */
    public function isTemporary(): bool
    {
        return in_array($this->kind, ['rate', 'transport'], true);
    }
}
