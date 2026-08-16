<?php

namespace App\Services\Assistant;

use App\Enums\AssistantSourceClass;
use App\Support\Assistant\AssistantSourceAuthority;
use App\Support\Assistant\Dto\AssistantAnswer;
use App\Support\Assistant\Dto\AssistantClaim;
use App\Support\Assistant\Dto\AssistantQueryPlan;
use App\Support\Assistant\Dto\AssistantSourceRef;

/**
 * Rejects unsupported / cross-scope / class-impersonating claims.
 * No hallucinated DB answers.
 */
final class AssistantAnswerGroundingValidator
{
    public function __construct(
        private readonly AssistantSourceAuthority $authority,
    ) {}

    public function validate(AssistantAnswer $answer, AssistantQueryPlan $plan): AssistantAnswer
    {
        $allowedRefs = [];
        foreach ($answer->sourceManifest->sourceRefs as $ref) {
            $allowedRefs[$ref->opaqueRef] = $ref;
        }

        $validClaims = [];
        $rejected = [];

        foreach ($answer->claims as $claim) {
            $result = $this->validateClaim($claim, $allowedRefs, $plan);
            if ($result['ok']) {
                $validClaims[] = $claim;
            } else {
                $rejected[] = $result['reason'];
            }
        }

        if ($rejected !== [] && $validClaims === [] && $answer->claims !== []) {
            return new AssistantAnswer(
                strategy: $answer->strategy,
                intentType: $answer->intentType,
                scope: $answer->scope,
                claims: [],
                blocks: [[
                    'type' => 'limitation',
                    'message' => 'I do not have enough current MoxDOP data to answer that reliably.',
                    'grounding_rejected' => $rejected,
                ]],
                sourceManifest: $answer->sourceManifest,
                requestedPeriod: $answer->requestedPeriod,
                coveredPeriod: $answer->coveredPeriod,
                freshness: $answer->freshness,
                coverage: $answer->coverage,
                limitations: array_merge($answer->limitations, ['grounding_failed']),
                abstained: true,
                abstentionReason: 'unsupported_factual_claim',
                runtimeProvenance: array_merge($answer->runtimeProvenance, [
                    'hallucinated_db_answer' => false,
                    'grounding_rejected' => $rejected,
                ]),
                answeredAt: $answer->answeredAt,
            );
        }

        if ($rejected === []) {
            return $answer;
        }

        return new AssistantAnswer(
            strategy: $answer->strategy,
            intentType: $answer->intentType,
            scope: $answer->scope,
            claims: $validClaims,
            blocks: $answer->blocks,
            sourceManifest: $answer->sourceManifest,
            requestedPeriod: $answer->requestedPeriod,
            coveredPeriod: $answer->coveredPeriod,
            freshness: $answer->freshness,
            coverage: $answer->coverage,
            limitations: array_merge($answer->limitations, ['some_claims_rejected']),
            clarificationReason: $answer->clarificationReason,
            abstained: $answer->abstained,
            abstentionReason: $answer->abstentionReason,
            runtimeProvenance: array_merge($answer->runtimeProvenance, [
                'grounding_rejected' => $rejected,
            ]),
            answeredAt: $answer->answeredAt,
        );
    }

    /**
     * @param  array<string, AssistantSourceRef>  $allowedRefs
     * @return array{ok: bool, reason: ?string}
     */
    public function validateClaim(AssistantClaim $claim, array $allowedRefs, AssistantQueryPlan $plan): array
    {
        if ($claim->sourceRefs === []) {
            return ['ok' => false, 'reason' => 'FACTUAL_CLAIM_WITHOUT_SOURCE'];
        }

        foreach ($claim->sourceRefs as $ref) {
            if (! array_key_exists($ref->opaqueRef, $allowedRefs)) {
                return ['ok' => false, 'reason' => 'UNKNOWN_SOURCE_REF'];
            }

            if (! $this->authority->canSatisfy($claim->requiredSourceClass, $ref->sourceClass)) {
                return ['ok' => false, 'reason' => 'SOURCE_CLASS_IMPERSONATION'];
            }

            // Sector cannot masquerade as Brand Evidence / Provider Data
            if ($claim->requiredSourceClass === AssistantSourceClass::ProviderData
                && $ref->sourceClass !== AssistantSourceClass::ProviderData) {
                return ['ok' => false, 'reason' => 'NON_PROVIDER_AS_METRIC'];
            }

            if ($claim->requiredSourceClass === AssistantSourceClass::Evidence
                && in_array($ref->sourceClass, [
                    AssistantSourceClass::SectorPattern,
                    AssistantSourceClass::SkillKnowledge,
                    AssistantSourceClass::BrandExperience,
                ], true)) {
                return ['ok' => false, 'reason' => 'MEMORY_OR_SKILL_AS_EVIDENCE'];
            }

            // Cross-scope: Brand Experience refs must belong to session Brand when present in metadata
            if ($ref->sourceClass === AssistantSourceClass::BrandExperience) {
                $metaBrand = $ref->metadata['brand_id'] ?? null;
                if ($metaBrand !== null && (int) $metaBrand !== (int) $plan->scope->brandId) {
                    return ['ok' => false, 'reason' => 'CROSS_SCOPE_SOURCE_REF'];
                }
            }
        }

        if ($claim->numericValue !== null && $claim->requiredSourceClass !== AssistantSourceClass::ProviderData
            && $claim->requiredSourceClass !== AssistantSourceClass::Evidence) {
            // Numeric MoxDOP facts must be provider/evidence backed
            return ['ok' => false, 'reason' => 'NUMERIC_WITHOUT_FACT_SOURCE'];
        }

        return ['ok' => true, 'reason' => null];
    }
}
