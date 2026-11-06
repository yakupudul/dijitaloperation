<?php
/** Koyu bant: bir haftanın düzeni (gün gün) ve yanında örnek WhatsApp haftalık özeti (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<?= mx_np_open($d, 'hafta', 'nc-wk') ?>
<div class="nc-wk-g">
<div>
<?= mx_np_head($d) ?>
<ol class="nc-days">
<?php foreach (mx_rows($d['liste'] ?? null) as $r) : ?><li><span><?= mx_h($r['ust'] ?? '') ?></span><div><h3><?= mx_h($r['baslik'] ?? '') ?></h3><p><?= mx_h($r['metin'] ?? '') ?></p></div></li><?php endforeach; ?>
</ol>
</div>
<div class="nc-phone" aria-hidden="true"><div class="nc-scr">
<div class="nc-ph"><i>M</i><div><b>Moximu</b><small>çevrimiçi</small></div></div>
<div class="nc-chat">
<div class="nc-msg"><b><?= mx_h($d['mesaj_baslik'] ?? '') ?></b>
<?php foreach (mx_np_lines($d['mesaj'] ?? '') as $mx_t) : $mx_w = str_starts_with($mx_t, '!'); ?><p class="<?= $mx_w ? 'w' : '' ?>"><?= mx_h(ltrim($mx_t, '! ')) ?></p><?php endforeach; ?>
<small>Pzt 09:12 ✓✓</small></div>
<div class="nc-msg me"><p><?= mx_h($d['cevap'] ?? '') ?></p><small>09:40</small></div>
</div>
</div><em>Örnek haftalık özet</em></div>
</div>
</div></section>
