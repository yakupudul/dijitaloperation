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
        'auto_assign_score' => 0.60, // eşik üstü → otomatik ata; aksi hâlde "soru" görevi
        'ask_score' => 0.25,         // bu puanın altındaki adaylar hiç sorulmaz
        'max_candidates_in_question' => 4,
    ],

    'ai_visibility' => [
        'blocked_bots' => ['OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot', 'ClaudeBot', 'Googlebot', 'Google-Extended', 'PerplexityBot'],
    ],

    'quotas' => [
        'open_tasks_per_site' => 15,
        'create_per_site' => 6,
        'strengthen_per_site' => 6,
        'fix_per_site' => 6,
        'ai_visibility_per_site' => 3,
    ],

    'llm' => [
        'enabled' => env('SEO_TASKS_LLM_ENABLED', true),
        'max_candidates' => 12,
        'max_page_summaries' => 60,
    ],
];
