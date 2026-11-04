<?php
/** Solda başlık, sağda kutu içinde numaralı adımlar (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<?= mx_np_open($d, 'istek') ?>
<div class="np-2">
<?= mx_np_head($d) ?>
<div class="np-box"><ol class="np-st" style="margin:0">
<?php foreach (mx_rows($d['liste'] ?? null) as $mx_i => $r) : ?><li><span><?= (int) $mx_i + 1 ?></span><div><b><?= mx_h($r['baslik'] ?? '') ?></b><em><?= mx_h($r['metin'] ?? '') ?></em></div></li><?php endforeach; ?>
</ol></div>
</div>
</div></section>
