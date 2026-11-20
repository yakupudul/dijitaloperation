<?php

namespace App\Panel\Support;

/** The source dictionary every brand shares: where a request came from. */
final class PanelSources
{
    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'web_form' => 'Web formu',
            'call' => 'Çağrı',
            'whatsapp' => 'WhatsApp',
            'instagram' => 'Instagram',
            'facebook' => 'Facebook',
            'google_ads' => 'Google Ads',
            'meta_ads' => 'Meta reklamı',
            'google_profile' => 'Google işletme profili',
            'referral' => 'Tavsiye',
            'walk_in' => 'Kapıdan gelen',
            'other' => 'Diğer',
        ];
    }

    public static function label(?string $key): string
    {
        return self::labels()[$key] ?? '—';
    }

    public static function normalize(?string $key): string
    {
        return array_key_exists((string) $key, self::labels()) ? (string) $key : 'other';
    }
}
