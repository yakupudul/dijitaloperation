<?php
/** Branş kutuları: başlık, kısa açıklama, alt sayfa bağlantıları ("Yazı | /adres/" satır satır). */
defined('ABSPATH') || exit;
$mx_rows = mx_rows($d['liste'] ?? null);
?>
<?= mx_np_open($d, 'branslar') ?>
<?php if (! empty($d['baslik'])) : ?><?= mx_np_head($d) ?><?php endif; ?>
<div class="np-br<?= count($mx_rows) === 1 ? ' tek' : '' ?>">
<?php foreach ($mx_rows as $r) : ?><div><h3><?php if (! empty($r['baglanti'])) : ?><a href="<?= mx_url($r['baglanti']) ?>"><?= mx_h($r['baslik'] ?? '') ?></a><?php else : ?><?= mx_h($r['baslik'] ?? '') ?><?php endif; ?></h3><?php if (! empty($r['metin'])) : ?><p><?= mx_h($r['metin']) ?></p><?php endif; ?><nav><?php foreach (mx_np_lines($r['linkler'] ?? '') as $mx_l) : [$mx_t, $mx_u] = mx_np_pair($mx_l) + ['', '']; ?><a href="<?= mx_url($mx_u) ?>"><?= mx_h($mx_t) ?></a><?php endforeach; ?></nav></div><?php endforeach; ?>
</div>
</div></section>
