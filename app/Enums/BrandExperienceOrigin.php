<?php

namespace App\Enums;

enum BrandExperienceOrigin: string
{
    case OperatorCaptured = 'operator_captured';
    case SystemAssistedCapture = 'system_assisted_capture';
}
