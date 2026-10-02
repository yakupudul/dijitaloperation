<?php

/*
| DataForSEO aylık USD tavanı ve arama hacmi ayarları.
| Maliyetler tahmindir; gerçek maliyet her görevin DataForSEO yanıtından kaydedilir ve Maliyetler ekranında görünür.
*/
return [
    // W5: tüm DataForSEO özellikleri için hesap geneli aylık tavan (USD). 0 = kapalı.
    'global_monthly_usd' => (float) env('DATAFORSEO_GLOBAL_MONTHLY_USD', 100),

    // Arama hacmi (ayda bir): DataForSEO konum / dil (Türkiye; dili bilinmeyen sorgu Türkçe).
    'query_volumes' => [
        'location_code' => (int) env('DATAFORSEO_VOLUME_LOCATION_CODE', 2792),
        'default_language' => 'tr',
    ],
];
