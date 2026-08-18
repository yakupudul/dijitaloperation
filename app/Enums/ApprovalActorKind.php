<?php

namespace App\Enums;

enum ApprovalActorKind: string
{
    case InternalUser = 'internal_user';
    case ClientContact = 'client_contact';
}
