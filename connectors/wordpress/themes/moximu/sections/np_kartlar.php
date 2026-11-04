<?php
/** Hizmet kartları: numara, başlık, açıklama, etiket ve fiyat (panelden düzenlenir; fiyatlar {fiyat:seo} gibi yazılır). */
defined('ABSPATH') || exit;
?>
<?= mx_np_open($d, 'hizmetler') ?>
<?= mx_np_head($d, true) ?>
<div class="np-svc">
<?php foreach (mx_rows($d['liste'] ?? null) as $mx_i => $r) : ?><a href="<?= mx_url($r['baglanti'] ?? '') ?>"><i><?= sprintf('%02d', $mx_i + 1) ?></i><h3><?= mx_h($r['baslik'] ?? '') ?></h3><p><?= mx_h($r['metin'] ?? '') ?></p><?php if (! empty($r['etiket'])) : ?><span class="tag"><?= mx_h($r['etiket']) ?></span><?php endif; ?><div class="pr"><?= mx_h($r['fiyat_ust'] ?? '') ?><b><?= mx_h($r['fiyat'] ?? '') ?></b></div></a><?php endforeach; ?>
</div>
<?php if (! empty($d['alt_not'])) : ?><p class="np-not"><?= mx_h($d['alt_not']) ?></p><?php endif; ?>
</div></section>
