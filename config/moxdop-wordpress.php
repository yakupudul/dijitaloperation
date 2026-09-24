<?php

return [
    'connector_version' => '1.4.0',
    // Sağlık raporu, tek tık giriş ve onaylı güncelleme: 1.3.0 bu yanıtları imzasız döndürüyordu (MoxDOP reddeder); 1.4.0 imzalar.
    'management_min_plugin_version' => '1.4.0',
    // ADR-070: onaylı SEO düzeltmeleri ve içerik güncelleme.
    'fixes_min_plugin_version' => '1.4.0',
    'pairing_ttl_minutes' => 15,
    'signature_clock_skew_seconds' => 300,
    'request_timeout_seconds' => 30,
    'update_timeout_seconds' => 300,
    'max_response_bytes' => 5 * 1024 * 1024,
    'per_page' => 50,
];
