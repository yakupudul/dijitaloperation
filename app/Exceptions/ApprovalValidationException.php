<?php

namespace App\Exceptions;

use RuntimeException;

final class ApprovalValidationException extends RuntimeException
{
    public function __construct(string $message = 'Invalid Approval transition.')
    {
        parent::__construct($message);
    }
}
