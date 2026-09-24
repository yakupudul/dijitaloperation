<?php

/*
 * Faz 14 — ajans lead kutusu kaynakları. Web formu /api/leads/{token} (Faz 8g). Meta form reklamları: Meta
 * uygulamasında "leadgen" webhook'unu /api/meta/leadgen adresine abone edin; lead bilgisi sayfa erişim anahtarıyla
 * Graph API'den okunur. WhatsApp: ajans numarasına yazan ve müşteri / adayla eşleşmeyen yeni kişiler.
 */
return [
    'meta' => [
        'verify_token' => env('MOXDOP_META_LEADGEN_VERIFY_TOKEN'),
        'app_secret' => env('MOXDOP_META_LEADGEN_APP_SECRET'),
        'page_access_token' => env('MOXDOP_META_LEADGEN_PAGE_TOKEN'),
        'graph_version' => env('MOXDOP_META_LEADGEN_GRAPH_VERSION', 'v23.0'),
    ],
    'whatsapp_unknown_contacts' => (bool) env('MOXDOP_LEADS_FROM_WHATSAPP', true),
];
