<?php

namespace App\Services\Security;

use App\Enums\Security\SecurityAuditEventKind;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use App\Support\Security\SecurityRedactor;
use Illuminate\Support\Facades\Log;

/**
 * Safe security audit events — never store secret values (Prompt 64).
 */
final class SecurityAuditRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        SecurityAuditEventKind $kind,
        ?User $actor = null,
        ?int $customerId = null,
        ?int $brandId = null,
        ?int $integrationId = null,
        ?string $provider = null,
        ?string $reason = null,
        array $metadata = [],
    ): SecurityAuditEvent {
        $safeMeta = app(SecurityRedactor::class)->redactContext($metadata);

        $event = SecurityAuditEvent::query()->create([
            'kind' => $kind->value,
            'actor_user_id' => $actor?->id,
            'customer_id' => $customerId,
            'brand_id' => $brandId,
            'integration_id' => $integrationId,
            'provider' => $provider,
            'reason' => $reason,
            'metadata' => $safeMeta,
            'created_at' => now(),
        ]);

        Log::info('security.audit', [
            'kind' => $kind->value,
            'actor_user_id' => $actor?->id,
            'customer_id' => $customerId,
            'brand_id' => $brandId,
            'integration_id' => $integrationId,
            'provider' => $provider,
            'reason' => $reason,
        ]);

        return $event;
    }
}
