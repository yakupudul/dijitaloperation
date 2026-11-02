<?php
/** Tedaviye göre — bölüm şablonu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<section class="mx lp-inc lp-tedavi" id="tedaviler"><div class="wrap"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-loop.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2><p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p><div class="lp-cards"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><article><span class="n"><?= mx_h(($r1['yazi'] ?? null)) ?></span><h3><?= mx_h(($r1['alt_baslik'] ?? null)) ?></h3><p><?= mx_h(($r1['metin'] ?? null)) ?></p><ul><?php foreach (mx_rows(($r1['liste'] ?? null)) as $i2 => $r2): ?><li><?= mx_h(($r2['madde'] ?? null)) ?></li><?php endforeach; ?></ul></article><?php endforeach; ?></div></div></section>
