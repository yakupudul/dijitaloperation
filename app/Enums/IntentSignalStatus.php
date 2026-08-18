<?php

namespace App\Enums;

enum IntentSignalStatus: string
{
    case New = 'new';
    case Reviewed = 'reviewed';
    case ConvertedToProspect = 'converted_to_prospect';
    case Dismissed = 'dismissed';

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::New,
            self::Reviewed,
            self::ConvertedToProspect,
            self::Dismissed,
        ];
    }
}
