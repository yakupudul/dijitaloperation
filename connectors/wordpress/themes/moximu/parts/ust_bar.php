<?php
/** Üst şerit (Görünüm › Moximu ayarları). */
defined('ABSPATH') || exit;
$d = mx_opt('ust_bar');
$k = mx_kampanya();
?>
<?php if ($k): ?>
<a class="mxkmp" href="<?= mx_url($k['baglanti'] ?? '/fiyatlar/') ?>" data-end="<?= (int) $k['ts'] ?>"><span class="in"><img class="mxkmp-i" alt="" width="22" height="22" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><span class="mxkmp-t"><b><?= mx_h($k['etiket'] ?? '') ?></b><span class="mxkmp-m"><?= mx_h($k['baslik'] ?? '') ?></span></span><span class="mxkmp-cd" aria-label="Kampanyanın bitmesine kalan süre"><i><b data-u="d">00</b><small>Gün</small></i><i><b data-u="h">00</b><small>Saat</small></i><i><b data-u="m">00</b><small>Dakika</small></i><i><b data-u="s">00</b><small>Saniye</small></i></span><span class="mxkmp-go"><?= mx_h($k['buton'] ?? 'İnceleyin') ?> <i>→</i></span></span></a>
<script>(function(a){var end=+a.dataset.end*1000,u=function(n){return(n<10?'0':'')+n},q=function(k){return a.querySelector('[data-u='+k+']')};function t(){var r=Math.max(0,end-Date.now());if(!r){a.remove();return}var s=Math.floor(r/1e3);q('d').textContent=u(Math.floor(s/86400));q('h').textContent=u(Math.floor(s%86400/3600));q('m').textContent=u(Math.floor(s%3600/60));q('s').textContent=u(s%60);setTimeout(t,1000)}t()})(document.currentScript.previousElementSibling);</script>
<?php else: ?>
<div class="mxbar"><div class="in"><span class="dot"></span><span class="lead"><?= mx_h(($d['yazi'] ?? null)) ?></span></div></div>
<?php endif; ?>
