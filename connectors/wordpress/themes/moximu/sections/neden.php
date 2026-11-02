<?php
/** Neden — bölüm şablonu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<?php $mx_v = $d['tasarim'] ?? 'v1'; ?>
<?php if ($mx_v === 'v1'): ?>
<section class="mx lp-why lp-why2" id="neden"><div class="wrap">
<div class="lp-why-top"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2></div>
<div class="lp-why-g2">
<div class="lp-why-photo"><img <?= mx_img_wh(($d['gorsel'] ?? null)) ?> alt="<?= mx_img_alt(($d['gorsel'] ?? null)) ?>" decoding="async" loading="<?= mx_loading() ?>" src="<?= mx_img_src(($d['gorsel'] ?? null)) ?>"/><div class="notif lp-fl f1"><?= mx_h(($d['yazi_2'] ?? null)) ?></div><div class="notif lp-fl f2"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><div class="notif lp-fl f3"><?= mx_h(($d['yazi_4'] ?? null)) ?></div></div>
<div class="lp-why-g"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><div class="lp-why-c"><h3><?= mx_h(($r1['alt_baslik'] ?? null)) ?></h3><p><?= mx_h(($r1['metin'] ?? null)) ?></p></div><?php endforeach; ?></div>
</div>
</div></section>
<?php elseif ($mx_v === 'v2'): ?>
<section class="mx lp-why lp-why2" id="kurallar"><div class="wrap"><div class="lp-why-top"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2></div><div class="lp-why-g2"><div class="lp-why-photo"><img <?= mx_img_wh(($d['gorsel'] ?? null)) ?> alt="<?= mx_img_alt(($d['gorsel'] ?? null)) ?>" decoding="async" loading="<?= mx_loading() ?>" src="<?= mx_img_src(($d['gorsel'] ?? null)) ?>"/><div class="notif lp-fl f1"><?= mx_h(($d['yazi_2'] ?? null)) ?></div><div class="notif lp-fl f2"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><div class="notif lp-fl f3"><?= mx_h(($d['yazi_4'] ?? null)) ?></div></div><div class="lp-why-g"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><div class="lp-why-c"><h3><?= mx_h(($r1['alt_baslik'] ?? null)) ?></h3><p><?= mx_h(($r1['metin'] ?? null)) ?></p></div><?php endforeach; ?></div></div></div></section>
<?php elseif ($mx_v === 'v3'): ?>
<section class="mx lp-why lp-why2" id="dahil"><div class="wrap">
<div class="lp-why-top"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2></div>
<div class="lp-why-g2">
<div class="lp-why-photo"><img <?= mx_img_wh(($d['gorsel'] ?? null)) ?> alt="<?= mx_img_alt(($d['gorsel'] ?? null)) ?>" decoding="async" loading="<?= mx_loading() ?>" src="<?= mx_img_src(($d['gorsel'] ?? null)) ?>"/><div class="notif lp-fl f1"><?= mx_h(($d['yazi_2'] ?? null)) ?></div><div class="notif lp-fl f2"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><div class="notif lp-fl f3"><?= mx_h(($d['yazi_4'] ?? null)) ?></div></div>
<div class="lp-why-g"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><div class="lp-why-c"><h3><?= mx_h(($r1['alt_baslik'] ?? null)) ?></h3><p><?= mx_h(($r1['metin'] ?? null)) ?></p></div><?php endforeach; ?></div>
</div>
</div></section>
<?php elseif ($mx_v === 'v4'): ?>
<section class="mx lp-why lp-why2" id="ilkeler"><div class="wrap">
<div class="lp-why-top"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2></div>
<div class="lp-why-g2">
<div class="lp-why-photo"><img <?= mx_img_wh(($d['gorsel'] ?? null)) ?> alt="<?= mx_img_alt(($d['gorsel'] ?? null)) ?>" decoding="async" loading="<?= mx_loading() ?>" src="<?= mx_img_src(($d['gorsel'] ?? null)) ?>"/><div class="notif lp-fl f1"><?= mx_h(($d['yazi_2'] ?? null)) ?></div><div class="notif lp-fl f2"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><div class="notif lp-fl f3"><?= mx_h(($d['yazi_4'] ?? null)) ?></div></div>
<div class="lp-why-g"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><div class="lp-why-c"><h3><?= mx_h(($r1['alt_baslik'] ?? null)) ?></h3><p><?= mx_h(($r1['metin'] ?? null)) ?></p></div><?php endforeach; ?></div>
</div>
</div></section>
<?php endif; ?>
