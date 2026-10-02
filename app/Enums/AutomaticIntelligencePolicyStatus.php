<?php

namespace App\Enums;

enum AutomaticIntelligencePolicyStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Disabled = 'disabled';
    case Archived = 'archived';
}
