<?php

namespace App\Enums;

enum ProspectEvidenceProvenance: string
{
    case Observed = 'observed';
    case Derived = 'derived';
    case AiInference = 'ai_inference';
    case Operator = 'operator';
    case Unavailable = 'unavailable';
}
