<?php
/** Başvuru / iletişim formu bölümü: solda başlık ve maddeler, sağda form; konu önceden seçilir (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<?= mx_np_open($d, 'basvuru', 'ilt-fs') ?>
<div class="ilt-fs-g">
<div><?= mx_np_head($d) ?><?php if ($mx_l = mx_np_lines($d['maddeler'] ?? '')) : ?><ul class="ort-tk"><?php foreach ($mx_l as $mx_t) : ?><li><?= mx_h($mx_t) ?></li><?php endforeach; ?></ul><?php endif; ?></div>
<div class="ilt-fc"><?= mx_iletisim_form((string) ($d['konu'] ?? 'teklif'), (string) ($d['buton_yazisi'] ?? '') ?: 'Başvuruyu gönderin', ! empty($d['firma'])) ?></div>
</div>
</div></section>
