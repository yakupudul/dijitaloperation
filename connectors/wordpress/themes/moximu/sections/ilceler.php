<?php
/** İlçeler — bölüm şablonu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<section class="mx lp-city-ilce" id="ilceler"><div class="wrap"><div class="exp-head"><div><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2><p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p></div></div><div class="lp-ilce" data-cycle-ilce=""><?= mx_h(($d['yazi_2'] ?? null)) ?></div><p class="lp-note"><?= mx_h(($d['metin_2'] ?? null)) ?></p></div></section>
