<?php
/** Google puanı — bölüm şablonu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<section class="mx lp-rev" id="yorumlar"><div class="wrap">
<div class="lp-rev-g">
<div class="lp-rev-s"><?= mx_h(($d['yazi'] ?? null)) ?></div>
<p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p>
<a class="btn outline" href="<?= mx_url(($d['baglanti'] ?? null)) ?>" rel="noopener" target="_blank"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a>
</div>
</div></section>
