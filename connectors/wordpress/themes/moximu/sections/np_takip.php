<?php
/** Örnek aylık rapor kartı + takip adımları (panelden düzenlenir; rapor rakamları "örnek" etiketiyle gösterilir). */
defined('ABSPATH') || exit;
?>
<?= mx_np_open($d, 'takip') ?>
<div class="np-tk">
<div class="np-rep" aria-label="Örnek aylık rapor">
<div class="np-rep-h"><div><b><?= mx_h($d['rapor_baslik'] ?? '') ?></b><br><?= mx_h($d['rapor_alt'] ?? '') ?></div><s>örnek</s></div>
<div class="np-kp"><?php foreach (mx_np_lines($d['rapor_kpi'] ?? '') as $mx_l) : [$mx_a, $mx_b, $mx_c] = mx_np_pair($mx_l) + ['', '', '']; ?><div><small><?= mx_h($mx_a) ?></small><b><?= mx_h($mx_b) ?></b><em><?= mx_h($mx_c) ?></em></div><?php endforeach; ?></div>
<div class="np-bars"><?php foreach (mx_np_lines($d['rapor_dagilim'] ?? '') as $mx_l) : [$mx_a, $mx_b] = mx_np_pair($mx_l) + ['', '0']; $mx_w = max(0, min(100, (int) $mx_b)); ?><div><span><?= mx_h($mx_a) ?></span><i style="--w:<?= $mx_w ?>%"></i><span>%<?= $mx_w ?></span></div><?php endforeach; ?></div>
<ul class="np-rep-l"><?php foreach (mx_np_lines($d['rapor_isler'] ?? '') as $mx_l) : ?><li><?= mx_h($mx_l) ?></li><?php endforeach; ?></ul>
</div>
<div><?= mx_np_head($d) ?>
<?php if ($mx_rows = mx_rows($d['liste'] ?? null)) : ?><ol class="np-st">
<?php foreach ($mx_rows as $r) : ?><li><span><?= mx_h($r['ikon'] ?? '') ?></span><div><b><?= mx_h($r['baslik'] ?? '') ?></b><em><?= mx_h($r['metin'] ?? '') ?></em></div></li><?php endforeach; ?>
</ol><?php endif; ?></div>
</div>
</div></section>
