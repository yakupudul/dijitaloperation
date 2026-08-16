<?php

namespace App\Services\Assistant;

use App\Enums\AssistantCapabilityId;
use App\Enums\AssistantClarificationReason;
use App\Enums\AssistantIntentType;
use App\Support\Assistant\AssistantCapabilityRegistry;
use App\Support\Assistant\AssistantMetricRegistry;
use App\Support\Assistant\Dto\AssistantIntentCandidate;
use App\Support\Assistant\Dto\AssistantSessionScope;

/**
 * Validates model/structured intent candidates before any data access.
 * Rejects table/column/SQL/Customer-ID invention.
 */
final class AssistantIntentValidator
{
    public function __construct(
        private readonly AssistantCapabilityRegistry $capabilities,
        private readonly AssistantMetricRegistry $metrics,
    ) {}

    /**
     * @return array{ok: bool, reason: ?AssistantClarificationReason, errors: list<string>, candidate: ?AssistantIntentCandidate}
     */
    public function validate(AssistantIntentCandidate $candidate, AssistantSessionScope $scope): array
    {
        $errors = [];

        if ($candidate->requestsWrite) {
            return [
                'ok' => false,
                'reason' => null,
                'errors' => ['UNSUPPORTED_WRITE_ACTION'],
                'candidate' => new AssistantIntentCandidate(
                    intentType: AssistantIntentType::UnsupportedWriteAction,
                    requestsWrite: true,
                ),
            ];
        }

        // Reject model-invented IDs in parameters.
        foreach (['customer_id', 'brand_id', 'digital_asset_id', 'table', 'column', 'sql'] as $forbidden) {
            if (array_key_exists($forbidden, $candidate->parameters)) {
                $errors[] = 'MODEL_PROVIDED_'.$forbidden;
            }
        }

        if ($candidate->intentType === AssistantIntentType::Unsupported
            || $candidate->intentType === AssistantIntentType::ClarificationRequired
            || $candidate->intentType === AssistantIntentType::UnsupportedWriteAction) {
            return [
                'ok' => true,
                'reason' => $candidate->intentType === AssistantIntentType::ClarificationRequired
                    ? AssistantClarificationReason::AmbiguousIntent
                    : null,
                'errors' => [],
                'candidate' => $candidate,
            ];
        }

        if ($candidate->capabilityId !== null) {
            if (! $this->capabilities->has($candidate->capabilityId->value)) {
                $errors[] = 'UNKNOWN_CAPABILITY';
            }
            foreach ($this->capabilities->forbiddenCapabilityIds() as $forbidden) {
                if ($candidate->capabilityId->value === $forbidden) {
                    $errors[] = 'FORBIDDEN_CAPABILITY';
                }
            }
        }

        if ($candidate->intentType === AssistantIntentType::FactLookup) {
            if ($candidate->metricId === null || ! $this->metrics->has($candidate->metricId)) {
                $errors[] = 'UNKNOWN_OR_MISSING_METRIC';
            }
            if ($candidate->periodToken === null) {
                return [
                    'ok' => false,
                    'reason' => AssistantClarificationReason::DateRangeRequired,
                    'errors' => ['DATE_RANGE_REQUIRED'],
                    'candidate' => $candidate,
                ];
            }
            if ($candidate->capabilityId === null) {
                $candidate = new AssistantIntentCandidate(
                    intentType: $candidate->intentType,
                    capabilityId: AssistantCapabilityId::ProviderMetricLookup,
                    metricId: $candidate->metricId,
                    periodToken: $candidate->periodToken,
                    domainFilter: $candidate->domainFilter,
                    scopeReference: $candidate->scopeReference,
                    parameters: $candidate->parameters,
                );
            }
        }

        if ($errors !== []) {
            return [
                'ok' => false,
                'reason' => AssistantClarificationReason::AmbiguousIntent,
                'errors' => $errors,
                'candidate' => $candidate,
            ];
        }

        if (! $scope->hasCustomer()) {
            return [
                'ok' => false,
                'reason' => AssistantClarificationReason::CustomerScopeRequired,
                'errors' => ['CUSTOMER_SCOPE_REQUIRED'],
                'candidate' => $candidate,
            ];
        }

        return [
            'ok' => true,
            'reason' => null,
            'errors' => [],
            'candidate' => $candidate,
        ];
    }
}
