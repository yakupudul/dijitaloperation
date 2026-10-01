<?php

namespace App\Support\Ai;

use App\Services\Ai\AiUsageRecorder;
use App\Services\Prompts\PromptRegistry;
use Illuminate\Support\Str;
use Throwable;

/**
 * Short Turkish names of AI operations for live lists and the prompt info window. Unknown operations fall back to the
 * prompt registry purpose (shortened), then to the key itself.
 */
final class AiOperationLabels
{
    /** @var array<string, string> */
    private const array LABELS = [
        'brand_setup.assistant' => 'Marka kurulum asistanı',
        'brand.candidates' => 'Marka adaylarını grupla',
        'brand.services' => 'Sayfalardan hizmet çıkar',
        'queries.filter_rules' => 'Sorgu filtre kuralları',
        'queries.plan_sectors' => 'AI ile planla · sektörler',
        'queries.plan_services' => 'AI ile planla · hizmetler',
        'queries.plan_filters' => 'AI ile planla · filtreler',
        'queries.scan_filters' => 'Sorgularda filtre kelimesi tara',
        'queries.assign_services' => 'Sorgulara hizmet öner',
        'queries.cluster' => 'Sorgu kümeleme',
        'queries.cluster_review' => 'Küme gözden geçirme',
        'content.ideas' => 'Yeni içerik fikri',
        'gbp.review_reply' => 'Yorum yanıt taslağı',
        'gbp.services_compare' => 'İşletme Profili hizmet karşılaştırma',
        'gbp.description' => 'İşletme Profili açıklama önerisi',
        'gbp.post_from_page' => 'Sayfadan gönderi yaz',
        'google_ads.search_terms' => 'Google Ads arama terimleri',
        'google_ads.structure' => 'Google Ads kampanya yapısı',
        'google_ads.ad_texts' => 'Google Ads reklam metni',
        'meta.creatives' => 'Meta kreatif önerisi',
        'meta.structure' => 'Meta kampanya yapısı',
        'meta.landing' => 'Meta form / açılış sayfası',
        'insights.alert_cause' => 'Uyarı nedeni',
        'insights.technical_tasks' => 'Teknik görevler',
        'competitors.classify' => 'Rakip sınıflandırma',
        'competitors.analyze' => 'Rakip analizi',
        'backlinks.sources' => 'Backlink kaynak önerisi',
        'site.page_categories' => 'Sayfaları sınıflandır',
        'site.service_pages' => 'Hizmet ↔ sayfa eşleme',
        'site.cluster_pages' => 'Küme ↔ sayfa eşleme',
        'site.cluster_match' => 'Küme ↔ içerik eşleştirme',
        'site.cluster_gaps' => 'Küme eksikleri',
        'queries.ai_queries' => 'AI asistanı sorguları',
        'site.page_summary' => 'Sayfa özeti',
        'site.url_analysis' => 'URL analizi',
        'site.apply_change' => 'AI ile yap (site değişikliği)',
        'site.standard_from_decision' => 'Karardan standart önerisi',
        'site.weekly_content' => 'Haftalık içerik önerisi',
        'site.content_discovery' => 'İçerik fırsatı keşfi',
        'site.write_article' => 'Makale taslağı',
        AiUsageRecorder::TRIAL_ROUTE => 'Prompt denemesi (Örnekte dene)',
    ];

    public static function for(?string $operation, ?string $agent = null): string
    {
        if ($operation === null || $operation === '') {
            return $agent !== null && $agent !== '' ? 'AI · '.$agent : 'AI işlemi';
        }
        if (isset(self::LABELS[$operation])) {
            return self::LABELS[$operation];
        }
        if (str_starts_with($operation, 'analyst.')) {
            return 'Kanal analisti · '.Str::after($operation, 'analyst.');
        }
        try {
            $registry = app(PromptRegistry::class);
            if ($registry->has($operation)) {
                $purpose = trim($registry->definitions()[$operation]['purpose'] ?? '');
                if ($purpose !== '') {
                    return Str::limit($purpose, 80);
                }
            }
        } catch (Throwable) {
            // Label only: fall back to the key.
        }

        return $operation;
    }
}
