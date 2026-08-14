<?php

namespace App\Enums;

enum ClientRequestStatus: string
{
    case New = 'new';
    case Triaged = 'triaged';
    case Planned = 'planned';
    case WaitingOnClient = 'waiting_on_client';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Declined = 'declined';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [
                self::Triaged,
                self::Planned,
                self::WaitingOnClient,
                self::Declined,
                self::Done,
            ],
            self::Triaged => [
                self::Planned,
                self::WaitingOnClient,
                self::InProgress,
                self::Declined,
                self::Done,
            ],
            self::Planned => [
                self::WaitingOnClient,
                self::InProgress,
                self::Declined,
                self::Done,
            ],
            self::WaitingOnClient => [
                self::Planned,
                self::InProgress,
                self::Declined,
                self::Done,
            ],
            self::InProgress => [
                self::WaitingOnClient,
                self::Planned,
                self::Declined,
                self::Done,
            ],
            self::Done, self::Declined => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Done || $this === self::Declined;
    }

    public function waitingOnClient(): bool
    {
        return $this === self::WaitingOnClient;
    }
}
