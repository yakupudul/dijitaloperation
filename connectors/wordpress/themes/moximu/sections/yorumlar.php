<?php
/** Google yorumları — bölüm şablonu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<section class="mxrev"><div class="mxrev-wrap"><div class="mxrev-head"><div><span class="mxrev-eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-loop.svg"/> <?= mx_h(($d['yazi'] ?? null)) ?> </span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2><p><?= mx_h(($d['metin'] ?? null)) ?></p></div><div class="mxrev-score"><div class="mxrev-num"><?= mx_h(($d['yazi_2'] ?? null)) ?></div><div class="mxrev-stars"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><div class="mxrev-cnt"><?= mx_h(($d['yazi_4'] ?? null)) ?></div><a href="<?= mx_url(($d['baglanti'] ?? null)) ?>" rel="noopener" target="_blank"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></div></div><div class="mxrev-row"><div class="mxrev-track">
<?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><article class="mxrev-card"><div class="mxrev-top"><span class="mxrev-av"><?= mx_h(($r1['yazi'] ?? null)) ?></span><div><?= mx_h(($r1['yazi_2'] ?? null)) ?></div><span class="mxrev-stars sm"><?= mx_h(($r1['yazi_3'] ?? null)) ?></span></div><p class="mxrev-q"><?= mx_h(($r1['metin'] ?? null)) ?></p><small class="mxrev-date"><?= mx_h(($r1['kucuk_not'] ?? null)) ?></small></article><?php endforeach; ?>
</div></div></div></section>
