<?php
/** Ölçülmüş sonuç kartları: logo, branş, yüzde, ölçü ve kaynak/dönem (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<?= mx_np_open($d, 'referanslar-ozet') ?>
<?= mx_np_head($d) ?>
<div class="np-ref">
<?php foreach (mx_rows($d['liste'] ?? null) as $r) : ?><a href="<?= mx_url($r['baglanti'] ?? '') ?>"><?php if (! empty($r['gorsel'])) : ?><img <?= mx_img_wh($r['gorsel'], false) ?>alt="<?= mx_img_alt($r['gorsel']) ?>" decoding="async" loading="lazy" src="<?= mx_img_src($r['gorsel']) ?>"><?php endif; ?><small><?= mx_h($r['ust'] ?? '') ?></small><b><?= mx_h($r['rakam'] ?? '') ?></b><span><?= mx_h($r['birim'] ?? '') ?></span><em><?= mx_h($r['kaynak'] ?? '') ?></em></a><?php endforeach; ?>
</div>
<?php if (! empty($d['buton_yazisi'])) : ?><p class="np-more"><a href="<?= mx_url($d['buton_link'] ?? '') ?>"><?= mx_h($d['buton_yazisi']) ?> →</a></p><?php endif; ?>
</div></section>
