<?php

namespace App\Enums;

enum DomainEventActorKind: string
{
    case InternalUser = 'internal_user';
    case System = 'system';
    case ClientContact = 'client_contact';
}
