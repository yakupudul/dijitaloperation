<?php

namespace App\Exceptions;

use RuntimeException;

final class QaReviewValidationException extends RuntimeException
{
    public function __construct(string $message = 'Invalid QA review transition.')
    {
        parent::__construct($message);
    }
}
