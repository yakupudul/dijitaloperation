<?php

/*
|--------------------------------------------------------------------------
| Harici yazma (ADR-064, Faz 7)
|--------------------------------------------------------------------------
| MoxDOP yalnız iki şey yazar, yalnız Admin tıklamasıyla ve her zaman geri
| alınabilir şekilde: Google Ads paylaşılan negatif listesi ve WordPress
| taslağı. EXTERNAL_WRITES_ENABLED=false tüm yazmayı anında kapatır.
*/

return [
    'enabled' => env('EXTERNAL_WRITES_ENABLED', true),
    'queue_connection' => env('EXTERNAL_WRITES_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
    'queue' => env('EXTERNAL_WRITES_QUEUE', 'default'),

    'google_ads' => [
        'enabled' => env('EXTERNAL_WRITES_GOOGLE_ADS', true),
        'shared_set_name' => 'MoxDOP negatifleri',
        'max_terms' => 200,
        'max_term_length' => 80,
    ],

    'wordpress' => [
        'enabled' => env('EXTERNAL_WRITES_WORDPRESS', true),
        'min_plugin_version' => '1.2.0',
    ],
];
