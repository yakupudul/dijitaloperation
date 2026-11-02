<?php
/** Tek hizmet fiyatları — bölüm şablonu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<section class="mx lp-single" id="tek-hizmet"><div class="wrap"><div class="exp-head"><div><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-loop.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2><p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p></div></div><div class="lp-srows"><?= mx_h(($d['yazi_2'] ?? null)) ?></div></div></section>
