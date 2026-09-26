<?php

namespace App\Services\CommandCenter;

use Illuminate\Support\Collection;

/** A producer of Komuta merkezi items beyond the built-in ones (coverage gaps, deliverables, invoices…). */
interface CommandCenterSource
{
    /** @return Collection<int, array<string, mixed>> items built with CommandCenter::item() */
    public function items(): Collection;
}
