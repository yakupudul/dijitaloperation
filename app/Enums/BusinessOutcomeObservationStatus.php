<?php

namespace App\Enums;

enum BusinessOutcomeObservationStatus: string
{
    case Active = 'active';
    case Invalidated = 'invalidated';
}
