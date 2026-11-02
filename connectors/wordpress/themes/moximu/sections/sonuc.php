<?php
/** Sonuç yüzdeleri — bölüm şablonu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<section class="mx lp-result" id="sonuc"><div class="wrap"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-loop.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2><p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p><div class="lp-res-g"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><div class="lp-res"><?= mx_h(($r1['yazi'] ?? null)) ?></div><?php endforeach; ?></div><p class="lp-note"><?= mx_h(($d['metin_2'] ?? null)) ?></p></div></section>
