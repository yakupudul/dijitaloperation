<?php
/** Analiz süreci — saatli üç adım (panelden düzenlenir). liste: yazi (zaman), baslik, metin. */
defined('ABSPATH') || exit;
?>
<section class="mx an-steps"><div class="wrap">
<div class="rf-head"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h($d['yazi'] ?? null) ?></span><h2><?= mx_h($d['baslik'] ?? null) ?></h2></div>
<ol class="an-steps-l"><?php foreach (mx_rows($d['liste'] ?? null) as $i => $r): ?><li><span class="an-t"><?= mx_h($r['yazi'] ?? null) ?></span><b><?= mx_h($r['baslik'] ?? null) ?></b><p><?= mx_h($r['metin'] ?? null) ?></p></li><?php endforeach; ?></ol>
</div></section>
