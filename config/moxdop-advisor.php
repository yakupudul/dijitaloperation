<?php

/*
|--------------------------------------------------------------------------
| Kanal danışmanı — kural sabitleri (Faz 3: Google Ads)
|--------------------------------------------------------------------------
| Motorun eşikleri burada. Çok / az öneri çıkıyorsa kod değil bu sayılar
| değişir. Bkz. docs/product/ADS_ADVISOR.md
*/

return [
    'enabled' => env('ADVISOR_ENABLED', true),
    'queue_connection' => env('ADVISOR_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
    'queue' => env('ADVISOR_QUEUE', 'default'),

    'schedule' => [
        'enabled' => env('ADVISOR_SCHEDULE_ENABLED', true),
        'weekly_day' => 1,          // Pazartesi
        'weekly_time' => '07:00',   // SEO planından sonra
    ],

    'google_ads' => [
        'window_days' => 30,
        // Aynı anda açık tutulan en fazla öneri (az ama gerçek). Kritik olanlar kotaya takılmaz.
        'max_open' => 6,
        // Hesap harcaması bunun altındaysa danışman sessiz kalır (anlamlı sinyal yok).
        'min_account_cost' => 300,

        'negatives' => [
            'min_clicks' => 3,
            // Dönüşümsüz terim için harcama eşiği: max(sabit, hesap CPA'sının oranı).
            'min_cost' => 40,
            'cpa_share' => 0.5,
            'max_terms' => 40,
            // Tek kelime negatif önerisi: en az bu kadar farklı israf terimde geçen ve dönüşüm getiren hiçbir terimde geçmeyen kelime.
            'word_min_terms' => 3,
            'stop_words' => ['ve', 'ile', 'için', 'de', 'da', 'en', 'bir', 'mi', 'mı', 'the', 'and', 'for', 'in'],
            // Bilinen düşük niyet kelimeleri: öneri gerekçesinde işaretlenir.
            'low_intent_words' => ['ücretsiz', 'bedava', 'nedir', 'nasıl', 'iş ilanı', 'iş ilanları', 'maaş', 'staj', 'kurs', 'eğitimi', 'pdf', 'wikipedia', 'ekşi', 'şikayet', 'free', 'jobs', 'salary'],
        ],

        'keyword_opportunities' => [
            'min_conversions' => 2,
            'max_terms' => 25,
        ],

        'budget' => [
            'lost_is_budget_min' => 0.20,   // bütçe kaybı ≥ %20
            'min_conversions' => 3,
            'cpa_ratio_max' => 1.0,         // CPA ≤ hesap/hedef CPA
            'waste_min_cost_ratio' => 2.0,  // dönüşümsüz kampanya: harcama ≥ 2 × hesap CPA
            'waste_min_cost' => 150,
        ],

        'measurement' => [
            'min_clicks_without_conversions' => 150,
            'ga4_ratio_low' => 0.5,
            'ga4_ratio_high' => 2.0,
            'ga4_min_conversions' => 10,
            'tracking_sessions_ratio_min' => 0.3, // GA4 google/cpc oturumu / Ads tıklaması
            'low_intent_categories' => ['PAGE_VIEW', 'ENGAGEMENT', 'OUTBOUND_CLICK', 'GET_DIRECTIONS', 'DEFAULT'],
            'lead_categories' => ['SUBMIT_LEAD_FORM', 'CONTACT', 'PHONE_CALL_LEAD', 'IMPORTED_LEAD', 'QUALIFIED_LEAD', 'CONVERTED_LEAD', 'BOOK_APPOINTMENT', 'REQUEST_QUOTE', 'SIGNUP'],
        ],

        'landing' => [
            'min_cost' => 100,
            'speed_score_max' => 4,      // Google Ads açılış sayfası hız puanı (1–10)
            'mobile_friendly_min' => 50,
        ],

        'ads' => [
            'weak_strengths' => ['POOR', 'AVERAGE'],
            'min_cost' => 50,
        ],

        'quality' => [
            'max_score' => 4,
            'min_cost' => 50,
        ],

        'change' => [
            'window_days' => 14,          // değişiklikten önce/sonra karşılaştırma
            'min_days_after' => 7,
            'cpa_increase' => 0.30,
            'min_conversions_before' => 5,
            'min_cost_before' => 200,
        ],

        // Google'ın kendi önerilerinden yalnızca bunlar gösterilir. Bütçe artırma, geniş eşleme ve
        // otomatik teklif "dürtmeleri" bilerek dışarıda.
        'google_recommendations' => [
            'SITELINK_ASSET' => 'Site bağlantısı ekle',
            'SITELINK_EXTENSION' => 'Site bağlantısı ekle',
            'CALLOUT_ASSET' => 'Açıklama metni ekle',
            'CALLOUT_EXTENSION' => 'Açıklama metni ekle',
            'STRUCTURED_SNIPPET_ASSET' => 'Yapılandırılmış snippet ekle',
            'CALL_ASSET' => 'Arama öğesi ekle',
            'CALL_EXTENSION' => 'Arama öğesi ekle',
            'LEAD_FORM_ASSET' => 'Potansiyel müşteri formu ekle',
            'RESPONSIVE_SEARCH_AD' => 'Duyarlı arama reklamı ekle',
            'RESPONSIVE_SEARCH_AD_ASSET' => 'Reklama başlık/açıklama ekle',
            'RESPONSIVE_SEARCH_AD_IMPROVE_AD_STRENGTH' => 'Reklam gücünü artır',
            'IMPROVE_GOOGLE_TAG_COVERAGE' => 'Google etiketini tüm sayfalara kur',
            'UPGRADE_LOCAL_CAMPAIGN_TO_PERFORMANCE_MAX' => null,
            'KEYWORD' => null,
            'CAMPAIGN_BUDGET' => null,
        ],
    ],
];
