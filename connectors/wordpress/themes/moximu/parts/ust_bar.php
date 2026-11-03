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
<div class="mxbar"><div class="in"><span class="dot"></span><span class="lead"><?= mx_h(($d['yazi'] ?? null)) ?></span><div class="r"><a href="https://wa.me/905388610288" rel="noopener" target="_blank"><svg fill="currentColor" viewbox="0 0 24 24"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.6.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.8 11.9 11.9 0 0 0 4.6 4c1.7.7 2.3.8 3.2.7a2.7 2.7 0 0 0 1.8-1.3 2.2 2.2 0 0 0 .2-1.3c-.1-.1-.3-.2-.5-.3z"></path></svg><span><?= mx_h(($d['yazi_2'] ?? null)) ?></span></a></div></div></div>
