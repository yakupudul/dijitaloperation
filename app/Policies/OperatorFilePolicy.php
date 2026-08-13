<?php

namespace App\Policies;

use App\Models\OperatorFile;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;

class OperatorFilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::ACCESS_APP);
    }

    public function view(User $user, OperatorFile $operatorFile): bool
    {
        return $this->ownsOrAdmin($user, $operatorFile);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::ACCESS_APP);
    }

    public function update(User $user, OperatorFile $operatorFile): bool
    {
        return $this->ownsOrAdmin($user, $operatorFile);
    }

    public function delete(User $user, OperatorFile $operatorFile): bool
    {
        return $this->ownsOrAdmin($user, $operatorFile);
    }

    public function download(User $user, OperatorFile $operatorFile): bool
    {
        return $this->ownsOrAdmin($user, $operatorFile);
    }

    private function ownsOrAdmin(User $user, OperatorFile $operatorFile): bool
    {
        if ($user->hasRole(Roles::ADMIN)) {
            return true;
        }

        if (! $user->can(Permissions::ACCESS_APP)) {
            return false;
        }

        return (int) $operatorFile->user_id === (int) $user->id;
    }
}
