<?php

use App\Models\Collection\CollectionRun;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('collection-runs.{uuid}', function (User $user, string $uuid): bool {
    $run = CollectionRun::query()->where('uuid', $uuid)->first();
    if ($run === null) {
        return false;
    }

    return $user->can('view', $run);
});
