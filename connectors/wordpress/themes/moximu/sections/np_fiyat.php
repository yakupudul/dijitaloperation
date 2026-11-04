<?php
/** Tek hizmet fiyat kutusu: fiyat {fiyat:seo} gibi yazılır, Moximu ayarları › Fiyatlar'dan gelir. */
defined('ABSPATH') || exit;
?>
<?= mx_np_open($d, 'fiyat') ?>
<div class="np-2">
<?= mx_np_head($d) ?>
<div class="np-box"><div class="np-price"><b><?= mx_h($d['fiyat'] ?? '') ?></b><span><?= mx_h($d['fiyat_alt'] ?? '') ?></span></div><?php if (! empty($d['not'])) : ?><span class="np-note"><?= mx_h($d['not']) ?></span><?php endif; ?>
<ul class="np-li"><?php foreach (mx_np_lines($d['maddeler'] ?? '') as $mx_l) : ?><li><?= mx_h($mx_l) ?></li><?php endforeach; ?></ul>
<?php if (! empty($d['buton_yazisi'])) : ?><a class="btn np-k" href="<?= mx_url($d['buton_link'] ?? '') ?>" style="margin-top:20px"><?= mx_h($d['buton_yazisi']) ?></a><?php endif; ?></div>
</div>
</div></section>
