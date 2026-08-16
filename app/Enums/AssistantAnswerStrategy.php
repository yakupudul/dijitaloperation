<?php

namespace App\Enums;

enum AssistantAnswerStrategy: string
{
    case DeterministicFact = 'deterministic_fact';
    case CanonicalDomainSummary = 'canonical_domain_summary';
    case SpecialistStructuredAnalysis = 'specialist_structured_analysis';
    case MethodologyGuidance = 'methodology_guidance';
    case Clarification = 'clarification';
    case Unavailable = 'unavailable';
    case Unsupported = 'unsupported';
}
