<?php

namespace App\Support\Security;

/**
 * Short-lived plaintext secret holder — never JSON-serializes the value.
 */
final class EphemeralSecret
{
    public function __construct(
        private readonly string $value,
        public readonly string $purpose,
        public readonly ?string $provider = null,
        public readonly ?int $integrationId = null,
        public readonly ?int $connectionId = null,
    ) {}

    public function reveal(): string
    {
        return $this->value;
    }

    public function isEmpty(): bool
    {
        return $this->value === '';
    }

    /**
     * @return array{purpose: string, provider: ?string, integration_id: ?int, connection_id: ?int, present: bool}
     */
    public function toArray(): array
    {
        return [
            'purpose' => $this->purpose,
            'provider' => $this->provider,
            'integration_id' => $this->integrationId,
            'connection_id' => $this->connectionId,
            'present' => $this->value !== '',
        ];
    }

    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return '[REDACTED_EPHEMERAL_SECRET]';
    }
}
