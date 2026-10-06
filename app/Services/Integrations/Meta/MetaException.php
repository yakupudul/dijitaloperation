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

    /**
     * Graph code 1 "Please reduce the amount of data you're asking for": the request is too heavy for Meta (it arrives
     * as HTTP 500 too). Retrying the same request does not help; a smaller one (page size, date range) can.
     */
    public const string KIND_DATA_TOO_LARGE = 'data_too_large';

    public function __construct(
        string $message,
        public readonly string $kind = self::KIND_PROVIDER,
        public readonly ?int $httpStatus = null,
        public readonly ?int $providerCode = null,
        ?Throwable $previous = null,
        public readonly ?int $providerSubcode = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
