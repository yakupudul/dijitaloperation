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
    'queue' => env('EXTERNAL_WRITES_QUEUE', env('WRITES_QUEUE', env('QUEUE_CONNECTION') === 'redis' ? 'writes' : 'default')),

    // yakup 2026-10-21: no Google Ads (or Meta) writes for now — findings and "how to do it" only, the operator applies
    // them in Google Ads and says "Yaptım". Deliberately not read from .env so a stale server value cannot turn it on.
    'google_ads' => [
        'enabled' => false,
        'shared_set_name' => 'MoxDOP negatifleri',
        'max_terms' => 200,
        'max_term_length' => 80,
    ],

    'wordpress' => [
        'enabled' => env('EXTERNAL_WRITES_WORDPRESS', true),
        'min_plugin_version' => '1.2.0',
    ],

    /* ADR-073: Business Profile review replies and local posts (Admin-approved, undoable). */
    'gbp' => [
        'enabled' => env('EXTERNAL_WRITES_GBP', true),
        'max_reply_length' => 4000,
        'max_post_length' => 1500,
    ],
];
