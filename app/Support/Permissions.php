<?php

namespace App\Support;

final class Permissions
{
    /**
     * Minimal core permission. Modules may register additional permissions later.
     */
    public const string ACCESS_APP = 'access.app';

    /**
     * @return list<string>
     */
    public static function core(): array
    {
        return [
            self::ACCESS_APP,
        ];
    }
}
