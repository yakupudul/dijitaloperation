<?php
/** Klinik duvarı — bölüm şablonu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<?php $mx_v = $d['tasarim'] ?? 'v1'; ?>
<?php if ($mx_v === 'v1'): ?>
<section class="mx lp-wall" id="klinikler"><div class="wrap"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-loop.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2><div class="lp-wall-g"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><div><img <?= mx_img_wh(($r1['gorsel'] ?? null)) ?> alt="<?= mx_img_alt(($r1['gorsel'] ?? null)) ?>" decoding="async" loading="<?= mx_loading() ?>" src="<?= mx_img_src(($r1['gorsel'] ?? null)) ?>" title="Avrupadent"/></div><?php endforeach; ?></div><div class="cap"><?= mx_h(($d['yazi_2'] ?? null)) ?></div></div></section>
<?php elseif ($mx_v === 'v2'): ?>
<section class="mx lp-wall" id="klinikler"><div class="wrap"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-loop.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2><div class="lp-wall-g three"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><div><img <?= mx_img_wh(($r1['gorsel'] ?? null)) ?> alt="<?= mx_img_alt(($r1['gorsel'] ?? null)) ?>" decoding="async" loading="<?= mx_loading() ?>" src="<?= mx_img_src(($r1['gorsel'] ?? null)) ?>" title="Panorama Ankara"/></div><?php endforeach; ?></div><div class="cap"><?= mx_h(($d['yazi_2'] ?? null)) ?></div></div></section>
<?php endif; ?>
