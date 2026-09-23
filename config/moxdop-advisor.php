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

    'meta_ads' => [
        'window_days' => 30,
        'max_open' => 6,
        'min_account_spend' => 300,

        // Dönüşüm hedefli sayılan kampanya hedefleri / optimizasyon hedefleri.
        'conversion_objectives' => ['OUTCOME_LEADS', 'LEAD_GENERATION', 'OUTCOME_SALES', 'CONVERSIONS', 'MESSAGES'],
        'conversion_goals' => ['LEAD_GENERATION', 'QUALITY_LEAD', 'OFFSITE_CONVERSIONS', 'CONVERSATIONS', 'VALUE', 'APP_INSTALLS'],
        // Sonuç sayılan eylem türleri (sırayla ilk dolu olan kullanılır). Önce optimizasyon hedefi, yoksa kampanya hedefi.
        'result_actions' => [
            'LEAD_GENERATION' => ['lead', 'onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead'],
            'QUALITY_LEAD' => ['lead', 'onsite_conversion.lead_grouped'],
            'OUTCOME_LEADS' => ['lead', 'onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead'],
            'OFFSITE_CONVERSIONS' => ['offsite_conversion.fb_pixel_lead', 'lead', 'offsite_conversion.fb_pixel_purchase', 'purchase', 'offsite_conversion.fb_pixel_complete_registration', 'complete_registration', 'offsite_conversion.fb_pixel_schedule', 'schedule', 'offsite_conversion.fb_pixel_custom'],
            'OUTCOME_SALES' => ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase'],
            'VALUE' => ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase'],
            'CONVERSIONS' => ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase', 'offsite_conversion.fb_pixel_lead', 'complete_registration'],
            'CONVERSATIONS' => ['onsite_conversion.messaging_conversation_started_7d', 'onsite_conversion.messaging_first_reply'],
            'MESSAGES' => ['onsite_conversion.messaging_conversation_started_7d', 'onsite_conversion.messaging_first_reply'],
            'APP_INSTALLS' => ['app_install', 'omni_app_install'],
        ],

        // Kreatif yorgunluğu: son 7 gün vs önceki 7 gün. Sıklık = günlük sıklıkların gösterim ağırlıklı
        // ortalaması (haftalık tekil erişim toplanmıyor; haftalık sıklık bundan yüksektir).
        'fatigue' => [
            'min_spend_14d' => 200,
            'frequency_min' => 1.8,
            'ctr_drop' => 0.25,
            'max_items' => 3,
        ],
        // Kitle doygunluğu: kampanyanın son 7 gündeki günlük ortalama sıklığı.
        'saturation' => [
            'frequency_min' => 2.5,
            'min_spend_7d' => 200,
        ],
        // Öğrenme: Meta haftada ~50 optimizasyon olayı ister. Öğrenme durumu toplanmıyor; haftalık sonuç sayısından tahmin edilir.
        'learning' => [
            'weekly_results_target' => 50,
            'low_weekly_results' => 15,
            'min_spend_7d' => 150,
        ],
        'waste' => [
            'min_spend' => 200,
            'cpr_ratio' => 2.0,
        ],
        'pixel' => [
            'stale_days' => 3,
        ],
        // Maliyet sapmaları (hesap düzeyi yerleşim / cihaz / saat kırılımı; sonuç yok, tık üzerinden).
        'delivery' => [
            'min_share' => 0.10,
            'hour_min_share' => 0.05,
            'cpc_ratio' => 2.0,
            'ctr_ratio' => 0.4,
        ],
        'landing' => [
            'min_spend' => 100,
        ],
        'change' => [
            'window_days' => 14,
            'min_days_after' => 7,
            'cpa_increase' => 0.30,
            'min_conversions_before' => 5,
            'min_cost_before' => 200,
        ],
    ],

    'gbp' => [
        'max_open' => 6,
        'description_min_chars' => 250,
        // Aylık arama kelimeleri: son N ayın toplamı; "<15" eşik değerleri sayılmaz.
        'keyword_months' => 3,
        'keyword_min_impressions' => 30,
        'keyword_max_items' => 20,
        // Performans: son 28 gün vs önceki 28 gün.
        'performance_days' => 28,
        'action_drop' => 0.30,
        'min_actions_before' => 30,
        'action_metrics' => ['CALL_CLICKS', 'WEBSITE_CLICKS', 'BUSINESS_DIRECTION_REQUESTS', 'BUSINESS_CONVERSATIONS', 'BUSINESS_BOOKINGS'],
        // Fotoğraf tazeliği yalnızca profil gerçekten etkileşim alıyorsa önerilir.
        'photo_stale_days' => 120,
        'photo_min_actions_28d' => 20,
        // Puan trendi: son 90 gün vs önceki 365 gün.
        'rating_drop' => 0.3,
        'rating_min_reviews' => 5,
        'utm' => 'utm_source=google&utm_medium=organic&utm_campaign=gbp',
        'attribute_suggestions' => 8,
    ],

    // Faz 6 — kanallar arası öneriler (web sitesi varlığında çalışır; Google Ads / İşletme Profili / GSC verisini birleştirir).
    'cross' => [
        'gsc_days' => 90,
        'ads_term_min_conversions' => 2,
        'ads_term_max_items' => 20,
        'organic_good_position' => 10,
        'page_match_overlap' => 0.8,
        'brand_paid_min_cost' => 300,
        'brand_organic_max_position' => 1.5,
        'brand_organic_min_impressions' => 100,
        'gbp_keyword_min_impressions' => 40,
        'gbp_keyword_max_items' => 20,
    ],

    // Haftalık özet e-postası (varsayılan kapalı) ve "Yapıldı" sonrası ölçüm.
    'digest' => [
        'enabled' => env('ADVISOR_DIGEST_ENABLED', false),
        'weekly_time' => '08:00',
        'items' => 5,
    ],
    'measure' => [
        'after_days' => 28,
        'gsc_lag_days' => 3,
        'weekly_time' => '07:30',
    ],
];
