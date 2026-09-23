<?php

return [
    'identity' => [
        'status' => [
            'connected' => 'Bağlı',
            'action_required' => 'İşlem gerekli',
            'not_connected' => 'Bağlı değil',
            'error' => 'Okuma hatası',
        ],
        'title_not_connected' => ':name — bağlı değil',
        'title_read_error' => ':name — okuma hatası',
    ],

    'boundary' => [
        'real' => 'Meta Ads sayfası daha önce toplanmış verileri gösterir. Veri olmayan kartlar boş kalır; sayfa açılırken Meta API çağrısı yapılmaz.',
        'unbound' => 'Bu Meta Ads varlığına kullanılabilir bir reklam hesabı bağlı değil. Sayfa açılırken Meta API çağrısı yapılmaz.',
    ],

    'integration' => [
        'binding' => 'Meta Ads bağlantısı',
        'data_label' => 'Henüz veri toplanmadı — veri durumuna bakın',
    ],

    'measurement' => [
        'lead_hint' => 'Meta’nın standart “lead” sonucu',
        'whatsapp_rule' => 'WhatsApp maliyeti yalnızca reklam setinin hedefi WhatsApp olduğunda ve Meta “başlatılan mesajlaşma konuşması” sonucunu raporladığında hesaplanır. Diğer mesajlaşma sonuçları WhatsApp sonucu gibi sayılmaz.',
        'raw_action' => 'Meta’daki teknik adı göster',
        'actions_intro' => 'Farklı sonuçlar birbirine eklenmez. Benzer teknik adlara sahip sonuçlar da otomatik olarak aynı sonuç kabul edilmez.',
        'actions_empty' => 'Seçili dönemde ölçülmüş sonuç verisi yok.',
        'footer' => 'Öne çıkan sonuçlar Genel Bakış, Performans, Kampanyalar ve ilişkilendirilebildiğinde Kreatifler alanında da kendi bağlamında gösterilir. Bu ayrıntılı tablo Meta’nın raporladığı her sonucu ayrı ayrı korur.',
    ],

    'health' => [
        'datasets' => [
            'meta_account_daily' => 'Hesap günlük performansı',
            'meta_campaign_daily' => 'Kampanya günlük performansı',
            'meta_adset_daily' => 'Reklam seti günlük performansı',
            'meta_ad_daily' => 'Reklam günlük performansı',
            'meta_typed_action_daily' => 'Sonuç (aksiyon) kayıtları',
            'meta_video_engagement_daily' => 'Video izlenme verileri',
            'meta_analysis_breakdown_daily' => 'Kitle ve yerleşim kırılımları',
            'meta_hourly_daily' => 'Saatlik performans',
            'meta_ad_snapshot' => 'Reklam listesi',
            'meta_adset_targeting_snapshot' => 'Hedefleme ayarları',
            'meta_conversion_source_snapshot' => 'Pixel ve özel dönüşümler',
            'meta_change_event' => 'Hesap değişiklik geçmişi',
            'meta_creative_snapshot' => 'Kreatif listesi',
        ],
        'freshness' => [
            'FRESH' => 'Güncel',
            'FRESH_WITH_LIMITATION' => 'Güncel (sınırlı)',
            'DUE' => 'Yenileme zamanı geldi',
            'STALE' => 'Eski',
            'PARTIAL' => 'Kısmi',
            'ACTION_REQUIRED' => 'İşlem gerekli',
            'PROVIDER_LIMITED' => 'Meta tarafından sınırlı',
            'INTEGRITY_BLOCKED' => 'Doğrulama bekliyor',
            'UNKNOWN' => 'Bilinmiyor',
        ],
        'coverage' => [
            'FULLY_COVERED' => 'Dönemin tamamı var',
            'PARTIALLY_COVERED' => 'Dönemin bir kısmı var',
            'NOT_COVERED' => 'Bu dönem için veri yok',
        ],
        'integrity' => [
            'READY_FOR_REAL_UI' => 'Doğrulandı',
            'READY_WITH_PROVIDER_LIMITATION' => 'Doğrulandı (Meta sınırlaması var)',
            'BLOCKED_PARTIAL' => 'Eksik veri nedeniyle bekletiliyor',
            'BLOCKED_INTEGRITY' => 'Tutarlılık kontrolünden geçmedi',
            'BLOCKED_STALE' => 'Veri eski olduğu için bekletiliyor',
            'BLOCKED_CONTRACT' => 'Veri sözleşmesi eksik',
            'UNVERIFIED' => 'Henüz doğrulanmadı',
            'UNAVAILABLE' => 'Kullanılamıyor',
        ],
        'freshness_label' => 'Güncellik',
        'coverage_label' => 'Kapsam',
        'integrity_label' => 'Doğrulama',
    ],

    'instagram' => [
        'not_connected_title' => 'Instagram analitiği henüz bağlı değil',
        'not_connected_body' => 'Bu sistem Instagram gönderi, takipçi veya etkileşim verisi toplamıyor. Bu yüzden bu sayfada Instagram performans rakamı gösterilmez; eksik veri sıfır gibi sunulmaz.',
        'profile_title' => 'Son toplanan profil bilgileri',
        'profile_observed' => 'Toplanma zamanı: :time',
        'profile_missing' => 'Bu hesap için henüz profil bilgisi toplanmadı.',
        'username' => 'Kullanıcı adı',
        'name' => 'Görünen ad',
        'account_type' => 'Hesap türü',
        'biography' => 'Biyografi',
        'website' => 'Web sitesi',
        'brand' => 'Marka',
        'open_brand' => 'Markayı aç',
        'all_assets' => 'Tüm dijital varlıklar',
        'view_activity' => 'Aktiviteyi görüntüle',
    ],
];
