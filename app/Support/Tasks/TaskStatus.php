<?php

namespace App\Support\Tasks;

/**
 * Controlled Task workflow statuses (human-operated).
 */
final class TaskStatus
{
    public const string OPEN = 'open';

    public const string IN_PROGRESS = 'in_progress';

    public const string BLOCKED = 'blocked';

    public const string COMPLETED = 'completed';

    public const string CANCELLED = 'cancelled';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::OPEN,
            self::IN_PROGRESS,
            self::BLOCKED,
            self::COMPLETED,
            self::CANCELLED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function active(): array
    {
        return [
            self::OPEN,
            self::IN_PROGRESS,
            self::BLOCKED,
        ];
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::OPEN => 'Open',
            self::IN_PROGRESS => 'In progress',
            self::BLOCKED => 'Blocked',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
            default => str($status)->replace('_', ' ')->title()->toString(),
        };
    }
}
