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

    /** İçerik plan item (new page / post or update). */
    public const string CONTENT = 'content';

    /** Rakipler suggestion (competitor analysis); on an existing page "AI ile yap" writes it like a missing topic. */
    public const string COMPETITOR = 'rakip';

    /** Types "AI ile yap" can write: fields (title / description, links, schema) or page HTML (sections, FAQ). */
    public const array APPLICABLE = ['title_description', 'missing_topic', 'internal_links', 'technical_seo', 'conversion', 'wrong_intent', self::COMPETITOR, SiteAudit::TYPE];

    public static function label(string $type): string
    {
        return match ($type) {
            self::CONTENT => 'içerik',
            self::COMPETITOR => 'rakip',
            ClusterOverlaps::TYPE => 'küme çakışması',
            ImageAlts::TYPE => 'görsel alt metni',
            SiteAudit::TYPE => 'SEO başlık / açıklama',
            Clarity\ClarityRules::TYPE => 'ziyaretçi davranışı',
            default => self::ANALYSIS[$type] ?? $type,
        };
    }

    public static function applicable(string $type): bool
    {
        return in_array($type, self::APPLICABLE, true);
    }
}
