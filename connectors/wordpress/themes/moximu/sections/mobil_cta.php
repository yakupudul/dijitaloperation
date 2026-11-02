<?php
/** Mobil arama çubuğu — bölüm şablonu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<div class="lp-mcta"><a class="wa" href="<?= esc_url(mx_wa_url()) ?>" rel="noopener" target="_blank"><?= mx_wa_icon() ?>WhatsApp</a><a class="go" href="<?= mx_url(($d['baglanti'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></div>
