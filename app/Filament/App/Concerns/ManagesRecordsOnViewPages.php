<?php

namespace App\Filament\App\Concerns;

/**
 * Filament defaults relation managers on ViewRecord pages to read-only, which
 * hides Create/Edit/Delete actions. Use this trait only for local domain
 * management relations that product specs require to be interactive from view pages.
 */
trait ManagesRecordsOnViewPages
{
    public function isReadOnly(): bool
    {
        return false;
    }
}
