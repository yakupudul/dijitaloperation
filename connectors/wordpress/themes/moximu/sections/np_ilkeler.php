<?php
/** Koyu kutu: solda başlık ve buton, sağda numaralı ilkeler (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<?= mx_np_open($d, 'nasil') ?>
<div class="np-dark">
<div><?php if (! empty($d['ust'])) : ?><span class="eyebrow"><?= mx_h($d['ust']) ?></span><?php endif; ?><h2><?= mx_h($d['baslik'] ?? '') ?></h2><?php if (! empty($d['metin'])) : ?><p><?= mx_h($d['metin']) ?></p><?php endif; ?><?php if (! empty($d['buton_yazisi'])) : ?><a class="btn np-y" href="<?= mx_url($d['buton_link'] ?? '') ?>" style="margin-top:22px"><?= mx_h($d['buton_yazisi']) ?></a><?php endif; ?></div>
<ol class="np-il">
<?php foreach (mx_rows($d['liste'] ?? null) as $mx_i => $r) : ?><li><span><?= (int) $mx_i + 1 ?></span><div><b><?= mx_h($r['baslik'] ?? '') ?></b><em><?= mx_h($r['metin'] ?? '') ?></em></div></li><?php endforeach; ?>
</ol>
</div>
</div></section>
