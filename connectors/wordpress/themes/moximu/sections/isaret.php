<?php
/**
 * İşaret — logonun hikâyesi (panelden düzenlenir). Gerçek logo büyük çizilir; dört daire dört kanalı, sarı çizgi
 * dördünü birleştiren tek planı anlatır. Liste satırları sırayla sol üst, sağ üst, sol alt, sağ alt daireye denk gelir.
 */
defined('ABSPATH') || exit;
$mx_rows = mx_rows($d['liste'] ?? null);
?>
<section class="mx mxsign" id="isaret"><div class="wrap"><div class="mxsign-g">
<div class="mxsign-art" aria-hidden="true"><div class="mxsign-tile"><img alt="" width="1000" height="1000" decoding="async" src="<?= MX_URI ?>/assets/i/mark-static.svg"/>
<svg class="mxsign-line" viewBox="0 0 1000 1000"><path pathLength="1" d="M315 685V315L500 500L685 315V685"/></svg>
<?php foreach (array_slice($mx_rows, 0, 4) as $i => $r): ?><span class="mxsign-n n<?= $i + 1 ?>"><?= $i + 1 ?></span><?php endforeach; ?>
</div><span class="mxsign-cap"><?= mx_h(($d['kucuk_not'] ?? null)) ?></span></div>
<div class="mxsign-t"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2><p class="mxsign-lead"><?= mx_h(($d['metin'] ?? null)) ?></p>
<ol class="mxsign-l"><?php foreach ($mx_rows as $i => $r): ?><li class="<?= $i < 4 ? 'q'.($i + 1) : 'ln' ?>"><i><?= $i < 4 ? $i + 1 : '' ?></i><div><b><?= mx_h(($r['baslik'] ?? null)) ?></b><span><?= mx_h(($r['metin'] ?? null)) ?></span></div></li><?php endforeach; ?></ol>
<?php if (! empty($d['metin_2'])): ?><p class="mxsign-foot"><?= mx_h($d['metin_2']) ?></p><?php endif; ?></div>
</div></div></section>
