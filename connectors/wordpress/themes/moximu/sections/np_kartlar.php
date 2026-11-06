<?php
/**
 * Hizmet kartları: her kartta küçük bir ürün görünümü (arama sonucu, harita, site, reklam, form), fiyat ve paket avantajı.
 * Fiyatlar ve avantajlar Moximu ayarları › Fiyatlar'dan hesaplanır; panelde yalnız başlık, açıklama ve bağlantı yazılır.
 */
defined('ABSPATH') || exit;
$mx_p = mx_fiyatlar();
$mx_k = mx_kampanya();
$mx_kind = static function (string $url): string {
    foreach (['web-sitesi' => 'web', 'seo' => 'seo', 'isletme-profili' => 'harita', 'google-ads' => 'ads', 'instagram' => 'meta'] as $needle => $kind) {
        if (str_contains($url, $needle)) {
            return $kind;
        }
    }

    return '';
};
$mx_vis = [
    'seo' => '<div class="sv-v sv-serp"><span class="sv-q"><i></i>implant tedavisi karşıyaka</span><span class="sv-r on"><b>1</b><em>Örnek Diş Kliniği · İmplant tedavisi</em></span><span class="sv-r"><b>2</b><em></em></span><span class="sv-r"><b>3</b><em></em></span></div>',
    'harita' => '<div class="sv-v sv-map"><span class="sv-pin"></span><span class="sv-pc"><b>Örnek Diş Kliniği</b><em><i>★★★★★</i> 4,9 · Açık</em><span><u>Ara</u><u>Yol tarifi</u></span></span></div>',
    'web' => '<div class="sv-v sv-web"><span class="sv-bar"><i></i><i></i><i></i><em>klinik.com/implant</em></span><span class="sv-pg"><b></b><i></i><i></i><u>Randevu</u></span><span class="sv-sp">1,2 sn</span></div>',
    'ads' => '<div class="sv-v sv-ad"><span class="sv-sp2">Sponsorlu</span><b>İmplant tedavisi · Örnek Diş Kliniği</b><em>Hekim muayenesi, tedavi planı ve süreç hakkında bilgi alın.</em><span class="sv-kpi"><u>Arama <b>+%18</b></u><u>Form <b>+%11</b></u></span></div>',
    'meta' => '<div class="sv-v sv-lead"><span class="sv-n"><i></i><b>Yeni form</b><em>Almanya · İmplant</em><small>şimdi</small></span><span class="sv-n"><i></i><b>WhatsApp mesajı</b><em>Hollanda · Zirkonyum</em><small>4 dk</small></span></div>',
];
?>
<?= mx_np_open($d, 'hizmetler', 'np-sv') ?>
<?= mx_np_head($d, true) ?>
<div class="sv-g">
<?php foreach (mx_rows($d['liste'] ?? null) as $mx_i => $r) :
    $mx_u = (string) ($r['baglanti'] ?? '');
    $mx_t = $mx_kind($mx_u);
    $mx_dark = $mx_t === 'web' && $mx_k;
    $mx_once = $mx_t === 'web';
    $mx_price = $mx_t !== '' && isset($mx_p[$mx_t]) ? mx_tl($mx_p[$mx_t]) : mx_h($r['fiyat'] ?? '');
    // Struck price + discount badge: SEO = price after the campaign; Profile/Ads/Meta = the real "SEO ile" bundle price.
    $mx_old = 0; $mx_now = 0; $mx_note = '';
    if ($mx_t === 'seo') {
        $mx_now = $mx_p['seo'];
        $mx_old = $mx_k ? (int) $mx_p['seo_sonra'] : 0;
        $mx_note = $mx_k ? esc_html(mx_kampanya_tarih()).'\'a kadar başlayan kliniklere' : 'Kurulum ücreti yok · aylık';
    } elseif ($mx_t === 'harita') {
        $mx_now = $mx_p['paket'];
        $mx_old = $mx_k ? (int) $mx_p['paket_sonra'] : $mx_p['paket_liste'];
        $mx_note = 'SEO + İşletme Profili birlikte · tek başına '.mx_tl($mx_p['harita']);
    } elseif ($mx_t === 'ads' || $mx_t === 'meta') {
        $mx_now = $mx_p[$mx_t.'_paket'];
        $mx_old = $mx_p[$mx_t];
        $mx_note = 'SEO ile birlikte · tek başına '.mx_tl($mx_p[$mx_t]);
    }
    $mx_deal = '';
    if ($mx_t === 'web' && $mx_k) {
        $mx_deal = '<span class="sv-deal sv-gift">'.esc_html(mx_kampanya_tarih()).'\'a kadar başlayan kliniklere</span>';
    } elseif ($mx_note !== '') {
        $mx_deal = '<span class="sv-note">'.$mx_note.'</span>';
    } elseif (! empty($r['etiket'])) {
        $mx_deal = '<span class="sv-deal sv-plain">'.mx_h($r['etiket']).'</span>';
    }
    ?>
<a class="sv-c svk-<?= esc_attr($mx_t ?: 'x') ?><?= $mx_dark ? ' sv-dark' : '' ?>" href="<?= mx_url($mx_u) ?>">
<div class="sv-top"><span class="sv-ic"><?= mx_svc_icon($mx_u, wp_strip_all_tags((string) ($r['baslik'] ?? ''))) ?></span><h3><?= mx_h($r['baslik'] ?? '') ?></h3><?php if ($mx_dark) : ?><span class="sv-flag"><?= mx_h($mx_k['etiket'] ?? '') ?></span><?php endif; ?></div>
<?= $mx_vis[$mx_t] ?? '' ?>
<p><?= mx_h($r['metin'] ?? '') ?></p>
<div class="sv-pr"><?php if ($mx_dark) : ?><div><small>Tek sefer · 6 aylık planda</small><b class="sv-hd">Hediye</b><s><?= $mx_price ?> + KDV</s></div><?php elseif ($mx_now) : $mx_pct = $mx_old > $mx_now ? (int) round(($mx_old - $mx_now) * 100 / $mx_old) : 0; ?><div><small>Aylık</small><?php if ($mx_pct) : ?><s><?= mx_tl($mx_old) ?></s><em class="sv-off">%<?= $mx_pct ?> indirim</em><i class="px-br"></i><?php endif; ?><b><?= mx_tl($mx_now) ?></b><span>+ KDV</span></div><?php else : ?><div><small><?= $mx_once ? 'Tek sefer' : 'Aylık' ?></small><b><?= $mx_price ?></b><span>+ KDV</span></div><?php endif; ?><?= $mx_deal ?></div>
<span class="sv-go">İnceleyin <i>→</i></span>
</a>
<?php endforeach; ?>
</div>
<div class="sv-foot"><a class="btn" href="<?= mx_url('/fiyatlar/') ?>">Planları ve paket fiyatlarını görün</a><?php if (! empty($d['alt_not'])) : ?><p><?= mx_h($d['alt_not']) ?></p><?php endif; ?></div>
</div></section>
