<?php

namespace App\Enums;

enum ApprovalKind: string
{
    case Client = 'client';
    case Internal = 'internal';
}
