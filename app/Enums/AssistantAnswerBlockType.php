<?php

namespace App\Enums;

enum AssistantAnswerBlockType: string
{
    case Fact = 'fact';
    case DomainRecord = 'domain_record';
    case Analysis = 'analysis';
    case HistoricalContext = 'historical_context';
    case SectorContext = 'sector_context';
    case Methodology = 'methodology';
    case Limitation = 'limitation';
    case Clarification = 'clarification';
}
