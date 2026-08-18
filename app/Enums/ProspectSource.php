<?php

namespace App\Enums;

enum ProspectSource: string
{
    case WhatsApp = 'whatsapp';
    case Phone = 'phone';
    case Email = 'email';
    case Referral = 'referral';
    case Website = 'website';
    case Manual = 'manual';
    case IntentRadar = 'intent_radar';
    case Other = 'other';

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::WhatsApp,
            self::Phone,
            self::Email,
            self::Referral,
            self::Website,
            self::Manual,
            self::IntentRadar,
            self::Other,
        ];
    }
}
