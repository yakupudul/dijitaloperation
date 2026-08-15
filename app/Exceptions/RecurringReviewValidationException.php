<?php

namespace App\Exceptions;

use RuntimeException;

final class RecurringReviewValidationException extends RuntimeException
{
    public function __construct(
        public readonly string $code,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $code);
    }
}
