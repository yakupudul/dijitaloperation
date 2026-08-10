<?php

namespace App\Services\Integrations\Meta;

use RuntimeException;
use Throwable;

class MetaException extends RuntimeException
{
    public const string KIND_HTTP = 'http';

    public const string KIND_AUTH = 'auth';

    public const string KIND_PERMISSION = 'permission';

    public const string KIND_RATE_LIMIT = 'rate_limit';

    public const string KIND_TRANSPORT = 'transport';

    public const string KIND_PROVIDER = 'provider';

    public const string KIND_CONFIG = 'config';

    public function __construct(
        string $message,
        public readonly string $kind = self::KIND_PROVIDER,
        public readonly ?int $httpStatus = null,
        public readonly ?int $providerCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
