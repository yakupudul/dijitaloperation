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

    'health_score' => [
        'title' => 'Site sağlık skoru',
        'subtitle' => 'Toplanmış tarama verisinden hesaplanan tek skor; yeni tarama veya dış çağrı yapılmaz.',
        'score_label' => 'Skor',
        'grades' => [
            'good' => 'İyi',
            'fair' => 'Geliştirilmeli',
            'poor' => 'Zayıf',
        ],
        'pages_checked' => 'Kontrol edilen sayfa',
        'collected_at' => 'Son tarama / veri toplama',
        'crawl_count' => 'Kayıtlı tarama',
        'source_projection' => 'Ham tarama kaydı bulunamadı; skor birleştirilmiş sayfa profillerinden tek tarama olarak hesaplandı.',
        'trend_title' => 'Skor eğilimi',
        'trend_hint' => 'Son :count taramadaki skorlar',
        'trend_hidden' => 'Eğilim için en az iki tarama gerekir; şu an yalnızca bir tarama kaydı var.',
        'change' => 'Önceki taramaya göre :value puan',
        'no_change' => 'Önceki taramaya göre değişiklik yok',
        'formula_title' => 'Skor nasıl hesaplanır?',
        'formula' => 'Skor = 100 − Σ (ağırlık × sorunlu sayfa payı). Ağırlıklar: Kritik :critical, Yüksek :high, Orta :medium, Düşük :low. Pay, ilgili önem düzeyinde en az bir kural sorunu olan sayfaların kontrol edilen sayfalara oranıdır. Kopya başlık/açıklama, yetim sayfa ve kırık iç bağlantı grupları bilgi amaçlıdır ve skora dahil edilmez.',
        'summary' => [
            'broken_pages' => 'Bozuk sayfalar (4xx/5xx)',
            'broken_links' => 'Kırık iç bağlantılar',
            'redirects' => 'Yönlendirme zincirleri',
            'orphans' => 'Yetim sayfalar',
            'duplicates' => 'Kopya başlıklı sayfalar',
        ],
        'unavailable' => [
            'links' => 'İç bağlantı verisi (link grafiği) toplanmadığı için hesaplanamadı.',
            'duplicates' => 'Başlık / meta açıklama verisi toplanmadığı için hesaplanamadı.',
        ],
        'groups_title' => 'Sorunlar',
        'groups_hint' => 'Kural veya sorun türüne göre gruplanmış güncel durum. Satıra tıklayarak etkilenen URL’leri açın.',
        'columns' => [
            'issue' => 'Sorun',
            'severity' => 'Önem',
            'affected' => 'Etkilenen URL',
            'share' => 'Sayfa payı',
            'new' => 'Son taramada yeni',
        ],
        'not_scored' => 'Skora dahil değil',
        'new_unknown' => '—',
        'url_limit' => 'İlk :limit URL gösteriliyor (toplam :total). Tamamı için CSV indirin.',
        'no_issues' => 'Güncel taramada sorun bulunmadı.',
        'export_csv' => 'CSV indir',
        'redirect_steps' => ':count yönlendirme adımı',
        'kinds' => [
            'broken' => 'Bozuk',
            'redirect' => 'Yönlendirme',
            'rule' => 'Kural',
            'duplicate' => 'Kopya',
            'orphan' => 'Yetim',
        ],
        'codes' => [
            'DUPLICATE_TITLE' => 'Kopya title (aynı başlığı paylaşan sayfalar)',
            'DUPLICATE_META_DESCRIPTION' => 'Kopya meta açıklaması',
            'ORPHAN_PAGE' => 'Yetim sayfa (iç bağlantı almıyor)',
            'BROKEN_INTERNAL_LINK' => '4xx/5xx sayfaya giden iç bağlantı',
        ],
        'csv' => [
            'filename' => 'site-sagligi',
            'code' => 'Kod',
            'issue' => 'Sorun',
            'severity' => 'Önem',
            'scored' => 'Skora dahil',
            'url_count' => 'Etkilenen URL sayısı',
            'share' => 'Sayfa payı (%)',
            'new_count' => 'Yeni (son tarama)',
            'url' => 'URL',
            'detail' => 'Detay',
            'yes' => 'Evet',
            'no' => 'Hayır',
        ],
    ],
];
