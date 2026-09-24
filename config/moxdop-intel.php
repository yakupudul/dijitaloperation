<?php

use App\Services\Intel\MapGridService;

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
    ],

    'tasks' => [
        'poll_after_seconds' => 60,
        'give_up_hours' => 24,
        'max_polls' => 60,
        'per_run' => 200,
        // Amaç → işleyici sınıfı (DataForSeoTaskHandler).
        'handlers' => [
            'map_grid' => MapGridService::class,
        ],
    ],
];
