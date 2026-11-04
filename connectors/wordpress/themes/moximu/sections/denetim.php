<?php
/**
 * Klinik dijital denetimi — ücretsiz ön değerlendirmenin yanında duran ücretli seçenek (panelden düzenlenir).
 * Formu yoktur; talep WhatsApp'tan alınır. Fiyat Moximu ayarları › Fiyatlar › denetim alanından gelir.
 */
defined('ABSPATH') || exit;
$mx_p = mx_fiyatlar();
?>
<section class="mx dn" id="denetim"><div class="wrap"><div class="dn-box">
<div class="dn-t">
<span class="dn-chip"><?= mx_h($d['yazi'] ?? 'Detaylı istiyorsanız') ?></span>
<h2><?= mx_h($d['baslik'] ?? '') ?></h2>
<p><?= mx_h($d['metin'] ?? '') ?></p>
<ul class="dn-l"><?php foreach (mx_rows($d['liste'] ?? null) as $r): ?><li><?= mx_h($r['madde'] ?? '') ?></li><?php endforeach; ?></ul>
</div>
<div class="dn-p">
<div class="dn-cmp"><div><small>Ön değerlendirme</small><b>Ücretsiz</b><span>2-3 öncelikli bulgu, WhatsApp'tan</span></div><div class="on"><small>Klinik dijital denetimi</small><b><?= mx_tl((int) $mx_p['denetim']) ?> <em>+ KDV</em></b><span>Yazılı rapor ve 30 dk online sunum</span></div></div>
<p class="dn-note"><?= mx_h($d['kucuk_not'] ?? '') ?></p>
<a class="btn dn-btn" href="<?= esc_url(mx_wa_url((string) ($d['buton_yazisi_2'] ?? 'Merhaba, kliniğim için klinik dijital denetimi istiyorum.'))) ?>" target="_blank" rel="noopener"><?= mx_wa_icon() ?><?= mx_h($d['buton_yazisi'] ?? "WhatsApp'tan isteyin") ?></a>
</div>
</div></div></section>
