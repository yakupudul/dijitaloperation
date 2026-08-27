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
        public readonly ?int $providerSubcode = null,
        public readonly ?string $providerType = null,
        public readonly ?string $providerUserTitle = null,
        public readonly ?string $providerUserMessage = null,
        public readonly bool $isTransient = false,
        public readonly ?string $traceId = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function retryable(): bool
    {
        if ($this->isTransient) {
            return true;
        }

        if (in_array($this->kind, [self::KIND_RATE_LIMIT, self::KIND_TRANSPORT], true)) {
            return true;
        }

        return $this->kind === self::KIND_HTTP
            && $this->httpStatus !== null
            && ($this->httpStatus === 429 || $this->httpStatus >= 500);
    }
}
