<?php

namespace App\Services\Integrations\Google\Discovery;

use App\Support\Integrations\DiscoveredExternalResource;

/**
 * Per-Connector discovery outcome.
 *
 * Status vocabulary (Prompt 15):
 * - completed — full successful inventory (may be zero resources)
 * - partial — some resources seen; inventory not guaranteed complete
 * - failed — terminal connector failure
 * - scope_required — missing OAuth scope (no provider call)
 * - external_access_required — app/API/project access missing
 * - authentication_required — credential unusable
 * - setup_required — legacy alias for external/app readiness (Ads developer token, etc.)
 * - skipped — intentionally not run
 * - ok — legacy alias of completed (kept for Filament/tests)
 */
final class CapabilityDiscoveryResult
{
    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_OK = 'ok';

    public const string STATUS_PARTIAL = 'partial';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_ERROR = 'error';

    public const string STATUS_SCOPE_REQUIRED = 'scope_required';

    public const string STATUS_EXTERNAL_ACCESS_REQUIRED = 'external_access_required';

    public const string STATUS_AUTHENTICATION_REQUIRED = 'authentication_required';

    public const string STATUS_SETUP_REQUIRED = 'setup_required';

    public const string STATUS_SKIPPED = 'skipped';

    /**
     * @param  list<DiscoveredExternalResource>  $resources
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $capability,
        public readonly string $status,
        public readonly string $message,
        public readonly array $resources = [],
        public readonly bool $completeInventory = false,
        public readonly array $meta = [],
    ) {}

    /**
     * @param  list<DiscoveredExternalResource>  $resources
     */
    public static function completed(string $capability, array $resources, string $message = 'OK'): self
    {
        return new self($capability, self::STATUS_COMPLETED, $message, $resources, true);
    }

    /**
     * @param  list<DiscoveredExternalResource>  $resources
     */
    public static function ok(string $capability, array $resources, string $message = 'OK'): self
    {
        // Backward-compatible alias — treated as complete successful inventory.
        return new self($capability, self::STATUS_OK, $message, $resources, true);
    }

    /**
     * @param  list<DiscoveredExternalResource>  $resources
     */
    public static function partial(string $capability, array $resources, string $message): self
    {
        return new self($capability, self::STATUS_PARTIAL, $message, $resources, false);
    }

    public static function scopeRequired(string $capability, string $message): self
    {
        return new self($capability, self::STATUS_SCOPE_REQUIRED, $message);
    }

    public static function externalAccessRequired(string $capability, string $message): self
    {
        return new self($capability, self::STATUS_EXTERNAL_ACCESS_REQUIRED, $message);
    }

    public static function authenticationRequired(string $capability, string $message): self
    {
        return new self($capability, self::STATUS_AUTHENTICATION_REQUIRED, $message);
    }

    public static function setupRequired(string $capability, string $message): self
    {
        return new self($capability, self::STATUS_SETUP_REQUIRED, $message);
    }

    public static function error(string $capability, string $message): self
    {
        return new self($capability, self::STATUS_ERROR, $message);
    }

    public static function failed(string $capability, string $message): self
    {
        return new self($capability, self::STATUS_FAILED, $message);
    }

    public static function skipped(string $capability, string $message): self
    {
        return new self($capability, self::STATUS_SKIPPED, $message);
    }

    public function isSuccessfulInventory(): bool
    {
        return in_array($this->status, [self::STATUS_OK, self::STATUS_COMPLETED, self::STATUS_PARTIAL], true);
    }

    public function allowsNegativeReconciliation(): bool
    {
        return $this->completeInventory
            && in_array($this->status, [self::STATUS_OK, self::STATUS_COMPLETED], true);
    }
}
