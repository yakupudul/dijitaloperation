<?php

namespace App\Support\ClientRequests;

use App\Enums\ClientRequestScopeState;
use Illuminate\Support\Carbon;

/**
 * Typed current-scope resolution for a Client Request.
 *
 * @phpstan-type ReasonCode list<string>
 */
final readonly class ClientRequestScopeResolution
{
    /**
     * @param  list<int>  $relevantServiceDefinitionIds
     * @param  list<int>  $coveringCustomerServiceScopeIds
     * @param  list<int>  $nonCoveredServiceDefinitionIds
     * @param  list<string>  $reasonCodes
     */
    public function __construct(
        public ClientRequestScopeState $state,
        public array $relevantServiceDefinitionIds,
        public array $coveringCustomerServiceScopeIds,
        public array $nonCoveredServiceDefinitionIds,
        public Carbon $evaluatedAt,
        public array $reasonCodes = [],
    ) {}

    /**
     * @return array{
     *     state: string,
     *     relevant_service_definition_ids: list<int>,
     *     covering_customer_service_scope_ids: list<int>,
     *     non_covered_service_definition_ids: list<int>,
     *     evaluated_at: string,
     *     reason_codes: list<string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'relevant_service_definition_ids' => $this->relevantServiceDefinitionIds,
            'covering_customer_service_scope_ids' => $this->coveringCustomerServiceScopeIds,
            'non_covered_service_definition_ids' => $this->nonCoveredServiceDefinitionIds,
            'evaluated_at' => $this->evaluatedAt->toIso8601String(),
            'reason_codes' => $this->reasonCodes,
        ];
    }
}
