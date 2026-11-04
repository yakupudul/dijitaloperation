<?php
/** Zaman çizelgesi: adım adım süreç ve "sizden istenenler" şeridi (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<?= mx_np_open($d, 'surec') ?>
<?= mx_np_head($d, true) ?>
<div class="np-tl">
<?php foreach (mx_rows($d['liste'] ?? null) as $mx_i => $r) : ?><div data-n="<?= (int) $mx_i + 1 ?>"><small><?= mx_h($r['ust'] ?? '') ?></small><h3><?= mx_h($r['baslik'] ?? '') ?></h3><p><?= mx_h($r['metin'] ?? '') ?></p></div><?php endforeach; ?>
</div>
<?php if (! empty($d['gerek_baslik'])) : ?><div class="np-need"><b><?= mx_h($d['gerek_baslik']) ?></b><span><?= mx_h($d['gerek_metin'] ?? '') ?></span></div><?php endif; ?>
</div></section>
