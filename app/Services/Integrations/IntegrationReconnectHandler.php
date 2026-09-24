<?php

namespace App\Services\Integrations;

use App\Enums\Security\SecurityAuditEventKind;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Services\Security\SecurityAuditRecorder;
use Throwable;

/**
 * After a successful OAuth authorization (Google or Meta): record the reconnect in the security audit log and
 * make the accounts that stopped for "reconnect" due immediately, so collection continues without the
 * operator restarting each account.
 */
final class IntegrationReconnectHandler
{
    public function __construct(
        private readonly ResourceAutomationService $automations,
        private readonly SecurityAuditRecorder $audit,
    ) {}

    public function handle(CoreIntegration $integration, ?User $actor): int
    {
        $resumed = 0;
        try {
            $resumed = $this->automations->resumeAfterReconnect((int) $integration->id);
        } catch (Throwable $exception) {
            report($exception);
        }
        try {
            $this->audit->record(
                SecurityAuditEventKind::IntegrationReconnected,
                actor: $actor,
                integrationId: (int) $integration->id,
                provider: (string) $integration->provider,
                reason: 'OAUTH_AUTHORIZED',
                metadata: ['resumed_automations' => $resumed],
            );
        } catch (Throwable $exception) {
            report($exception);
        }

        return $resumed;
    }
}
