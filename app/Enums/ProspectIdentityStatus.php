<?php

namespace App\Enums;

enum ProspectIdentityStatus: string
{
    case Verified = 'verified';
    case Partial = 'partial';
    case Unknown = 'unknown';

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::Verified,
            self::Partial,
            self::Unknown,
        ];
    }
}
