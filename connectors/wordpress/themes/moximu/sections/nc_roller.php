<?php
/** Kim ne yapar: üç sütun (Moximu, klinik, hiç yapılmayanlar) ve alt not (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<?= mx_np_open($d, 'roller') ?>
<?= mx_np_head($d, true) ?>
<div class="nc-roles">
<?php foreach (mx_rows($d['liste'] ?? null) as $mx_i => $r) : ?><div class="nc-rl nc-rl<?= (int) $mx_i + 1 ?>"><small><?= mx_h($r['ust'] ?? '') ?></small><h3><?= mx_h($r['baslik'] ?? '') ?></h3><ul><?php foreach (mx_np_lines($r['metin'] ?? '') as $mx_t) : ?><li><?= mx_h($mx_t) ?></li><?php endforeach; ?></ul></div><?php endforeach; ?>
</div>
<?php if (! empty($d['alt_not'])) : ?><p class="nc-rn"><?= mx_h($d['alt_not']) ?></p><?php endif; ?>
</div></section>
