<?php
/**
 * Referanslar girişi — başlık, iki düğme, dört koyu rakam kartı ve logo duvarı (panelden düzenlenir).
 * liste: rakam kartları ("<b>50+</b><span>…</span>"); liste_2: logo duvarı (logo + ad, logosu olmayan ad olarak yazılır).
 */
defined('ABSPATH') || exit;
$mx_logos = mx_rows($d['liste_2'] ?? null);
?>
<section class="mx rf-hero"><div class="wrap">
<div class="rf-hero-t"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h($d['yazi'] ?? null) ?></span>
<h1><?= mx_h($d['baslik'] ?? null) ?></h1><p class="rf-lead"><?= mx_h($d['metin'] ?? null) ?></p>
<div class="rf-cta"><?php if (! empty($d['baglanti'])): ?><a class="btn navy" href="<?= mx_url($d['baglanti']) ?>"><?= mx_h($d['baglanti_yaz'] ?? null) ?></a><?php endif; ?><a class="btn rf-wa" href="<?= esc_url(mx_wa_url('Merhaba, referanslarınızı inceledim; kliniğim için bilgi almak istiyorum.')) ?>" target="_blank" rel="noopener"><?= mx_wa_icon() ?>WhatsApp'tan yazın</a></div></div>
<?php if ($d['liste'] ?? null): ?><div class="rf-stats"><?php foreach (mx_rows($d['liste']) as $r): ?><div class="rf-stat"><?= mx_h($r['yazi'] ?? null) ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($mx_logos): ?><div class="rf-wall" aria-label="Birlikte çalıştığımız klinikler"><?php foreach ($mx_logos as $i => $r): $src = mx_img_src($r['logo'] ?? 0); ?><div class="rf-cell" style="--i:<?= (int) $i ?>" title="<?= esc_attr((string) ($r['ad'] ?? '')) ?>"><?php if ($src !== ''): ?><img src="<?= $src ?>" alt="<?= esc_attr((string) ($r['ad'] ?? '')) ?>" loading="lazy" decoding="async"/><?php else: ?><span><?= esc_html((string) ($r['ad'] ?? '')) ?></span><?php endif; ?></div><?php endforeach; ?></div>
<?php if (! empty($d['kucuk_not'])): ?><p class="rf-note"><?= mx_h($d['kucuk_not']) ?></p><?php endif; ?><?php endif; ?>
</div></section>
