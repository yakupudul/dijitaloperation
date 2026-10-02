<?php

namespace App\Enums;

enum ServiceCatalogStatus: string
{
    case Available = 'available';
    case Archived = 'archived';
}
