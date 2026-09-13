<?php

namespace App\Services\Collection\Monitoring;

use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;

final class CollectionAccountPresenter
{
    /** Read-only state shared by account rows and collection monitors. */
    public function state(CollectionResourceRun|CollectionRun $run): string
    {
        if ($run->status->isTerminal() || $run->status->value === 'cancellation_requested') {
            return $run->status->value;
        }
        $datasets = $run->datasetRuns;
        $pending = $datasets->filter(fn ($d) => ! $d->status->isTerminal());
        $running = $pending->filter(fn ($d) => $d->status->value === 'running');
        if ($running->isNotEmpty()) {
            return $running->every(fn ($d) => ($d->last_activity_at ?? $d->started_at ?? $d->created_at)?->lt(now()->subMinutes(30)))
                ? 'delayed' : 'running';
        }
        if ($pending->isNotEmpty() && $pending->every(fn ($d) => $d->status->value === 'retrying' && $d->retry_at?->isFuture())) {
            return 'retrying';
        }
        $activity = $datasets->max(fn ($d) => ($d->last_activity_at ?? $d->created_at)?->getTimestamp())
            ?? ($run->last_activity_at ?? $run->created_at)?->getTimestamp();
        if ($activity && $activity < now()->subMinutes(30)->getTimestamp()) {
            return 'delayed';
        }
        return $pending->contains(fn ($d) => $d->status->value === 'queued') ? 'queued'
            : ($pending->contains(fn ($d) => $d->status->value === 'retrying') ? 'retrying' : $run->status->value);
    }

    public function label(CollectionResourceRun|CollectionRun $run): string
    {
        return __('resource-auto.state_'.$this->state($run));
    }
}
