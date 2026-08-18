<?php

namespace App\Services\Observability;

use App\Enums\Observability\OperationalAlertRuleType;
use App\Enums\Observability\OperationalAlertSeverity;
use App\Enums\Observability\OperationalAlertState;
use App\Enums\Observability\OperationalSignalFamily;
use App\Models\Observability\OperationalAlert;
use App\Models\User;
use App\Support\Security\SecurityRedactor;

/**
 * Durable Operational Alert lifecycle (Prompt 66).
 * Acknowledge ≠ resolve. History retained. Semantic identity excludes timestamps/current values.
 */
final class OperationalAlertLifecycleService
{
    public function __construct(
        private readonly SecurityRedactor $redactor,
        private readonly OperationalAlertNotifier $notifier,
        private readonly OperationalTelemetryRecorder $telemetry,
    ) {}

    /**
     * @param  array<string, mixed>  $observed  Safe observed values (no secrets)
     */
    public function observeCondition(
        string $ruleKey,
        int $ruleVersion,
        OperationalAlertRuleType $ruleType,
        OperationalSignalFamily $family,
        OperationalAlertSeverity $severity,
        string $scopeType,
        string $scopeKey,
        string $title,
        ?string $summary = null,
        array $observed = [],
    ): OperationalAlert {
        $semanticKey = $this->semanticKey($ruleKey, $scopeType, $scopeKey);
        $safeObserved = $this->redactor->redactContext($observed);

        $existing = OperationalAlert::query()
            ->where('semantic_key', $semanticKey)
            ->whereIn('state', [
                OperationalAlertState::Open->value,
                OperationalAlertState::Acknowledged->value,
            ])
            ->first();

        if ($existing instanceof OperationalAlert) {
            $existing->observation_count = (int) $existing->observation_count + 1;
            $existing->last_observed_at = now();
            $existing->observed = $safeObserved;
            $existing->summary = $summary;
            $existing->save();
            $this->telemetry->info('alert.observation', [
                'operation' => 'update',
                'status' => $existing->state->value,
                'error_code' => $ruleKey,
            ]);

            return $existing;
        }

        $alert = OperationalAlert::query()->create([
            'semantic_key' => $semanticKey,
            'rule_key' => $ruleKey,
            'rule_version' => $ruleVersion,
            'rule_type' => $ruleType,
            'signal_family' => $family,
            'severity' => $severity,
            'state' => OperationalAlertState::Open,
            'scope_type' => $scopeType,
            'scope_key' => substr($scopeKey, 0, 120),
            'title' => substr($title, 0, 255),
            'summary' => $summary !== null ? substr($summary, 0, 500) : null,
            'observed' => $safeObserved,
            'observation_count' => 1,
            'first_observed_at' => now(),
            'last_observed_at' => now(),
            'opened_at' => now(),
            'notification_emitted' => false,
        ]);

        $this->notifier->notifyOpened($alert);
        $this->telemetry->warning('alert.opened', [
            'operation' => $ruleKey,
            'status' => OperationalAlertState::Open->value,
            'error_code' => $ruleType->value,
        ]);

        return $alert;
    }

    public function resolveIfActive(string $ruleKey, string $scopeType, string $scopeKey, string $resolutionKind = 'RECOVERED'): ?OperationalAlert
    {
        $semanticKey = $this->semanticKey($ruleKey, $scopeType, $scopeKey);
        $alert = OperationalAlert::query()
            ->where('semantic_key', $semanticKey)
            ->whereIn('state', [
                OperationalAlertState::Open->value,
                OperationalAlertState::Acknowledged->value,
            ])
            ->first();

        if (! $alert instanceof OperationalAlert) {
            return null;
        }

        $alert->state = OperationalAlertState::Resolved;
        $alert->resolved_at = now();
        $alert->resolution_kind = $resolutionKind;
        $alert->save();

        $this->notifier->notifyResolved($alert);
        $this->telemetry->info('alert.resolved', [
            'operation' => $ruleKey,
            'status' => OperationalAlertState::Resolved->value,
        ]);

        return $alert;
    }

    public function acknowledge(OperationalAlert $alert, User $actor, ?string $note = null): OperationalAlert
    {
        if (! $alert->isActive()) {
            return $alert;
        }

        $alert->state = OperationalAlertState::Acknowledged;
        $alert->acknowledged_at = now();
        $alert->acknowledged_by_user_id = $actor->id;
        $alert->ack_note = $note !== null ? substr($note, 0, 500) : null;
        $alert->save();

        // Acknowledge does NOT resolve and does not mutate queue/credential/dataset state.
        return $alert;
    }

    public function semanticKey(string $ruleKey, string $scopeType, string $scopeKey): string
    {
        return substr(hash('sha256', $ruleKey.'|'.$scopeType.'|'.$scopeKey), 0, 64);
    }
}
