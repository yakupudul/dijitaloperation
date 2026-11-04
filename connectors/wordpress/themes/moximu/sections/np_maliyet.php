<?php
/** Maliyet hesaplayıcı: fiyatlar Moximu ayarları › Fiyatlar'dan gelir (panelden yalnız başlıklar düzenlenir). */
defined('ABSPATH') || exit;
$p = mx_fiyatlar();
$mx_js = ['seo' => $p['seo'], 'harita' => $p['harita'], 'paket' => $p['paket'], 'ads' => $p['ads'], 'ads_p' => $p['ads_paket'], 'meta' => $p['meta'], 'meta_p' => $p['meta_paket'], 'web' => $p['web']];
$mx_o = [
    ['seo', 'Klinik SEO', 'Tedavi ve ilçe aramalarında üst sıralar', mx_tl($p['seo']).'/ay', true],
    ['harita', 'Google Harita Kaydı ve İşletme Profili', 'SEO ile birlikte toplam '.mx_tl($p['paket']), mx_tl($p['harita']).'/ay', true],
    ['ads', 'Google Ads Danışmanlığı', 'SEO ile '.mx_tl($p['ads_paket']).' · reklam bütçesi ayrı', mx_tl($p['ads']).'/ay', false],
    ['meta', 'Instagram ve Facebook Reklamları', 'SEO ile '.mx_tl($p['meta_paket']).' · reklam bütçesi ayrı', mx_tl($p['meta']).'/ay', false],
    ['web', 'Klinik Web Sitesi', 'Tek sefer · 6 aylık planda hediye', mx_tl($p['web']), false],
];
?>
<?= mx_np_open($d, 'maliyet') ?>
<?= mx_np_head($d, true) ?>
<div class="np-calc" data-calc data-p="<?= esc_attr(wp_json_encode($mx_js)) ?>" data-wa="<?= esc_attr(mx_wa_url('x')) ?>">
<div>
<div class="np-pre"><button type="button" data-pre="seo,harita">Önerilen: SEO + harita kaydı</button><button type="button" data-pre="seo,harita,web" data-six="1">Yeni açılan klinik</button><button type="button" data-pre="seo,harita,ads,meta">Dört kanal</button></div>
<div class="np-opts">
<?php foreach ($mx_o as [$mx_k, $mx_t, $mx_s, $mx_f, $mx_on]) : ?><label class="np-opt"><input type="checkbox" value="<?= $mx_k ?>"<?= $mx_on ? ' checked' : '' ?>><div><b><?= esc_html($mx_t) ?></b><small><?= esc_html($mx_s) ?></small></div><strong><?= esc_html($mx_f) ?></strong></label><?php endforeach; ?>
</div>
</div>
<aside class="np-sum">
<div class="np-sw"><button type="button" class="on" data-six="0">Aylık</button><button type="button" data-six="1">6 aylık plan</button></div>
<ul class="np-sum-l" data-lines><li><span>Klinik SEO + harita kaydı</span><span><?= esc_html(mx_tl($p['paket'])) ?>/ay</span></li></ul>
<div class="np-tot"><small>Aylık toplam</small><b data-tot><?= esc_html(mx_tl($p['paket'])) ?> <em>+ KDV</em></b><p data-note></p></div>
<a class="btn np-y" data-send href="<?= esc_url(mx_wa_url()) ?>" target="_blank" rel="noopener">Bu planı WhatsApp'tan gönder</a>
</aside>
</div>
<?php if (! empty($d['alt_not'])) : ?><p class="np-not"><?= mx_h($d['alt_not']) ?></p><?php endif; ?>
</div></section>
