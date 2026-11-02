<?php
/** Yazı girişi — bölüm şablonu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<section class="mx lp-art-hero" id="yazi"><div class="wrap">
<div class="lp-art-head"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h1><?= mx_h(($d['baslik'] ?? null)) ?></h1>
<p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p>
<div class="lp-art-meta"><span><img alt="" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi_2'] ?? null)) ?></span><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><span><?= mx_h(($r1['yazi'] ?? null)) ?></span><?php endforeach; ?></div></div>
</div></section>
