<?php

return [
    'connector_version' => '1.9.0',
    // 1.6.0: sayfa önbelleği dışa aktarımı (/page-cache) — önbellek eklentisinin diske yazdığı HTML okunur, sayfa işlenmez.
    'page_cache_min_plugin_version' => '1.6.0',
    // 1.7.0: rendered content of published pages (/content-export) replaces most page reads over HTTP.
    'content_export_min_plugin_version' => '1.7.0',
    // Sağlık raporu, tek tık giriş ve onaylı güncelleme: 1.3.0 bu yanıtları imzasız döndürüyordu (MoxDOP reddeder); 1.4.0 imzalar.
    'management_min_plugin_version' => '1.4.0',
    // ADR-070: onaylı SEO düzeltmeleri ve içerik güncelleme.
    'fixes_min_plugin_version' => '1.4.0',
    // 1.4.1: MoxDOP'tan tek tık eklenti güncellemesi (/self-update). 1.4.0 ve öncesi bir kez elle güncellenir.
    'self_update_min_plugin_version' => '1.4.1',
    // ADR-076 (1.5.0): zengin taslak (kategori, SEO alanları, Polylang dili ve çeviri bağlantısı).
    'rich_drafts_min_plugin_version' => '1.5.0',
    // 1.8.0: site kurulumu (/build) — ACF, sayfa, Elementor şablonu, medya, menü. Sitede "Site building" ve MoxDOP'ta
    // sitenin "Claude site kurulumu" anahtarı açık olmalı.
    'build_min_plugin_version' => '1.8.0',
    'build_timeout_seconds' => 55,
    // 1.9.0: "301 ile birleştir" — yönlendirme sitenin SEO eklentisine (Rank Math, Yoast Premium, Redirection; yoksa
    // eklentinin kendi listesine) yazılır, yönlendirilen sayfa silinmez, taslağa alınır. Geri alınabilir.
    'merge_redirect_min_plugin_version' => '1.9.0',
    // Kendini güncelleme için paket bağlantısı bu kadar dakika geçerli.
    'package_link_minutes' => 15,
    'pairing_ttl_minutes' => 15,
    'signature_clock_skew_seconds' => 300,
    'request_timeout_seconds' => 30,
    'update_timeout_seconds' => 300,
    'max_response_bytes' => 5 * 1024 * 1024,
    'per_page' => 50,
    // 1.5.1 "nazik mod": içerik / medya sayfaları küçük (site her yazının bloklarını işler); iki sayfa arası kısa ara;
    // site 429 / 503 / veritabanı hatası verirse Retry-After, sonra 5 → 15 → 60 dk beklenir.
    'content_per_page' => 25,
    'page_delay_seconds' => (int) env('MOXDOP_WORDPRESS_PAGE_DELAY_SECONDS', 2),
    'max_busy_retries' => 8,
    // Eklenti boş outbox'ta en geç 6 saatte bir sinyal gönderir; bu süreden uzun sessizlik "sessiz site" sayılır.
    'delivery_stale_hours' => 24,
];
