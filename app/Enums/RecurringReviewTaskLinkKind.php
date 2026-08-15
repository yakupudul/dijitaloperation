<?php

namespace App\Enums;

enum RecurringReviewTaskLinkKind: string
{
    case Created = 'created';
    case ExistingLinked = 'existing_linked';
}
