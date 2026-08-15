<?php

namespace App\Enums;

enum RecurringReviewScopeKind: string
{
    case Customer = 'customer';
    case Brand = 'brand';
    case DigitalAsset = 'digital_asset';

    public function requiresBrand(): bool
    {
        return $this !== self::Customer;
    }

    public function requiresDigitalAsset(): bool
    {
        return $this === self::DigitalAsset;
    }
}
