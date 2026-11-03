<?php
/** Üst şerit (Görünüm › Moximu ayarları). */
defined('ABSPATH') || exit;
$d = mx_opt('ust_bar');
$k = mx_kampanya();
?>
<?php if ($k): ?>
<a class="mxkmp" href="<?= mx_url($k['baglanti'] ?? '/fiyatlar/') ?>" data-end="<?= (int) $k['ts'] ?>"><span class="in"><span class="mxkmp-t"><b><?= mx_h($k['etiket'] ?? '') ?></b><span class="mxkmp-m"><?= mx_h($k['baslik'] ?? '') ?></span><span class="mxkmp-x"><?= mx_h($k['metin'] ?? '') ?></span></span><span class="mxkmp-cd" aria-label="Kalan süre">Bitmesine <b data-cd>…</b></span><span class="mxkmp-go"><?= mx_h($k['buton'] ?? 'İnceleyin') ?> <i>→</i></span></span></a>
<script>(function(a){var end=+a.dataset.end*1000,b=a.querySelector('[data-cd]'),u=function(n){return(n<10?'0':'')+n};function t(){var r=Math.max(0,end-Date.now());if(!r){a.remove();return}var s=Math.floor(r/1e3),d=Math.floor(s/86400);b.textContent=(d?d+' gün ':'')+u(Math.floor(s%86400/3600))+':'+u(Math.floor(s%3600/60))+':'+u(s%60);setTimeout(t,1000)}t()})(document.currentScript.previousElementSibling);</script>
<?php endif; ?>
<div class="mxbar"><div class="in"><span class="dot"></span><span class="lead"><?= mx_h(($d['yazi'] ?? null)) ?></span></div></div>
