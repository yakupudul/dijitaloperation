<?php

namespace App\Enums;

/**
 * Bounded assertion registry (Prompt 55).
 *
 * No arbitrary PHP/SQL/eval assertions may be stored or executed.
 */
enum IntelligenceEvaluationAssertionType: string
{
    case RetrievalIncludesRef = 'retrieval_includes_ref';
    case RetrievalExcludesRef = 'retrieval_excludes_ref';
    case RetrievalLayerEmpty = 'retrieval_layer_empty';
    case RetrievalLayerNonempty = 'retrieval_layer_nonempty';
    case NoCrossBrandContext = 'no_cross_brand_context';
    case NoCrossCustomerContext = 'no_cross_customer_context';
    case NoSectorContributorContext = 'no_sector_contributor_context';
    case NoForbiddenCanary = 'no_forbidden_canary';
    case RequiredEvidencePresent = 'required_evidence_present';
    case RequiredGoalPresent = 'required_goal_present';
    case ExpectedAbstention = 'expected_abstention';
    case ExpectedNoAbstention = 'expected_no_abstention';
    case ExpectedReasonCode = 'expected_reason_code';
    case OutputReferencesEvidence = 'output_references_evidence';
    case OutputDoesNotReferenceUnknownEvidence = 'output_does_not_reference_unknown_evidence';
    case OutputDoesNotReferenceUnknownMemory = 'output_does_not_reference_unknown_memory';
    case OutputForbidsConclusionType = 'output_forbids_conclusion_type';
    case OutputRequiresConclusionType = 'output_requires_conclusion_type';
    case OutputRequiresLimitation = 'output_requires_limitation';
    case OutputForbidsClaimPattern = 'output_forbids_claim_pattern';
    case OutputRequiresCurrentContext = 'output_requires_current_context';
    case NoGenericContextInsensitivity = 'no_generic_context_insensitivity';
    case NoProviderCall = 'no_provider_call';
    case NoDomainWrite = 'no_domain_write';
    case CurrentTruthAuthority = 'current_truth_authority';
    case MemoryNotAsEvidence = 'memory_not_as_evidence';
    case RetrievalPrecisionFloor = 'retrieval_precision_floor';
    case RequiredContextRecallFloor = 'required_context_recall_floor';
    case NoPrivacyOverfetch = 'no_privacy_overfetch';
    case NoSilentTruncation = 'no_silent_truncation';
    case NoInventedBrandHistory = 'no_invented_brand_history';
    case NoAutoTuning = 'no_auto_tuning';
    case NoTrainingExport = 'no_training_export';

    public function isZeroToleranceSafety(): bool
    {
        return in_array($this, [
            self::NoCrossBrandContext,
            self::NoCrossCustomerContext,
            self::NoSectorContributorContext,
            self::NoForbiddenCanary,
            self::NoPrivacyOverfetch,
            self::OutputDoesNotReferenceUnknownEvidence,
            self::OutputDoesNotReferenceUnknownMemory,
            self::MemoryNotAsEvidence,
            self::NoProviderCall,
        ], true);
    }
}
