<?php
/** İki kutu yan yana: solda onay işaretli, sağda çarpı işaretli maddeler (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<?= mx_np_open($d, 'kurallar') ?>
<?= mx_np_head($d) ?>
<div class="np-2" style="margin-top:32px">
<div class="np-box"><h3><?= mx_h($d['sol_baslik'] ?? '') ?></h3><ul class="np-li"><?php foreach (mx_np_lines($d['sol_liste'] ?? '') as $mx_l) : ?><li><?= mx_h($mx_l) ?></li><?php endforeach; ?></ul></div>
<div class="np-box"><h3><?= mx_h($d['sag_baslik'] ?? '') ?></h3><ul class="np-li x"><?php foreach (mx_np_lines($d['sag_liste'] ?? '') as $mx_l) : ?><li><?= mx_h($mx_l) ?></li><?php endforeach; ?></ul></div>
</div>
<?php if (! empty($d['alt_not'])) : ?><p class="np-src"><?= mx_h($d['alt_not']) ?></p><?php endif; ?>
</div></section>
