<?php

namespace App\Services\Brain\Methods;

/**
 * The page features the method engine can learn from, with how to read them and what to tell a brand that lacks
 * them. Measured facts come from the crawl (`features`), checklist items from the AI reading (`ai_features`).
 * `{t}` is replaced by the threshold learned from the successful pages of the cohort.
 */
final class MethodCatalog
{
    /** @var array<string, array{kind: string, source: string, label: string, advice: string}> */
    public const array FEATURES = [
        'faq' => ['kind' => 'bool', 'source' => 'features', 'label' => 'Sayfada SSS bölümü var', 'advice' => 'Sayfaya konunun sık sorulan sorularını cevaplarıyla ekleyin (FAQPage yapılandırılmış verisiyle).'],
        'medical_schema' => ['kind' => 'bool', 'source' => 'features', 'label' => 'Konuya uygun yapılandırılmış veri var', 'advice' => 'Sayfaya konuya uygun yapılandırılmış veri ekleyin (ör. MedicalProcedure, FAQPage, işletme türü).'],
        'mentions_place' => ['kind' => 'bool', 'source' => 'features', 'label' => 'Hizmet verilen yer sayfada geçiyor', 'advice' => 'Hizmet verilen ilçe / şehri ve ulaşım bilgisini sayfada belirtin.'],
        'has_price' => ['kind' => 'bool', 'source' => 'features', 'label' => 'Fiyat / ücret bilgisi var', 'advice' => 'Sayfada fiyat veya ücret aralığı bilgisi verin.'],
        'words' => ['kind' => 'num', 'source' => 'features', 'label' => 'İçerik uzunluğu ≥ {t} kelime', 'advice' => 'İçeriği konuyu tam kapsayacak şekilde genişletin; bu konuda başarılı sayfalar yaklaşık {t} kelime ve üzeri.'],
        'h2_count' => ['kind' => 'num', 'source' => 'features', 'label' => 'Alt başlık sayısı ≥ {t}', 'advice' => 'İçeriği soru biçimli alt başlıklarla bölümlere ayırın; başarılı sayfalarda en az {t} alt başlık var.'],
        'coverage' => ['kind' => 'num', 'source' => 'features', 'label' => 'Konu sorgularının ≥ %{t}\'ini cevaplıyor', 'advice' => 'Kümenin sayfada cevaplanmayan sorgularını ekleyin; başarılı sayfalar konunun sorgularının en az %{t}\'ini cevaplıyor.'],
        'internal_links' => ['kind' => 'num', 'source' => 'features', 'label' => 'İç bağlantı ≥ {t}', 'advice' => 'Sayfaya ve sayfadan ilgili konulara iç bağlantıları artırın (başarılı sayfalarda ≥ {t}).'],
        'answer_first' => ['kind' => 'bool', 'source' => 'ai', 'label' => 'İlk paragraf soruyu doğrudan cevaplıyor', 'advice' => 'Sayfanın ilk paragrafında hizmetin ne olduğunu ve kimler için olduğunu kısa ve doğrudan cevaplayın (AI yanıtlarında alıntılanan bölüm genelde burası).'],
        'question_headings' => ['kind' => 'bool', 'source' => 'ai', 'label' => 'Soru biçimli başlıklar ve altında net cevaplar', 'advice' => 'İnsanların aradığı soruları alt başlık yapın, her birinin altına kendi başına anlaşılır kısa bir cevap yazın.'],
        'process_steps' => ['kind' => 'bool', 'source' => 'ai', 'label' => 'Süreç adım adım anlatılıyor', 'advice' => 'Tedavi / hizmet sürecini adım adım anlatın.'],
        'duration_recovery' => ['kind' => 'bool', 'source' => 'ai', 'label' => 'Süre ve iyileşme anlatılıyor', 'advice' => 'İşlemin süresini ve iyileşme sürecini açıklayın.'],
        'risks_side_effects' => ['kind' => 'bool', 'source' => 'ai', 'label' => 'Riskler / kimlere uygun olmadığı dürüstçe anlatılıyor', 'advice' => 'Olası riskleri, yan etkileri ve kimlere uygun olmadığını dürüstçe anlatın.'],
        'candidacy' => ['kind' => 'bool', 'source' => 'ai', 'label' => 'Kimlerin uygun aday olduğu anlatılıyor', 'advice' => 'Kimlerin bu hizmet için uygun aday olduğunu açıklayın.'],
        'expert_shown' => ['kind' => 'bool', 'source' => 'ai', 'label' => 'Sayfayı yazan / kontrol eden uzman görünüyor', 'advice' => 'Sayfayı yazan veya kontrol eden uzmanın adını ve unvanını gösterin (sağlıkta güven sinyali).'],
        'review_date' => ['kind' => 'bool', 'source' => 'ai', 'label' => 'Güncelleme / kontrol tarihi görünüyor', 'advice' => 'Sayfada son güncelleme veya uzman kontrol tarihini gösterin.'],
        'sources_cited' => ['kind' => 'bool', 'source' => 'ai', 'label' => 'Kaynak / istatistik veriliyor', 'advice' => 'İddiaları güvenilir kaynak veya istatistiklerle destekleyin.'],
        'clear_next_step' => ['kind' => 'bool', 'source' => 'ai', 'label' => 'Net bir sonraki adım var', 'advice' => 'Sayfada net bir sonraki adım sunun (arama, form, WhatsApp, randevu).'],
        'local_context' => ['kind' => 'bool', 'source' => 'ai', 'label' => 'Konum ve ulaşım bilgisi var', 'advice' => 'Hizmet verilen konumu ve nasıl ulaşılacağını belirtin.'],
    ];

    /**
     * The value of one feature for a page, or null when it was not measured.
     *
     * @param  array<string, mixed>  $features
     * @param  array<string, mixed>|null  $ai
     */
    public static function value(string $feature, array $features, ?array $ai): float|bool|null
    {
        $def = self::FEATURES[$feature];
        $bag = $def['source'] === 'ai' ? $ai : $features;
        if ($bag === null || ! array_key_exists($feature, $bag) || $bag[$feature] === null) {
            return null;
        }

        return $def['kind'] === 'bool' ? (bool) $bag[$feature] : (float) $bag[$feature];
    }

    public static function text(string $template, ?float $threshold, string $feature): string
    {
        $shown = $threshold === null ? '' : ($feature === 'coverage' ? (string) (int) round($threshold * 100) : (string) (int) round($threshold));

        return str_replace('{t}', $shown, $template);
    }
}
