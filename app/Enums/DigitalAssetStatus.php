<?php

namespace App\Enums;

enum DigitalAssetStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';
}
