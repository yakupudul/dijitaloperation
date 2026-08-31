<?php

namespace App\Enums\IntelligenceCore;

enum IntelligenceSourceClass: string
{
    case FirstPartyMeasured = 'FIRST_PARTY_MEASURED';
    case FirstPartyConfigured = 'FIRST_PARTY_CONFIGURED';
    case DirectObserved = 'DIRECT_OBSERVED';
    case CmsAuthenticated = 'CMS_AUTHENTICATED';
    case ProviderAttributed = 'PROVIDER_ATTRIBUTED';
    case ProviderEstimated = 'PROVIDER_ESTIMATED';
    case OperatorMaintained = 'OPERATOR_MAINTAINED';
    case MoxdopDerived = 'MOXDOP_DERIVED';
    case AiSearchObserved = 'AI_SEARCH_OBSERVED';
}
