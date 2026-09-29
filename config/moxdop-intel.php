<?php

/*
| DataForSEO görev kuyruğu ve aylık USD tavanı (Faz 4 Rakipler bu kuyruğu kullanır).
| Maliyetler tahmindir; gerçek maliyet her görevin DataForSEO yanıtından kaydedilir ve Maliyetler ekranında görünür.
*/
return [
    // W5: tüm DataForSEO özellikleri için hesap geneli aylık tavan (USD). 0 = kapalı.
    'global_monthly_usd' => (float) env('DATAFORSEO_GLOBAL_MONTHLY_USD', 100),

    'tasks' => [
        'poll_after_seconds' => 60,
        'give_up_hours' => 24,
        'max_polls' => 60,
        'per_run' => 200,
        // Amaç → işleyici sınıfı (DataForSeoTaskHandler). Faz 4 (Rakipler) yeniden ekler.
        'handlers' => [],
    ],
];
