<?php

namespace App\Exceptions;

use RuntimeException;

final class TaskScopeValidationException extends RuntimeException
{
    public function __construct(string $message = 'Invalid Task execution scope.')
    {
        parent::__construct($message);
    }
}
