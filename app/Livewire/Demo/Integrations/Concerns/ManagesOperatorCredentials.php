<?php

namespace App\Livewire\Demo\Integrations\Concerns;

use App\Models\User;
use App\Support\Roles;

trait ManagesOperatorCredentials
{
    public function canManageCredentials(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasRole(Roles::ADMIN);
    }

    protected function credentialManager(): User
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        return $user;
    }
}
