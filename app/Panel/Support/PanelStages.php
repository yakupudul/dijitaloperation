<?php

namespace App\Panel\Support;

/** The stage dictionary every brand shares: one list for the table, the board, the reports and the exports. */
final class PanelStages
{
    public const string NEW = 'new';

    public const string REACHED = 'reached';

    public const string APPOINTMENT = 'appointment';

    public const string CAME = 'came';

    public const string CUSTOMER = 'customer';

    public const string LOST = 'lost';

    public const string NOT_LEAD = 'not_lead';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::NEW => 'Yeni',
            self::REACHED => 'Ulaşıldı',
            self::APPOINTMENT => 'Randevu verildi',
            self::CAME => 'Geldi',
            self::CUSTOMER => 'Müşteri',
            self::LOST => 'Olumsuz',
            self::NOT_LEAD => 'Aday değil',
        ];
    }

    /** @return list<string> the stages a person moves through, in order */
    public static function open(): array
    {
        return [self::NEW, self::REACHED, self::APPOINTMENT, self::CAME];
    }

    public static function label(?string $key): string
    {
        return self::labels()[$key] ?? '—';
    }

    public static function tone(?string $key): string
    {
        return match ($key) {
            self::NEW => 'look',
            self::CUSTOMER => 'ok',
            self::LOST => 'critical',
            default => 'info',
        };
    }

    /** @return array<string, string> */
    public static function lostReasons(): array
    {
        return [
            'price' => 'Fiyat',
            'far' => 'Uzak',
            'other_place' => 'Başka yeri seçti',
            'unreachable' => 'Ulaşılamadı',
            'info_only' => 'Yalnız bilgi istedi',
            'unsuitable' => 'Uygun değil',
        ];
    }
}
