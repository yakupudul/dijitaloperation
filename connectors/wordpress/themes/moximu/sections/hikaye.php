<?php
/** Hikâye — bölüm şablonu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<section class="mx lp-story" id="hikaye"><div class="wrap"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-loop.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2><div class="lp-story-g"><div class="lp-story-t">
<?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><p><?= mx_h(($r1['metin'] ?? null)) ?></p><?php endforeach; ?>


</div>
<ol class="lp-story-tl"><?php foreach (mx_rows(($d['liste_2'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ol></div></div></section>
