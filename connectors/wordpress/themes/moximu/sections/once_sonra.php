<?php
/** Önce / sonra — bölüm şablonu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<?php $mx_v = $d['tasarim'] ?? 'v1'; ?>
<?php if ($mx_v === 'v1'): ?>
<section class="mx lp-ba" id="once-sonra"><div class="wrap">
<div class="lp-ba-g">
<div><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-loop.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2><p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p><ul class="lp-ticks"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ul></div>
<div class="mock ba lp-bamock" style="--p:46%">
<div class="ba-after"><div class="pg"><div class="h1"><?= mx_h(($d['yazi_2'] ?? null)) ?></div><div class="m"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><div class="sec2"><?= mx_h(($d['yazi_4'] ?? null)) ?></div><div class="sec2"><?= mx_h(($d['yazi_5'] ?? null)) ?></div><div class="sec2"><?= mx_h(($d['yazi_6'] ?? null)) ?></div><div class="cta"><?= mx_h(($d['yazi_7'] ?? null)) ?></div></div></div>
<div class="ba-before"><div class="pg"><div class="h1 g"><?= mx_h(($d['yazi_8'] ?? null)) ?></div><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i class="s"></i></div></div>
<div class="ba-tag"><?= mx_h(($d['yazi_9'] ?? null)) ?></div><div class="ba-tag r"><?= mx_h(($d['yazi_10'] ?? null)) ?></div>
<div class="ba-handle"><?= mx_h(($d['yazi_11'] ?? null)) ?></div>
</div>
</div>
</div></section>
<?php elseif ($mx_v === 'v2'): ?>
<section class="mx lp-ba" id="once-sonra"><div class="wrap">
<div class="lp-ba-g">
<div><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-loop.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2><p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p><ul class="lp-ticks"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ul></div>
<div class="mock ba lp-bamock" style="--p:46%">
<div class="ba-after"><div class="pg gbp"><div class="h1"><?= mx_h(($d['yazi_2'] ?? null)) ?></div><div class="m"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><div class="pics"><i></i><i></i><i></i><i></i></div><div class="sec2"><b><?= mx_h(($d['kalin_yazi'] ?? null)) ?></b><div class="tags"><?= mx_h(($d['yazi_4'] ?? null)) ?></div></div><?php foreach (mx_rows(($d['liste_2'] ?? null)) as $i1 => $r1): ?><div class="sec2"><?= mx_h(($r1['yazi'] ?? null)) ?></div><?php endforeach; ?><div class="cta"><?= mx_h(($d['yazi_5'] ?? null)) ?></div></div></div>
<div class="ba-before"><div class="pg gbp"><div class="h1 g"><?= mx_h(($d['yazi_6'] ?? null)) ?></div><div class="m"><?= mx_h(($d['yazi_7'] ?? null)) ?></div><div class="pics off"><i></i><i></i><i></i><i></i></div><?php foreach (mx_rows(($d['liste_3'] ?? null)) as $i1 => $r1): ?><div class="sec2"><?= mx_h(($r1['yazi'] ?? null)) ?></div><?php endforeach; ?><div class="sec2"><?= mx_h(($d['yazi_8'] ?? null)) ?></div></div></div>
<div class="ba-tag"><?= mx_h(($d['yazi_9'] ?? null)) ?></div><div class="ba-tag r"><?= mx_h(($d['yazi_10'] ?? null)) ?></div>
<div class="ba-handle"><?= mx_h(($d['yazi_11'] ?? null)) ?></div>
</div>
</div>
</div></section>
<?php elseif ($mx_v === 'v3'): ?>
<section class="mx lp-ba" id="once-sonra"><div class="wrap">
<div class="lp-ba-g">
<div><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-loop.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2><p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p><ul class="lp-ticks"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ul></div>
<div class="mock ba lp-bamock" style="--p:46%">
<div class="ba-after"><div class="pg ad"><div class="adl"><?= mx_h(($d['yazi_2'] ?? null)) ?></div><div class="h1"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><div class="m"><?= mx_h(($d['yazi_4'] ?? null)) ?></div><div class="tags"><?= mx_h(($d['yazi_5'] ?? null)) ?></div><div class="sec2"><b><?= mx_h(($d['kalin_yazi'] ?? null)) ?></b><div class="tags neg"><?= mx_h(($d['yazi_6'] ?? null)) ?></div></div><div class="sec2"><?= mx_h(($d['yazi_7'] ?? null)) ?></div></div></div>
<div class="ba-before"><div class="pg ad"><div class="adl"><?= mx_h(($d['yazi_8'] ?? null)) ?></div><div class="h1 g"><?= mx_h(($d['yazi_9'] ?? null)) ?></div><div class="m"><?= mx_h(($d['yazi_10'] ?? null)) ?></div><?php foreach (mx_rows(($d['liste_2'] ?? null)) as $i1 => $r1): ?><div class="sec2"><?= mx_h(($r1['yazi'] ?? null)) ?></div><?php endforeach; ?><div class="sec2"><?= mx_h(($d['yazi_11'] ?? null)) ?></div></div></div>
<div class="ba-tag"><?= mx_h(($d['yazi_12'] ?? null)) ?></div><div class="ba-tag r"><?= mx_h(($d['yazi_13'] ?? null)) ?></div>
<div class="ba-handle"><?= mx_h(($d['yazi_14'] ?? null)) ?></div>
</div>
</div>
</div></section>
<?php elseif ($mx_v === 'v4'): ?>
<section class="mx lp-ba" id="once-sonra"><div class="wrap">
<div class="lp-ba-g">
<div><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-loop.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2><p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p><ul class="lp-ticks"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ul></div>
<div class="mock ba lp-bamock" style="--p:46%">
<div class="ba-after"><div class="pg site"><div class="nav"><?= mx_h(($d['yazi_2'] ?? null)) ?></div><div class="h1"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><div class="m"><?= mx_h(($d['yazi_4'] ?? null)) ?></div><?php foreach (mx_rows(($d['liste_2'] ?? null)) as $i1 => $r1): ?><div class="row2"><i class="pic"></i><div><?= mx_h(($r1['yazi'] ?? null)) ?></div></div><?php endforeach; ?><div class="cta"><?= mx_h(($d['yazi_5'] ?? null)) ?></div></div></div>
<div class="ba-before"><div class="pg site"><div class="nav off"><i></i><span></span><span></span></div><div class="h1 g"><?= mx_h(($d['yazi_6'] ?? null)) ?></div><div class="m"><?= mx_h(($d['yazi_7'] ?? null)) ?></div><i class="pic big"></i><i></i><i></i><i class="s"></i></div></div>
<div class="ba-tag"><?= mx_h(($d['yazi_8'] ?? null)) ?></div><div class="ba-tag r"><?= mx_h(($d['yazi_9'] ?? null)) ?></div>
<div class="ba-handle"><?= mx_h(($d['yazi_10'] ?? null)) ?></div>
</div>
</div>
</div></section>
<?php endif; ?>
