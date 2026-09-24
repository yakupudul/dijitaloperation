<?php

use App\Services\Intel\MapGridService;
use App\Services\Intel\ProspectAuditService;
use App\Services\Intel\ReviewIntelService;

/*
| Faz 8 — Pazar istihbaratı (DataForSEO, marka bazında isteğe bağlı, aylık USD tavanı).
| Maliyetler tahmindir; gerçek maliyet her görevin DataForSEO yanıtından kaydedilir ve Maliyetler ekranında görünür.
*/
return [
    // Yeni marka ayarı için aylık tavan (USD). Harita grid + yorumlar + backlink aynı tavanı paylaşır.
    'default_monthly_usd' => 5,

    'grid' => [
        'sizes' => [3, 5, 7, 9],
        'default_size' => 7,
        'default_spacing_km' => 1.0,
        'default_every_days' => 7,
        'max_keywords' => 5,
        'zoom' => 15,
        'depth' => 20,
        'language_code' => 'tr',
        // Standart kuyruk (task_post) görev başı tahmini maliyet; bütçe kontrolü için.
        'cost_per_point_usd' => 0.0012,
        // SoLV: bu sıranın içindeki noktaların payı.
        'solv_top' => 3,
    ],

    // Faz 8b: harita pin (KML) deneyi. Konumlar OpenStreetMap Nominatim ile bulunur (herkese açık okuma, saniyede 1 istek).
    'kml' => [
        'max_pins' => 150,
        'geocoder_url' => 'https://nominatim.openstreetmap.org/search',
        'geocoder_delay_ms' => 1100,
        // Deney ölçümü: yayın tarihinden önceki ve sonraki bu kadar gün karşılaştırılır.
        'compare_days' => 42,
    ],

    'reviews' => [
        'depth' => 50,
        'every_days' => 14,
        'cost_per_task_usd' => 0.004,
        // Olumsuz yorum: bu puan ve altı.
        'negative_max_rating' => 2,
        'max_competitors' => 5,
    ],

    'backlinks' => [
        'every_days' => 30,
        'cost_per_request_usd' => 0.03,
        'referring_domains_limit' => 200,
        'intersection_limit' => 100,
        'min_competitors' => 2,
        'max_competitors' => 5,
        // Kalite filtresi: DataForSEO alan puanı (0–1000) ve spam puanı (0–100).
        'min_rank' => 50,
        'max_spam_score' => 30,
        // Canlı link kontrolü (yayında / bekliyor fırsatlar): gün.
        'check_every_days' => 7,
        // Türkiye rehber / atıf listesi. sectors boş = her marka; aksi halde marka sektörü eşleşirse.
        'citations' => [
            ['name' => 'Google İşletme Profili', 'domain' => 'google.com', 'sectors' => [], 'skip_check' => true],
            ['name' => 'Yandex Haritalar (Yandex Business)', 'domain' => 'yandex.com.tr', 'sectors' => [], 'skip_check' => true],
            ['name' => 'Apple Business Connect', 'domain' => 'apple.com', 'sectors' => [], 'skip_check' => true],
            ['name' => 'Bing Places', 'domain' => 'bing.com', 'sectors' => [], 'skip_check' => true],
            ['name' => 'Foursquare', 'domain' => 'foursquare.com', 'sectors' => []],
            ['name' => 'Find.com.tr', 'domain' => 'find.com.tr', 'sectors' => []],
            ['name' => 'Cylex Türkiye', 'domain' => 'cylex-tr.com', 'sectors' => []],
            ['name' => 'Doktortakvimi', 'domain' => 'doktortakvimi.com', 'sectors' => ['healthcare', 'dental', 'medical_aesthetics']],
            ['name' => 'Doktorsitesi', 'domain' => 'doktorsitesi.com', 'sectors' => ['healthcare', 'dental', 'medical_aesthetics']],
            ['name' => 'Oda / meslek birliği listesi', 'domain' => 'tobb.org.tr', 'sectors' => []],
        ],
    ],

    // Faz 8f: dış denetim (potansiyel müşteri). Harita kontrolü ajans geneli aylık tavan içinde.
    'prospect_audit' => [
        'monthly_usd' => 5,
    ],

    // Faz 8e: rakip site izleme (ücretsiz, herkese açık okuma, haftalık).
    'watch' => [
        'max_competitors' => 10,
    ],

    'tasks' => [
        'poll_after_seconds' => 60,
        'give_up_hours' => 24,
        'max_polls' => 60,
        'per_run' => 200,
        // Amaç → işleyici sınıfı (DataForSeoTaskHandler).
        'handlers' => [
            'map_grid' => MapGridService::class,
            'reviews' => ReviewIntelService::class,
            'prospect_maps' => ProspectAuditService::class,
        ],
    ],
];
