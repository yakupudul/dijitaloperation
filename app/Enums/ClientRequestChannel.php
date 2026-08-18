<?php

namespace App\Enums;

enum ClientRequestChannel: string
{
    case Meeting = 'meeting';
    case Email = 'email';
    case Whatsapp = 'whatsapp';
    case Phone = 'phone';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Meeting => 'Meeting',
            self::Email => 'Email',
            self::Whatsapp => 'WhatsApp',
            self::Phone => 'Phone',
            self::Other => 'Other',
        };
    }
}
