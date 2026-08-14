<?php

namespace App\Exceptions;

use RuntimeException;

final class TaskSourceValidationException extends RuntimeException
{
    public function __construct(string $message = 'Invalid Task operational source.')
    {
        parent::__construct($message);
    }
}
