<?php

namespace App\Enums\Collection;

enum RequirementLevel: string
{
    case Required = 'REQUIRED';
    case Optional = 'OPTIONAL';
    case Conditional = 'CONDITIONAL';
}
