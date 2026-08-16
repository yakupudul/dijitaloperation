<?php

namespace App\Enums;

enum BusinessOutcomeSourceKind: string
{
    case Manual = 'manual';
    case CsvImport = 'csv_import';
}
