<?php

namespace App\Enums\IntelligenceCore;

enum BusinessActionSignalClass: string
{
    case AnalyticsEvent = 'analytics_event';
    case AdsConversion = 'ads_conversion';
    case PlatformResult = 'platform_result';
    case LocalProfileAction = 'local_profile_action';
    case CrmObservation = 'crm_observation';
    case OperatorVerifiedOutcome = 'operator_verified_outcome';
}
