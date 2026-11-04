<?php
/** Solda başlık ve maddeler, sağda koyu bilgi kutusu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<?= mx_np_open($d, 'ne-yapiyoruz') ?>
<div class="np-2">
<div><?= mx_np_head($d) ?><ul class="np-li"><?php foreach (mx_np_lines($d['maddeler'] ?? '') as $mx_l) : ?><li><?= mx_h($mx_l) ?></li><?php endforeach; ?></ul></div>
<?php if (! empty($d['kutu_baslik'])) : ?><div class="np-box np-box-d"><h3><?= mx_h($d['kutu_baslik']) ?></h3><ul class="np-li"><?php foreach (mx_np_lines($d['kutu_maddeler'] ?? '') as $mx_l) : ?><li><?= mx_h($mx_l) ?></li><?php endforeach; ?></ul><?php if (! empty($d['kutu_not'])) : ?><p class="np-src"><?= mx_h($d['kutu_not']) ?></p><?php endif; ?></div><?php endif; ?>
</div>
</div></section>
