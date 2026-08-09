<?php

namespace App\Services\Integrations\DataForSeo;

use RuntimeException;
use Throwable;

/**
 * Safe DataForSEO failure. Never carries credentials or Authorization headers.
 */
class DataForSeoException extends RuntimeException
{
    public const string KIND_TRANSPORT = 'transport';

    public const string KIND_HTTP = 'http';

    public const string KIND_PROVIDER_STATUS = 'provider_status';

    public const string KIND_MALFORMED = 'malformed';

    public const string KIND_ENDPOINT_NOT_ALLOWED = 'endpoint_not_allowed';

    public const string KIND_AMBIGUOUS_PAID = 'ambiguous_paid';

    public function __construct(
        string $message,
        public readonly string $kind = self::KIND_TRANSPORT,
        public readonly ?int $httpStatus = null,
        public readonly ?int $providerStatusCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
