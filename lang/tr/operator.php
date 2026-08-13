<?php

return [
    'nav' => [
        'groups' => [
            'menu' => 'Menü',
            'portfolio' => 'Portföy',
            'operations' => 'Operasyon',
            'system' => 'Sistem',
        ],
        'dashboard' => 'Kontrol paneli',
        'customers' => 'Müşteriler',
        'brands' => 'Markalar',
        'digital_assets' => 'Dijital varlıklar',
        'files' => 'Dosyalar',
        'findings' => 'Bulgular',
        'recommendations' => 'Öneriler',
        'tasks' => 'Görevler',
        'activity' => 'Aktivite',
        'integrations' => 'Entegrasyonlar',
        'settings' => 'Ayarlar',
        'profile' => 'Profil',
        'site_connectors' => 'Site bağlayıcıları',
    ],

    'actions' => [
        'save' => 'Kaydet',
        'cancel' => 'İptal',
        'upload' => 'Yükle',
        'download' => 'İndir',
        'delete' => 'Sil',
        'search' => 'Ara',
        'open' => 'Aç',
        'manage' => 'Yönet',
        'remove' => 'Kaldır',
        'rename' => 'Yeniden adlandır',
        'confirm' => 'Onayla',
    ],

    'profile' => [
        'title' => 'Profil',
        'subtitle' => 'Operatör kimliğiniz, dil ve avatar ayarları.',
        'name' => 'Ad',
        'email' => 'E-posta',
        'password' => 'Yeni şifre',
        'password_confirmation' => 'Şifreyi onayla',
        'locale' => 'Dil',
        'timezone' => 'Saat dilimi',
        'avatar' => 'Avatar',
        'avatar_hint' => 'JPEG, PNG veya WebP · en fazla 2 MB',
        'saved' => 'Profil kaydedildi.',
        'remove_avatar' => 'Avatarı kaldır',
    ],

    'files' => [
        'title' => 'Dosyalar',
        'subtitle' => 'Operatör dosya kütüphanesi — kimlik doğrulamalı indirme ile özel depolama.',
        'empty' => 'Henüz dosya yok. Başlamak için bir belge veya görsel yükleyin.',
        'upload_cta' => 'Dosya yükle',
        'scope' => 'Kapsam',
        'scope_all' => 'Tüm kapsamlar',
        'scopes' => [
            'personal' => 'Kişisel',
            'agency' => 'Ajans',
            'customer' => 'Müşteri',
            'brand' => 'Marka',
            'digital_asset' => 'Dijital varlık',
            'task' => 'Görev',
        ],
        'original_name' => 'Ad',
        'size' => 'Boyut',
        'mime' => 'Tür',
        'uploaded_by' => 'Yükleyen',
        'uploaded_at' => 'Yüklenme',
        'confirm_delete' => 'Bu dosya kalıcı olarak silinsin mi?',
        'deleted' => 'Dosya silindi.',
        'uploaded' => 'Dosya yüklendi.',
        'renamed' => 'Dosya yeniden adlandırıldı.',
        'search_placeholder' => 'Dosyalarda ara…',
        'rejected_type' => 'Bu dosya türüne izin verilmiyor.',
    ],

    'site_connectors' => [
        'title' => 'Site bağlayıcıları',
        'subtitle' => 'Yönetilen bir Website’i MoxDOP ile eşleyen kurulabilir site paketleri (demo katalog).',
        'catalog' => 'Bağlayıcı kataloğu',
        'overview' => 'Genel bakış',
        'releases' => 'Sürümler',
        'install' => 'Kurulum',
        'connected' => 'Bağlı siteler',
        'activity' => 'Aktivite',
        'download_demo' => 'Demo paketini indir',
        'demo_badge' => 'Demo paketi — üretim kurulumu için değil',
        // Provider names stay as proper nouns (WordPress, etc.)
        'wordpress' => 'WordPress',
    ],

    'demo_mode' => [
        'label' => 'Demo Modu',
        'boundary' => 'Belirleyici fixture’lar — canlı sağlayıcı yazması yok.',
    ],

    'product' => [
        'tagline' => 'Ajans Operasyonları OS',
    ],

    'search' => [
        'placeholder' => 'Portföyde ara…',
        'empty' => 'Eşleşen müşteri, marka, varlık, bulgu veya görev yok.',
    ],

    'notifications' => [
        'title' => 'Bildirimler',
        'unread' => 'okunmamış',
        'empty' => 'Henüz bildirim yok. Kritik bulgular, görevler ve entegrasyon sorunları burada görünür.',
        'mark_all' => 'Tümünü okundu işaretle',
        'mark_read' => 'Okundu işaretle',
        'preferences' => 'Bildirim tercihleri',
        'item' => 'Bildirim',
    ],

    'languages' => [
        'en' => 'English',
        'tr' => 'Türkçe',
    ],
];
