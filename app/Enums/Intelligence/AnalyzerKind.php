<?php

namespace App\Enums\Intelligence;

enum AnalyzerKind: string
{
    case FindingRule = 'FINDING_RULE';
    case OpportunityRule = 'OPPORTUNITY_RULE';
    case AiSkill = 'AI_SKILL';
}
