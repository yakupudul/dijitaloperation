<?php

namespace App\Services\DomainEvents;

use App\Models\DomainEvent;
use App\Services\Activity\ActivityProjector;
use App\Services\Notifications\NotificationProjector;

/**
 * Orchestrates Activity + Notification projection for a DomainEvent.
 * Idempotent: projectors unique on domain_event_id / (event, recipient, kind).
 */
final class MeaningfulEventProjector
{
    public function __construct(
        private readonly ActivityProjector $activity,
        private readonly NotificationProjector $notifications,
    ) {}

    public function project(DomainEvent $event): void
    {
        $this->activity->project($event);
        $this->notifications->project($event);
    }
}
