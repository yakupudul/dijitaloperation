<?php
/** Rakamlar — bölüm şablonu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<section data-logos="<?= esc_attr(mx_ray_logos()) ?>" class="mx mxstats" id="rakamlar"><div class="wrap">
<div class="head"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span>
<h2><?= mx_h(($d['baslik'] ?? null)) ?></h2></div>
<div class="stats" role="tablist">
<button class="st on" data-i="0" type="button"><?= mx_h(($d['buton_yazisi'] ?? null)) ?></button>
<?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><button class="st" data-i="<?= (int) $i1 + 1 ?>" type="button"><?= mx_h(($r1['buton_yazisi'] ?? null)) ?></button><?php endforeach; ?>


</div>
<div aria-hidden="true" class="viz"><canvas class="rays"></canvas><div class="tips"></div><img alt="" class="sun" src="<?= MX_URI ?>/assets/i/mark-static.svg"/></div>
<p class="note"><?= mx_h(($d['metin'] ?? null)) ?></p>
</div></section>
