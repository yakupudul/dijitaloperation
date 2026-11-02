<?php
/** Adımlar — bölüm şablonu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<?php $mx_v = $d['tasarim'] ?? 'v1'; ?>
<?php if ($mx_v === 'v1'): ?>
<section class="mx lp-steps" id="takvim"><div class="wrap">
<div class="lp-steps-g">
<div><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2><p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p><a class="btn" href="<?= mx_url(($d['baglanti'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></div>
<ol class="lp-tl" data-cycle-tl=""><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><li><b><?= mx_h(($r1['kalin_yazi'] ?? null)) ?></b><h3><?= mx_h(($r1['alt_baslik'] ?? null)) ?></h3><p><?= mx_h(($r1['metin'] ?? null)) ?></p></li><?php endforeach; ?></ol>
</div>
</div></section>
<?php elseif ($mx_v === 'v2'): ?>
<section class="mx lp-steps" id="zaman"><div class="wrap">
<div class="lp-steps-g">
<div><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2><p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p><a class="btn" href="<?= mx_url(($d['baglanti'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></div>
<ol class="lp-tl" data-cycle-tl=""><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><li><b><?= mx_h(($r1['kalin_yazi'] ?? null)) ?></b><h3><?= mx_h(($r1['alt_baslik'] ?? null)) ?></h3><p><?= mx_h(($r1['metin'] ?? null)) ?></p></li><?php endforeach; ?></ol>
</div>
</div></section>
<?php endif; ?>
