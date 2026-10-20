<?php

namespace App\Support\Integrations\Presentation;

/**
 * Plain-Turkish wording for the English labels the Google / Meta read models still return (panel concept
 * 2026-10-20: no English statuses or internal words in visible text). Only the screen text changes; the read
 * models keep their values, and an English-locale operator sees them unchanged.
 */
final class OperatorLabel
{
    /**
     * @var array<string, string>
     */
    private const array EXACT = [
        'Not configured' => 'Yapılandırılmadı',
        'Configured' => 'Yapılandırıldı',
        'Missing' => 'Eksik',
        'Not granted' => 'Verilmedi',
        'Not authorized' => 'Yetki yok',
        'Not tested' => 'Test edilmedi',
        'Needs attention' => 'Bakılmalı',
        'Connection tested ✓' => 'Bağlantı test edildi',
        'Stored securely ✓' => 'Güvenle kayıtlı',
        'Live' => 'Canlı',
        'System' => 'Sistem',
        'Collection not run' => 'Veri çekimi henüz çalışmadı',
        'No data available' => 'Veri yok',
        'Data available' => 'Veri geliyor',
        'Stale or partial materialization' => 'Veri eski ya da eksik',
        'Stale / partial data' => 'Veri eski ya da eksik',
        'Materialization present but not available' => 'Veri var ama kullanılamıyor',
        'Previous data available · refresh required' => 'Eski veri var, yenilenmeli',
        'Configure Google' => 'Google uygulama bilgilerini gir',
        'Connect Google' => 'Google ile bağlan',
        'Discover resources' => 'Hesapları keşfet',
        'Select & bind resources' => 'Hesapları markaya bağla',
        'Collect data via Collection Engine' => 'Veri çek',
        'Manage Google' => 'Google bağlantısını yönet',
        'Configure Meta App credentials' => 'Meta uygulama bilgilerini gir',
        'Connect Meta' => 'Meta ile bağlan',
        'Reauthorize Meta' => 'Meta yetkisini yenile',
        'Discover Businesses' => 'İşletmeleri keşfet',
        'Select Business discovery context' => 'Keşif için işletme seç',
        'Discover Ad Accounts' => 'Reklam hesaplarını keşfet',
        'Confirm Ad Account connection' => 'Reklam hesabını markaya bağla',
        'Collect data' => 'Veri çek',
        'Manage Meta' => 'Meta bağlantısını yönet',
        'Discovered' => 'Keşfedildi',
        'Partial discovery' => 'Kısmen keşfedildi',
        'Scope required' => 'İzin gerekli',
        'API access required' => 'API erişimi gerekli',
        'Re-authorization required' => 'Yeniden yetki gerekli',
        'Discovery failed' => 'Keşif başarısız',
        'Skipped' => 'Atlandı',
        'Discovery not run' => 'Keşif yapılmadı',
        'Disabled — MoxDOP is read / bind only' => 'Kapalı, MoxDOP yalnız okur ve bağlar',
        'Google Integration not configured' => 'Google bağlantısı kurulmadı',
        'Meta Integration not configured' => 'Meta bağlantısı kurulmadı',
        'Authorization refresh required' => 'Yetki yenilenmeli',
        'Integration reported an error (details withheld from UI)' => 'Bağlantı hata bildirdi',
        'success' => 'Tamam',
        'completed' => 'Tamam',
        'failed' => 'Hata',
        'error' => 'Hata',
        'needs_attention' => 'Bakılmalı',
        'warning' => 'Bakılmalı',
        'info' => 'Bilgi',
    ];

    public static function turkish(?string $label): string
    {
        $label = (string) $label;
        if ($label === '' || app()->getLocale() !== 'tr') {
            return $label;
        }
        if (isset(self::EXACT[$label])) {
            return self::EXACT[$label];
        }
        if (preg_match('/^Resource discovery last ran \((\d+) resources known\)$/', $label, $m) === 1) {
            return 'Hesap keşfi çalıştı, '.$m[1].' kaynak biliniyor';
        }
        if (preg_match('/^Inventory · (\d+) Businesses · (\d+) Ad Accounts · (\d+) bound$/', $label, $m) === 1) {
            return $m[1].' işletme, '.$m[2].' reklam hesabı, '.$m[3].' bağlı';
        }
        if (str_starts_with($label, 'Authorization · ')) {
            return 'Yetki: '.self::turkish(substr($label, strlen('Authorization · ')));
        }
        if (str_starts_with($label, 'Last collection · ')) {
            return 'Son veri çekimi: '.substr($label, strlen('Last collection · '));
        }

        return $label;
    }
}
