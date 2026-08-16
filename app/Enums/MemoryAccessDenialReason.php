<?php

namespace App\Enums;

enum MemoryAccessDenialReason: string
{
    case SkillDeclaresNoMemory = 'skill_declares_no_memory';
    case SkillDoesNotRequestLayer = 'skill_does_not_request_layer';
    case AgentDoesNotAllowLayer = 'agent_does_not_allow_layer';
    case BrandScopeMismatch = 'brand_scope_mismatch';
    case CustomerScopeMismatch = 'customer_scope_mismatch';
    case SectorIdentityMissing = 'sector_identity_missing';
    case SectorPrivacyNotQualified = 'sector_privacy_not_qualified';
    case ValidityNotActive = 'validity_not_active';
    case LayerNotImplemented = 'layer_content_not_implemented';
    case RetrievalNotImplemented = 'retrieval_not_implemented';
    case CustomerDataInSkillMemory = 'customer_data_in_skill_memory';
    case ContributorIdentityExposed = 'contributor_identity_exposed';
    case CrossBrandForbidden = 'cross_brand_forbidden';
    case WriteForbidden = 'write_forbidden';
    case AiDirectWriteForbidden = 'ai_direct_write_forbidden';
}
