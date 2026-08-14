<?php

namespace App\Enums;

/**
 * Closed Approval subject kinds. No unrestricted morphTo.
 * Frozen Work supports Task-scoped Approvals (client/internal).
 */
enum ApprovalSubjectKind: string
{
    case Task = 'task';
}
