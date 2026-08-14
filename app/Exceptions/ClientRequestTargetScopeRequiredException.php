<?php

namespace App\Exceptions;

use App\Models\ClientRequest;
use RuntimeException;

/**
 * Task creation blocked because Task requires a DigitalAsset target and none was resolved.
 * The Client Request itself remains valid.
 */
final class ClientRequestTargetScopeRequiredException extends RuntimeException
{
    public const string CODE = 'TARGET_SCOPE_REQUIRED';

    public function __construct(
        public readonly ClientRequest $clientRequest,
        string $message = 'TARGET_SCOPE_REQUIRED: explicit DigitalAsset target is required to create a Task from this Client Request.',
    ) {
        parent::__construct($message);
    }
}
