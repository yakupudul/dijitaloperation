<?php

namespace App\Enums\Observability;

enum OperationalAlertSeverity: string
{
    case Info = 'INFO';
    case Warning = 'WARNING';
    case Critical = 'CRITICAL';
}
