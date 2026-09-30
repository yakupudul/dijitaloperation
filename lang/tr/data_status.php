<?php

/*
 * The one data-status language used on every asset page, the Portföy sağlığı grid and anything else that says
 * whether a data source of a digital asset is connected and current (App\Services\DataStatus\DataStatusReader).
 */
return [
    'title' => 'Veri durumu',

    'sources' => [
        'search_console' => 'Search Console',
        'ga4' => 'Google Analytics',
        'google_ads' => 'Google Ads',
        'meta_ads' => 'Meta Ads',
        'google_business_profile' => 'İşletme Profili',
    ],

    'states' => [
        'not_bound' => 'Bağlı değil',
        'first_load' => 'İlk veri yükleniyor',
        'fresh' => 'Güncel',
        'stale' => 'Gecikmiş',
        'access_problem' => 'Erişim sorunu',
        'paused' => 'Pasif',
    ],

    'stale_days' => 'Gecikmiş · :days gün',
    'last_data' => 'son veri :date',
    'collected' => 'toplandı :when',
    'no_rows_yet' => 'henüz veri satırı yok',
    'collecting' => 'Veri toplanıyor',
    'progress' => '%:pct',
    'paused_hint' => 'Hesapta son dönemde etkinlik yok',

    'reasons' => [
        'reconnect' => 'Google/Meta izni yenilenmeli',
        'revoked' => 'İzin geri alınmış',
        'integration_disabled' => 'Entegrasyon kapalı',
        'resource_unavailable' => 'Hesap artık bağlı kullanıcıya görünmüyor',
        'collection_failed' => 'Son toplama başarısız',
        'request_requires_fix' => 'Toplama isteği düzeltilmeli',
        'cancelled' => 'Son toplama durduruldu',
        'unbound' => 'Hesap markaya bağlı değil',
        'collection_disabled' => 'Otomatik güncelleme kapalı',
        'no_rows' => 'Toplama tamamlandı ama veri gelmedi',
    ],

    'actions' => [
        'refresh' => 'Verileri yenile',
        'reconnect' => 'Yeniden bağla',
        'bind' => 'Kaynağı bağla',
    ],

    'feedback' => [
        'started' => ':source güncellemesi başlatıldı.',
        'active_equivalent' => ':source güncellemesi zaten çalışıyor.',
        'data_current' => ':source verileri zaten güncel.',
        'action_required' => ':source bağlantısı kontrol edilmeli.',
        'failed' => ':source güncellemesi başlatılamadı.',
    ],

    'banner' => [
        'not_bound_title' => 'Bu web sitesine :sources bağlı değil',
        'not_bound_body' => 'Kaynağı bağlayın; veriler merkezi toplama ile otomatik gelir.',
        'first_load_title' => ':sources için ilk veri yükleniyor',
        'first_load_body' => 'Bağlantı hazır; ilk toplama bitince rakamlar burada görünür.',
    ],

];
