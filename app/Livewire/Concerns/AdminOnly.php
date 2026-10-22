<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use App\Support\Roles;

/**
 * Audit N8 (2026-10-22): one check for every deletion and external write a screen offers — an active admin only. A
 * team member can still look and prepare; removing library rows, services, areas or backlinks is the admin's.
 */
trait AdminOnly
{
    protected function adminOnly(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->is_active && $user->hasRole(Roles::ADMIN), 403);

        return $user;
    }
}
