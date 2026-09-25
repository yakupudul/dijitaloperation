<?php

namespace App\Services\Brain\Clustering;

/** Turkish labels of cluster page types and search intents. */
final class PageTypes
{
    public const array LABELS = ['main' => 'Ana hizmet sayfası', 'landing' => 'Ayrı satış sayfası', 'support' => 'Destek içerik', 'faq' => 'SSS bloğu'];

    public const array INTENTS = ['transactional' => 'Satın alma', 'local' => 'Yerel', 'commercial' => 'Karşılaştırma', 'informational' => 'Bilgi', 'navigational' => 'Marka / site'];

    public static function label(?string $type): string
    {
        return self::LABELS[$type] ?? '—';
    }

    public static function intent(?string $intent): string
    {
        return self::INTENTS[$intent] ?? '—';
    }
}
