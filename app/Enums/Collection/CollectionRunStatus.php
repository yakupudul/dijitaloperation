<?php

namespace App\Enums\Collection;

enum CollectionRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Retrying = 'retrying';
    case Partial = 'partial';
    case Completed = 'completed';
    case Failed = 'failed';
    case CancellationRequested = 'cancellation_requested';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';
    case NotEligible = 'not_eligible';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed,
            self::Failed,
            self::Partial,
            self::Cancelled,
            self::Skipped,
            self::NotEligible => true,
            default => false,
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Queued => [
                self::Running,
                self::Failed,
                self::Cancelled,
                self::CancellationRequested,
                self::NotEligible,
                self::Skipped,
            ],
            self::Running => [
                self::Completed,
                self::Failed,
                self::Partial,
                self::Retrying,
                self::CancellationRequested,
                self::Cancelled,
            ],
            self::Retrying => [
                self::Running,
                self::Queued,
                self::Failed,
                self::Cancelled,
                self::CancellationRequested,
            ],
            self::Failed => [
                self::Queued, // explicit resume only
            ],
            self::Partial => [
                self::Queued, // explicit resume of non-terminal child work only at dataset level
            ],
            self::CancellationRequested => [
                self::Cancelled,
                self::Partial,
                self::Completed,
                self::Failed,
            ],
            self::Completed,
            self::Cancelled,
            self::Skipped,
            self::NotEligible => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
