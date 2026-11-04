<?php
/** Sık sorulan sorular (açılır kutular); sayfanın FAQPage verisi de buradan üretilir. */
defined('ABSPATH') || exit;
?>
<?= mx_np_open($d, 'sss') ?>
<?= mx_np_head($d, true) ?>
<div class="np-faq">
<?php foreach (mx_rows($d['liste'] ?? null) as $mx_i => $r) : ?><details<?= $mx_i === 0 ? ' open' : '' ?>><summary><?= mx_h($r['baslik'] ?? '') ?></summary><p><?= mx_h($r['metin'] ?? '') ?></p></details><?php endforeach; ?>
</div>
</div></section>
