<?php

namespace App\Services\Assistant;

use App\Enums\AssistantAnswerStrategy;
use App\Enums\AssistantClarificationReason;
use App\Enums\AssistantIntentType;
use App\Support\Assistant\AssistantCapabilityRegistry;
use App\Support\Assistant\Dto\AssistantAnswer;
use App\Support\Assistant\Dto\AssistantIntentCandidate;
use App\Support\Assistant\Dto\AssistantQueryPlan;
use App\Support\Assistant\Dto\AssistantSessionScope;
use App\Support\Assistant\Dto\AssistantThreadState;

/**
 * Canonical MoxDOP Assistant orchestrator (Prompt 56).
 *
 * Natural-language interface over bounded capabilities.
 * Not a generic chatbot. Not text-to-SQL. Read-only.
 */
final class MoxdopAssistantService
{
    public function __construct(
        private readonly AssistantScopeResolver $scopes,
        private readonly AssistantIntentValidator $intentValidator,
        private readonly AssistantQueryPlanner $planner,
        private readonly AssistantCapabilityExecutor $executor,
        private readonly AssistantCapabilityRegistry $capabilities,
        private readonly AssistantBoundaryGuard $boundaryGuard,
    ) {}

    /**
     * Primary entry — structured intent candidate (NL interpretation upstream).
     *
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     * @param  list<int>  $authorizedDigitalAssetIds
     */
    public function ask(
        int $userId,
        AssistantIntentCandidate $candidate,
        array $authorizedCustomerIds,
        array $authorizedBrandIds,
        array $authorizedDigitalAssetIds,
        ?int $customerId = null,
        ?int $brandId = null,
        ?int $digitalAssetId = null,
        ?string $timezone = null,
        ?AssistantThreadState $threadState = null,
    ): AssistantAnswer {
        $this->boundaryGuard->assertSafeArchitecture();

        $scopeOrReason = $this->scopes->buildScope(
            userId: $userId,
            authorizedCustomerIds: $authorizedCustomerIds,
            authorizedBrandIds: $authorizedBrandIds,
            authorizedDigitalAssetIds: $authorizedDigitalAssetIds,
            customerId: $customerId,
            brandId: $brandId,
            digitalAssetId: $digitalAssetId,
            timezone: $timezone,
            threadState: $threadState,
        );

        if ($scopeOrReason instanceof AssistantClarificationReason) {
            $emptyScope = new AssistantSessionScope(
                userId: $userId,
                authorizedCustomerIds: $authorizedCustomerIds,
                authorizedBrandIds: $authorizedBrandIds,
                authorizedDigitalAssetIds: $authorizedDigitalAssetIds,
                customerId: $customerId,
                brandId: $brandId,
                digitalAssetId: $digitalAssetId,
                timezone: $timezone,
            );
            $plan = $this->planner->plan(
                $emptyScope,
                new AssistantIntentCandidate(
                    intentType: AssistantIntentType::ClarificationRequired,
                ),
            );

            // Force clarification reason from scope
            $plan = new AssistantQueryPlan(
                scope: $emptyScope,
                intentType: AssistantIntentType::ClarificationRequired,
                capabilities: [],
                answerStrategy: AssistantAnswerStrategy::Clarification,
                clarificationReason: $scopeOrReason,
                validated: true,
            );

            return $this->executor->execute($plan);
        }

        /** @var AssistantSessionScope $scope */
        $scope = $scopeOrReason;

        $validated = $this->intentValidator->validate($candidate, $scope);
        if (! $validated['ok']) {
            if (($validated['candidate']?->intentType) === AssistantIntentType::UnsupportedWriteAction) {
                $plan = $this->planner->plan($scope, $validated['candidate']);

                return $this->executor->execute($plan);
            }

            $plan = new AssistantQueryPlan(
                scope: $scope,
                intentType: AssistantIntentType::ClarificationRequired,
                capabilities: [],
                answerStrategy: AssistantAnswerStrategy::Clarification,
                clarificationReason: $validated['reason'] ?? AssistantClarificationReason::AmbiguousIntent,
                validated: true,
            );

            return $this->executor->execute($plan);
        }

        /** @var AssistantIntentCandidate $authorizedCandidate */
        $authorizedCandidate = $validated['candidate'];
        $plan = $this->planner->plan($scope, $authorizedCandidate, $threadState);

        return $this->executor->execute($plan);
    }

    /**
     * @return array<string, mixed>
     */
    public function architectureSnapshot(): array
    {
        return [
            'capability_registry' => $this->capabilities->snapshot(),
            'chat_ui' => false,
            'sidebar_item' => false,
            'floating_button' => false,
            'text_to_sql' => false,
            'raw_db_tool' => false,
            'fine_tuning' => false,
            'embeddings' => false,
            'vector_db' => false,
            'similar_customer' => false,
            'read_only' => true,
            'provider_writes' => false,
            'domain_writes' => false,
            'prompt_50_reuse' => true,
            'prompt_54_reuse' => true,
            'prompt_55_evaluation_hooks' => true,
            'assistant_v2' => false,
        ];
    }
}
