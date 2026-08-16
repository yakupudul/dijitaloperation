<?php

namespace App\Enums;

/**
 * Bounded Assistant Intent registry (Prompt 56).
 * No arbitrary code execution.
 */
enum AssistantIntentType: string
{
    case FactLookup = 'fact_lookup';
    case DomainLookup = 'domain_lookup';
    case IntelligenceSummary = 'intelligence_summary';
    case IntelligenceAnalysis = 'intelligence_analysis';
    case HistoricalContext = 'historical_context';
    case SectorContext = 'sector_context';
    case WorkStatus = 'work_status';
    case MethodologyGuidance = 'methodology_guidance';
    case ClarificationRequired = 'clarification_required';
    case Unsupported = 'unsupported';
    case UnsupportedWriteAction = 'unsupported_write_action';
}
