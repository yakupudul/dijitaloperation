<?php

namespace App\Policies;

use App\Models\Collection\CollectionRun;
use App\Models\User;
use App\Support\Roles;

class CollectionRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Roles::ADMIN) || $user->hasRole(Roles::TEAM_MEMBER);
    }

    public function view(User $user, CollectionRun $run): bool
    {
        if ($user->hasRole(Roles::ADMIN)) {
            return true;
        }

        return (int) $run->requested_by_user_id === (int) $user->id;
    }

    public function cancel(User $user, CollectionRun $run): bool
    {
        return $this->view($user, $run) && ! $run->status->isTerminal();
    }

    public function retry(User $user, CollectionRun $run): bool
    {
        return $this->view($user, $run);
    }
}
