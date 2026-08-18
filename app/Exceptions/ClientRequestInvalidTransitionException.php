<?php

namespace App\Exceptions;

use App\Enums\ClientRequestStatus;
use App\Models\ClientRequest;
use RuntimeException;

final class ClientRequestInvalidTransitionException extends RuntimeException
{
    public function __construct(
        public readonly ClientRequest $clientRequest,
        public readonly ClientRequestStatus $from,
        public readonly ClientRequestStatus $to,
    ) {
        parent::__construct(sprintf(
            'Invalid Client Request status transition from %s to %s.',
            $from->value,
            $to->value,
        ));
    }
}
