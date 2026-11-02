<?php
/** Fiyat — bölüm şablonu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<?php $mx_v = $d['tasarim'] ?? 'v1'; ?>
<?php if ($mx_v === 'v1'): ?>
<section class="mx lp-price" id="fiyat"><div class="wrap">
<span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-loop.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span>
<h2><?= mx_h(($d['baslik'] ?? null)) ?></h2>
<div class="lp-price-g">
<div class="lp-pcard main"><div class="lp-ph"><?= mx_h(($d['yazi_2'] ?? null)) ?></div><div class="lp-pv"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><p><?= mx_h(($d['metin'] ?? null)) ?></p><ul><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ul><a class="btn" href="<?= mx_url(($d['baglanti'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></div>
<div class="lp-pside"><div class="lp-pcard"><div class="lp-ph"><?= mx_h(($d['yazi_4'] ?? null)) ?></div><div class="lp-pv sm"><?= mx_h(($d['yazi_5'] ?? null)) ?></div><p><?= mx_h(($d['metin_2'] ?? null)) ?></p></div><div class="lp-pcard"><div class="lp-ph"><?= mx_h(($d['yazi_6'] ?? null)) ?></div><div class="lp-pv sm"><?= mx_h(($d['yazi_7'] ?? null)) ?></div><p><?= mx_h(($d['metin_3'] ?? null)) ?></p><a href="<?= mx_url(($d['baglanti_2'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz_2'] ?? null)) ?></a></div></div>
</div>
</div></section>
<?php elseif ($mx_v === 'v2'): ?>
<section class="mx lp-price" id="fiyat"><div class="wrap">
<span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-loop.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span>
<h2><?= mx_h(($d['baslik'] ?? null)) ?></h2>
<div class="lp-price-g">
<div class="lp-pcard main"><div class="lp-ph"><?= mx_h(($d['yazi_2'] ?? null)) ?></div><div class="lp-pv"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><p><?= mx_h(($d['metin'] ?? null)) ?></p><ul><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ul><a class="btn" href="<?= mx_url(($d['baglanti'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></div>
<div class="lp-pside"><?php foreach (mx_rows(($d['liste_2'] ?? null)) as $i1 => $r1): ?><div class="lp-pcard"><div class="lp-ph"><?= mx_h(($r1['yazi'] ?? null)) ?></div><div class="lp-pv sm"><?= mx_h(($r1['yazi_2'] ?? null)) ?></div><p><?= mx_h(($r1['metin'] ?? null)) ?></p><a href="<?= mx_url(($r1['baglanti'] ?? null)) ?>"><?= mx_h(($r1['baglanti_yaz'] ?? null)) ?></a></div><?php endforeach; ?></div>
</div>
</div></section>
<?php elseif ($mx_v === 'v3'): ?>
<section class="mx lp-price" id="fiyat"><div class="wrap">
<span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-loop.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span>
<h2><?= mx_h(($d['baslik'] ?? null)) ?></h2>
<div class="lp-price-g">
<div class="lp-pcard main"><div class="lp-ph"><?= mx_h(($d['yazi_2'] ?? null)) ?></div><div class="lp-pv"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><p><?= mx_h(($d['metin'] ?? null)) ?></p><ul><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ul><a class="btn" href="<?= mx_url(($d['baglanti'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></div>
<div class="lp-pside"><div class="lp-pcard"><div class="lp-ph"><?= mx_h(($d['yazi_4'] ?? null)) ?></div><div class="lp-pv sm"><?= mx_h(($d['yazi_5'] ?? null)) ?></div><p><?= mx_h(($d['metin_2'] ?? null)) ?></p><a href="<?= mx_url(($d['baglanti_2'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz_2'] ?? null)) ?></a></div><div class="lp-pcard"><div class="lp-ph"><?= mx_h(($d['yazi_6'] ?? null)) ?></div><div class="lp-pv sm"><?= mx_h(($d['yazi_7'] ?? null)) ?></div><p><?= mx_h(($d['metin_3'] ?? null)) ?></p></div></div>
</div>
</div></section>
<?php elseif ($mx_v === 'v4'): ?>
<section class="mx lp-price lp-price2" id="paketler"><div class="wrap"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-loop.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2><p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p><div class="lp-price3"><div class="lp-pcard main"><div class="lp-ph"><?= mx_h(($d['yazi_2'] ?? null)) ?></div><div class="lp-pv"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><p><?= mx_h(($d['metin_2'] ?? null)) ?></p><ul><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ul><a class="btn" href="<?= mx_url(($d['baglanti'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></div><?php foreach (mx_rows(($d['liste_2_v4'] ?? null)) as $i1 => $r1): ?><div class="lp-pcard"><div class="lp-ph"><?= mx_h(($r1['yazi'] ?? null)) ?></div><div class="lp-pv sm"><?= mx_h(($r1['yazi_2'] ?? null)) ?></div><p><?= mx_h(($r1['metin'] ?? null)) ?></p><ul><?php foreach (mx_rows(($r1['liste'] ?? null)) as $i2 => $r2): ?><li><?= mx_h(($r2['madde'] ?? null)) ?></li><?php endforeach; ?></ul><a class="btn outline" href="<?= mx_url(($r1['baglanti'] ?? null)) ?>"><?= mx_h(($r1['baglanti_yaz'] ?? null)) ?></a></div><?php endforeach; ?></div></div></section>
<?php endif; ?>
