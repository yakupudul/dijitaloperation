<?php
/** Vaka girişi — bölüm şablonu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<section class="mx lp-vhero svc" id="vaka"><div class="wrap">
<div class="lp-vhero-g">
<div class="lp-vhero-t"><i class="mxcase-c1"></i><i class="mxcase-c2"></i><i class="mxcase-l"></i>
<div class="lp-vhero-logo"><img <?= mx_img_wh(($d['gorsel'] ?? null)) ?> alt="<?= mx_img_alt(($d['gorsel'] ?? null)) ?>" decoding="async" loading="<?= mx_loading() ?>" src="<?= mx_img_src(($d['gorsel'] ?? null)) ?>"/></div>
<small><?= mx_h(($d['kucuk_not'] ?? null)) ?></small>
<h1><?= mx_h(($d['baslik'] ?? null)) ?></h1>
<p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p>
<div class="lp-vhero-m"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><div><?= mx_h(($r1['yazi'] ?? null)) ?></div><?php endforeach; ?></div>
<em class="lp-vhero-src"><?= mx_h(($d['vurgu'] ?? null)) ?></em>
</div>
<div class="lp-vhero-v"><div class="lp-stage"><div class="lp-serp lp-serp-wide"><div class="lp-sc"><div class="h"><?= mx_h(($d['yazi'] ?? null)) ?></div><?php foreach (mx_rows(($d['liste_2'] ?? null)) as $i1 => $r1): ?><div class="row"><?= mx_h(($r1['yazi'] ?? null)) ?></div><?php endforeach; ?></div><div class="notif lp-nt"><?= mx_h(($d['yazi_2'] ?? null)) ?></div></div><div class="lp-pop p1"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><div class="lp-pop p2"><?= mx_h(($d['yazi_4'] ?? null)) ?></div></div></div>
</div>
</div></section>
