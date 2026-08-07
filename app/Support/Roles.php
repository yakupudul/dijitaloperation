<?php

namespace App\Support;

final class Roles
{
    public const string ADMIN = 'Admin';

    public const string TEAM_MEMBER = 'Team Member';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ADMIN,
            self::TEAM_MEMBER,
        ];
    }
}
