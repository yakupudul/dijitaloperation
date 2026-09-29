<?php

/*
 * Faz 5–6 URL karnesi (Website › Sayfa Karnesi): one verdict per URL from stored data. Thresholds of the
 * "url_*" standards (standards.json) live here; the standards themselves are switched on/off and their severity
 * changed in Kütüphane › Standartlar.
 */
return [
    // Stored HTML pages read per refresh (highest traffic / service pages first). Others get "veri yok".
    'html_pages' => (int) env('MOXDOP_URL_AUDIT_HTML_PAGES', 400),

    // Automatic refreshes (projection rebuilt, SEO plan, weekly) wait this long and are unique per website until
    // they start, so triggers arriving together queue one refresh.
    'debounce_seconds' => (int) env('MOXDOP_URL_AUDIT_DEBOUNCE_SECONDS', 120),

    // Weekly refresh (after the Monday SEO plan).
    'schedule' => [
        'weekly_day' => 1,
        'weekly_time' => '07:10',
    ],

    'thin_words' => 300,
    'strengthen_positions' => [5, 20],
    'strengthen_min_impressions' => 30,
    'decay_months' => 12,
    'decay_min_prev_clicks' => 10,
    'decay_ratio' => 0.7,
    'service_min_inlinks' => 3,
    'doorway_min_group' => 3,
    'title_similarity' => 0.88,

    // Slug words that only vary a location/service page ("ankara-implant-merkezi", "implant-yapan-yerler").
    'doorway_modifiers' => [
        'merkezi', 'merkez', 'klinigi', 'klinik', 'klinikleri', 'poliklinigi', 'poliklinik', 'yapan', 'yerler', 'yerleri',
        'yer', 'fiyatlari', 'fiyati', 'fiyat', 'ucreti', 'ucretleri', 'en', 'iyi', 'ucuz', 'tavsiye', 'onerilen', 'doktoru',
        'doktor', 'doktorlari', 'hekimi', 'hekim', 'uzmani', 'uzman', 'dis', 'disci', 'tedavisi', 'tedavi', 'hastanesi',
        'hastane', 'ozel', 'yakinimda', 'yakin', 'nerede', 'nerde', 'ile', 've', 'icin', 'de', 'da', 'hizmeti', 'hizmetleri',
        'fiyatlar', 'yaptiran', 'yaptirma', 'yaptirmak', 'olan', 'bolgesi', 'semti', 'civari', 'ilcesi',
    ],

    // Service pages sharing this much of their main text (MinHash estimate) are near-duplicates.
    'service_similarity' => 0.6,

    // GA4 session sources of AI answer engines (informational "AI kaynaklı ziyaret" standard). Measured from our own
    // GA4 only; MoxDOP never queries LLMs to measure citations (operator decision 2026-09-28).
    'ai_referrers' => [
        'chatgpt.com' => '/(^|\.)(chatgpt\.com|chat\.openai\.com)$|^chatgpt/',
        'perplexity.ai' => '/(^|\.)perplexity\.ai$|^perplexity/',
        'copilot' => '/(^|\.)copilot\.microsoft\.com$|^copilot/',
        'gemini' => '/(^|\.)gemini\.google\.com$|^gemini/',
        'claude.ai' => '/(^|\.)claude\.ai$/',
    ],

    // Junk/test URLs that should not be in Google.
    'junk_path_pattern' => '#(^|/)(test|deneme|sample-page|ornek-sayfa|hello-world|merhaba-dunya|taslak|draft|kopya|copy|eski|old|yedek|backup)(-\d+)?(/|$)#i',
];
