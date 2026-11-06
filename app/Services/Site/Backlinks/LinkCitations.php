<?php

namespace App\Services\Site\Backlinks;

use App\Models\LinkSource;

/**
 * Yerel künye motoru (Backlink hizmeti mimarisi, motor 2): the national listings every local business should have, with
 * the same name, phone and address as its Business Profile (maps services; health platforms for health brands only).
 * They join the shared pool once as checked sources; city and sector directories come from the AI proposal of each
 * brand ("künye keşfi") when the pool runs dry, and pass the quality gate first.
 */
final class LinkCitations
{
    /** domain => [name, url, kind, apply_via, fee] */
    public const array NATIONAL = [
        'bingplaces.com' => ['Bing Places', 'https://www.bingplaces.com/', 'harita', 'form', 'ucretsiz'],
        'businessconnect.apple.com' => ['Apple Business Connect (Apple Haritalar)', 'https://businessconnect.apple.com/', 'harita', 'form', 'ucretsiz'],
        'yandex.com.tr' => ['Yandex Haritalar · İşletme ekle', 'https://yandex.com.tr/sprav/', 'harita', 'form', 'ucretsiz'],
        'doktortakvimi.com' => ['Doktortakvimi', 'https://www.doktortakvimi.com/', 'saglik', 'form', 'teyit'],
        'doktorsitesi.com' => ['Doktorsitesi', 'https://www.doktorsitesi.com/', 'saglik', 'form', 'teyit'],
    ];

    public static function ensure(): void
    {
        foreach (self::NATIONAL as $domain => [$name, $url, $kind, $via, $fee]) {
            LinkSource::query()->firstOrCreate(['domain' => $domain], ['name' => $name, 'url' => $url, 'kind' => $kind, 'apply_via' => $via, 'fee' => $fee,
                'quality' => 'uygun', 'quality_note' => 'Ulusal künye listesi', 'origin' => 'manual', 'checked_at' => now()]);
        }
    }
}
