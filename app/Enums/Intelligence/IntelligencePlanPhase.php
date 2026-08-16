<?php

namespace App\Enums\Intelligence;

enum IntelligencePlanPhase: string
{
    case FindingRules = 'PHASE_1_FINDING_RULES';
    case OpportunityRules = 'PHASE_2_OPPORTUNITY_RULES';
    case AiSkills = 'PHASE_3_AI_SKILLS';
}
