<?php

return [
    'connector_version' => '1.3.0',
    // Faz 9: sağlık raporu, tek tık panel girişi ve onaylı güncelleme için gereken en düşük eklenti sürümü.
    'management_min_plugin_version' => '1.3.0',
    'pairing_ttl_minutes' => 15,
    'signature_clock_skew_seconds' => 300,
    'request_timeout_seconds' => 30,
    'update_timeout_seconds' => 300,
    'max_response_bytes' => 5 * 1024 * 1024,
    'per_page' => 50,
];
