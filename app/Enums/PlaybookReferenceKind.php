<?php

namespace App\Enums;

enum PlaybookReferenceKind: string
{
    case ExternalUrl = 'external_url';
    case InternalRoute = 'internal_route';
}
