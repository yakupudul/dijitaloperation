<?php

namespace App\Enums\IntelligenceCore;

enum IdentityMatchMethod: string
{
    case SyntacticExact = 'syntactic_exact';
    case ProviderStableId = 'provider_stable_id';
    case RedirectEvidence = 'redirect_evidence';
    case DeclaredCanonicalEvidence = 'declared_canonical_evidence';
    case CmsPermalinkEvidence = 'cms_permalink_evidence';
    case OperatorConfirmed = 'operator_confirmed';
    case RuleConfirmed = 'rule_confirmed';
}
