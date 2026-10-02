<?php

namespace App\Enums;

enum FindingOrigin: string
{
    case RuleEngine = 'rule_engine';
    case Operator = 'operator';
    case LegacyUnverified = 'legacy_unverified';
    case AiFuture = 'ai_future';
}
