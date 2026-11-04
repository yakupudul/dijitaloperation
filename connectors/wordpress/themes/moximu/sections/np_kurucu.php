<?php
/** Kurucu: fotoğraf, başlık, paragraflar, imza ve rakamlar (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<?= mx_np_open($d, 'kurucu') ?>
<div class="np-fd">
<?php if (! empty($d['gorsel'])) : ?><img <?= mx_img_wh($d['gorsel']) ?>alt="<?= mx_img_alt($d['gorsel']) ?>" decoding="async" loading="lazy" src="<?= mx_img_src($d['gorsel']) ?>"><?php endif; ?>
<div><?php if (! empty($d['ust'])) : ?><span class="eyebrow"><?= mx_h($d['ust']) ?></span><?php endif; ?><h2><?= mx_h($d['baslik'] ?? '') ?></h2>
<?php foreach (mx_rows($d['liste'] ?? null) as $r) : ?><p><?= mx_h($r['metin'] ?? '') ?></p><?php endforeach; ?>
<?php if (! empty($d['ad'])) : ?><div class="sig"><?= mx_h($d['ad']) ?><small><?= mx_h($d['unvan'] ?? '') ?></small></div><?php endif; ?>
<?php if ($mx_n = mx_rows($d['rakamlar'] ?? null)) : ?><div class="nums"><?php foreach ($mx_n as $r) : ?><div><b><?= mx_h($r['rakam'] ?? '') ?></b><span><?= mx_h($r['yazi'] ?? '') ?></span></div><?php endforeach; ?></div><?php endif; ?>
<?php if (! empty($d['buton_yazisi'])) : ?><p class="np-more"><a href="<?= mx_url($d['buton_link'] ?? '') ?>"><?= mx_h($d['buton_yazisi']) ?> →</a></p><?php endif; ?>
</div></div>
</div></section>
