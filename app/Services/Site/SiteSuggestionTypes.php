<?php

namespace App\Services\Site;

use App\Services\Repair\SiteAudit;

/**
 * Suggestion types of the website screen (stored in `suggestions.action_type`, channel `search`).
 */
final class SiteSuggestionTypes
{
    /** URL analizi types (spec order). */
    public const array ANALYSIS = [
        'wrong_intent' => 'yanlış niyet',
        'missing_topic' => 'eksik konu / yanıtsız soru',
        'title_description' => 'başlık / açıklama',
        'internal_links' => 'iç bağlantı',
        'duplicate_content' => 'yinelenen / çakışan içerik',
        'service_location' => 'hizmet–lokasyon uyumsuzluğu',
        'conversion' => 'dönüşüm adımı',
        'technical_seo' => 'teknik SEO / yapılandırılmış veri',
    ];

    /**
     * Plain names the operator reads (yakup, 2026-10-19: "küme çakışması, 301, ayrıştır… ne olduğunu anlamadım"): every
     * screen names a type with these words; ANALYSIS stays the AI's own definition of each type.
     */
    public const array PLAIN = [
        'wrong_intent' => 'Sayfa aranan şeye cevap vermiyor',
        'missing_topic' => 'Sayfada cevapsız kalan sorular',
        'title_description' => 'Google\'da görünen başlık ve açıklama',
        'internal_links' => 'Sayfalar birbirine bağlanmamış',
        'duplicate_content' => 'Tekrar eden metin',
        'service_location' => 'Yanlış şehir ya da hizmet',
        'conversion' => 'Arama ve randevu tuşu zayıf',
        'technical_seo' => 'Google\'a verilen işletme bilgisi eksik',
        'content' => 'Yeni yazı',
        'rakip' => 'Rakiplerde olup sizde olmayanlar',
        'cluster_overlap' => 'Aynı konuyu anlatan sayfalar',
        'image_alt' => 'Resimlerin açıklaması yok',
        'translation_fix' => 'Bozuk çeviri',
        'seo_fields' => 'Google\'da görünen başlık ve açıklama',
        'clarity' => 'Ziyaretçilerin takıldığı yerler',
    ];

    /** Types the system cannot write: the operator fixes them on the site and says "Yaptım". */
    public const array MANUAL = ['duplicate_content', 'service_location', 'clarity'];

    /** İçerik plan item (new page / post or update). */
    public const string CONTENT = 'content';

    /** Rakipler suggestion (competitor analysis); on an existing page "AI ile yap" writes it like a missing topic. */
    public const string COMPETITOR = 'rakip';

    /** Types "AI ile yap" can write: fields (title / description, links, schema) or page HTML (sections, FAQ). */
    public const array APPLICABLE = ['title_description', 'missing_topic', 'internal_links', 'technical_seo', 'conversion', 'wrong_intent', self::COMPETITOR, SiteAudit::TYPE];

    public static function label(string $type): string
    {
        return self::PLAIN[$type] ?? self::ANALYSIS[$type] ?? $type;
    }

    public static function applicable(string $type): bool
    {
        return in_array($type, self::APPLICABLE, true);
    }
}
