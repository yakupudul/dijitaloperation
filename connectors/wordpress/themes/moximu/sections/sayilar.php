<?php
/** Sayılar — bölüm şablonu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<section class="mx lp-nums" id="rakamlar"><div class="wrap"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-loop.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2><div class="lp-nums-g"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><div class="lp-num"><?= mx_h(($r1['yazi'] ?? null)) ?></div><?php endforeach; ?></div></div></section>
