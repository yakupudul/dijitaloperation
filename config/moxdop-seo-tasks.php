<?php

/*
|--------------------------------------------------------------------------
| SEO Görevleri — kural sabitleri
|--------------------------------------------------------------------------
| Motorun tüm eşikleri burada. "Çok kasıyor / az kasıyor" durumunda kod
| değil bu sayılar değişir. Bkz. docs/product/SEO_TASKS.md
*/

return [
    'enabled' => env('SEO_TASKS_ENABLED', true),

    // Kuyruk: mevcut async iş kuyruğu ile aynı bağlantı.
    'queue_connection' => env('SEO_TASKS_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
    'queue' => env('SEO_TASKS_QUEUE', 'default'),

    // Haftalık zamanlayıcı (pazartesi sabahı) — routes/console.php içinde kullanılır.
    'schedule' => [
        'enabled' => env('SEO_TASKS_SCHEDULE_ENABLED', true),
        'weekly_day' => 1,          // 1 = Pazartesi
        'weekly_time' => '06:30',
        'sites_per_tick' => 25,     // tek zamanlayıcı geçişinde kuyruğa alınan site sayısı
    ],

    'window' => [
        'gsc_days' => 90,           // GSC sorgu×sayfa penceresi
        'ga4_days' => 90,
    ],

    // Pozisyona göre beklenen organik CTR (masaüstü/mobil karışık, muhafazakâr).
    // Anahtar: tam sayı pozisyon; aradaki değerler doğrusal enterpole edilir.
    'ctr_curve' => [
        1 => 0.28, 2 => 0.15, 3 => 0.10, 4 => 0.07, 5 => 0.05,
        6 => 0.04, 7 => 0.03, 8 => 0.025, 9 => 0.02, 10 => 0.018,
        11 => 0.012, 12 => 0.010, 13 => 0.009, 14 => 0.008, 15 => 0.007,
        16 => 0.006, 17 => 0.005, 18 => 0.005, 19 => 0.004, 20 => 0.004,
    ],
    'ctr_curve_target_position' => 3, // "güçlendir" için hedef pozisyon (beklenen ek tıklama hesabı)

    'strengthen' => [
        'position_min' => 5.0,
        'position_max' => 20.0,
        'min_impressions' => 100,        // 90 günde
        'no_page_above_position' => 5.0, // hiçbir sayfa bu pozisyonun üstünde olmamalı
        'thin_service_page_words' => 800,
        'cannibalization_share_min' => 0.25, // ikinci sayfa gösterim payı bu oranı geçerse "niyeti ayır"
        'max_per_priority_service' => 2,
    ],

    'create' => [
        'min_impressions' => 30,     // 90 günde; sayfasız sorgu adayı
        'ranked_position_max' => 20, // bu pozisyonda sayfası varsa "oluştur" değil "güçlendir"
        'min_per_site' => 4,         // haftalık asgari içerik önerisi
        'max_per_site' => 6,
        'bucket_max_queries' => 12,  // bir içerik briefinin kapsayacağı azami sorgu
        'target_words' => [
            'service' => 1200,
            'guide' => 1500,
            'faq' => 800,
            'location' => 900,
        ],
    ],

    'fix' => [
        'thin_page_words' => 150,
        'redirect_chain_min' => 2,
        'severities_that_become_tasks' => ['critical', 'high', 'warning', 'medium'],
    ],

    'service_page' => [
        'auto_assign_score' => 0.55, // eşik üstü → otomatik ata
        'auto_assign_min_with_margin' => 0.40, // ya da en iyi aday bu puanın üstünde ve ikinciden net ayrışıyorsa
        'auto_assign_margin' => 0.15,
        'ask_score' => 0.25,         // bu puanın altındaki adaylar hiç sorulmaz
        'max_candidates_in_question' => 4,
    ],

    // Saklı HTML okuma (yeni HTTP isteği yok): çift H1, alt metni, JSON-LD sameAs, metin özeti.
    'html' => [
        'max_pages' => 150,        // koşu başına okunacak azami sayfa (ana sayfa + en çok gösterim alanlar önce)
        'excerpt_pages' => 8,      // site anlama için metin özeti alınan sayfa sayısı
        'excerpt_chars' => 1500,
    ],

    // Markada hizmet tanımlı değilse: siteden hizmet/konu çıkarımı (AI, yoksa kural tabanlı).
    'understanding' => [
        'cache_days' => 28,        // aynı çıkarım bu süre boyunca yeniden kullanılır
        'max_services' => 8,
        'fallback_services' => 6,  // AI yoksa en çok gösterim alan sayfalardan
        'priority_count' => 3,     // çıkarılan hizmetlerden kaç tanesi "yıldızlı" sayılır
        // Marka hizmetleri siteye uyuyor mu? GSC gösterimi bu sayının üstündeyse, hizmetlerin payı
        // fit_min_share altında ve hiçbir sayfa başlığı/H1 hizmet adını içermiyorsa site kendi verisinden anlaşılır.
        'fit_min_impressions' => 200,
        'fit_min_share' => 0.03,
        'excluded_path_patterns' => ['blog', 'haber', 'makale', 'yazi', 'category', 'kategori', 'etiket', 'tag', 'author', 'iletisim', 'hakkimizda', 'kvkk', 'gizlilik', 'cerez', 'contact', 'about', 'privacy', 'cookie', 'sepet', 'cart', 'checkout', 'hesabim', 'account', 'login', 'wp-'],
    ],

    'ai_visibility' => [
        'blocked_bots' => ['OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot', 'ClaudeBot', 'Googlebot', 'Google-Extended', 'PerplexityBot'],
    ],

    // Markanın hizmet verdiği yerler dışındaki konumlu aramalar içerik önerisine girmez; toplam gösterimi
    // bu eşiği geçen konumlar için site başına tek karar kartı açılır.
    'locations' => [
        'out_of_area_min_impressions' => 50,
    ],

    'quotas' => [
        'open_tasks_per_site' => 15,
        'create_per_site' => 6,
        'strengthen_per_site' => 6,
        'fix_per_site' => 6,
        'ai_visibility_per_site' => 4,
    ],

    'llm' => [
        'enabled' => env('SEO_TASKS_LLM_ENABLED', true),
        'max_candidates' => 12,
        'max_page_summaries' => 60,
        'timeout' => 120,          // saniye; tek yapılandırılmış çağrı
    ],
];
