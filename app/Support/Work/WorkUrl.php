<?php

namespace App\Support\Work;

use InvalidArgumentException;

/**
 * Canonical Work detail URLs. Work is not a persistence domain.
 * Type must be explicit — never inferred from a colliding numeric id.
 */
final class WorkUrl
{
    public const string TYPE_TASK = 'task';

    public const string TYPE_CLIENT_REQUEST = 'client_request';

    public const string TYPE_APPROVAL = 'approval';

    public const string TYPE_RECURRING_REVIEW = 'recurring_review';

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_TASK,
            self::TYPE_CLIENT_REQUEST,
            self::TYPE_APPROVAL,
            self::TYPE_RECURRING_REVIEW,
        ];
    }

    public static function isType(string $type): bool
    {
        return in_array($type, self::types(), true);
    }

    /**
     * @return array{type: string, workId: string}
     */
    public static function parameters(string $type, int|string $workId): array
    {
        if (! self::isType($type)) {
            throw new InvalidArgumentException('Unknown work type: '.$type);
        }

        return [
            'type' => $type,
            'workId' => (string) $workId,
        ];
    }

    public static function show(string $type, int|string $workId): string
    {
        return route('operator.work.show', self::parameters($type, $workId));
    }
}
