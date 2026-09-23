<?php

return [
    'title' => 'Web Sitesi',
    'nav_label' => 'Web sitesi çalışma alanı',

    'tabs' => [
        'overview' => 'Genel Bakış',
        'seo' => 'SEO Görevleri',
        'search_console' => 'Search Console',
        'ga4_analysis' => 'Google Analytics',
        'content' => 'Sayfalar & İçerik',
        'health' => 'Site Sağlığı',
        'standards' => 'Standartlar',
        'infrastructure' => 'Altyapı & WordPress',
        'setup' => 'Veri Kaynakları',
    ],

    'actions' => [
        'refresh_seo' => 'SEO görünürlüğünü yenile',
    ],

    'kpi_fallback' => [
        'organic_search' => 'Organik arama',
        'analytics' => 'Google Analytics',
        'findings' => 'Bulgular',
        'recommendations' => 'Öneriler',
    ],

    'overview' => [
        'no_data_title' => 'Dikkat · bu web sitesi için henüz Google Analytics / Search Console verisi yok',
        'no_data_body' => 'Veri kaynağını bağlayın ve veri çekimini başlatın; göstergeler veri geldikçe oluşur.',
        'awaiting_data' => 'Veri bekleniyor',
        'inventory_body' => 'Envanter yalnızca toplanmış web sitesi ve Site Connector verisinden oluşur.',
        'open_findings' => 'Açık bulgular',
        'open_findings_hint' => 'Bu web sitesi için kayıtlı bulgular',
        'no_open_findings' => 'Henüz açık bulgu yok.',
        'recommendations' => 'Öneriler',
        'recommendations_hint' => 'Bulgulardan türetilen aksiyon önerileri',
        'no_recommendations' => 'Henüz öneri yok.',
        'all' => 'Tümünü gör →',
    ],

    'severity' => [
        'critical' => 'Kritik',
        'high' => 'Yüksek',
        'medium' => 'Orta',
        'low' => 'Düşük',
        'info' => 'Bilgi',
        'warning' => 'Uyarı',
    ],

    'priority' => [
        'critical' => 'Kritik öncelik',
        'high' => 'Yüksek öncelik',
        'medium' => 'Orta öncelik',
        'low' => 'Düşük öncelik',
    ],

    'finding_status' => [
        'open' => 'Açık',
        'acknowledged' => 'İnceleniyor',
        'resolved' => 'Çözüldü',
    ],

    'recommendation_status' => [
        'open' => 'Açık',
        'accepted' => 'Kabul edildi',
        'dismissed' => 'Reddedildi',
        'converted' => 'Göreve dönüştürüldü',
    ],

    'fact_notes' => [
        'pages_content' => 'Bu sekme toplanmış ve birleştirilmiş verileri gösterir. Sorun ve fırsat yorumları Genel Bakış sekmesindeki bulgu ve öneri listelerinde, kanıt bağlantılarıyla sunulur.',
        'technical_health' => 'Site Sağlığı toplanmış dış site verilerini ve kurala dayalı gözlemleri gösterir. Bulgular, önceliklendirme ve öneriler Genel Bakış sekmesinde ve Bulgular sayfasında yer alır.',
        'infrastructure' => 'Bu sekme web sitesi yapılandırmasını, WordPress’in içeriden bildirdiği durumu ve dış altyapı gözlemini ayrı kaynaklar olarak gösterir. WordPress site sağlığı ve güncelleme kayıtları ham gözlemdir; bulgu, öncelik ve öneriler Genel Bakış sekmesinde yer alır.',
    ],

    'search_console' => [
        'workflow_title' => 'Bulgular → Öneriler → Görevler',
        'external_resource' => 'Bağlı kaynak',
        'search_types' => 'Arama türleri',
        'site_totals' => 'Site toplamları',
        'site_totals_value' => 'Günlük mülk toplamları',
    ],
];
