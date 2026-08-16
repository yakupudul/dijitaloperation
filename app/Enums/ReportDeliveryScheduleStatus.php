<?php

namespace App\Enums;

enum ReportDeliveryScheduleStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';
}
