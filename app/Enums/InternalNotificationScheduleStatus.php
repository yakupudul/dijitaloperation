<?php

namespace App\Enums;

enum InternalNotificationScheduleStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';
}
