<?php
/**
 * İşaret — logonun açılış hareketi ve anlattığı çalışma sırası (panelden düzenlenir).
 * Logo burada CSS ile çizilir: daireler sırayla belirir, her birinin ışığı tam tur döner, sonra sarı çizgi çekilir.
 * liste: hareketin her aşaması (baslik + metin); aşama sırası animasyonla eşlenir, ilk dört satır kullanılır.
 */
defined('ABSPATH') || exit;
$mx_rows = array_slice(mx_rows($d['liste'] ?? null), 0, 4);
?>
<section class="mx mk" id="isaret"><div class="wrap mk-g">
<div class="mk-art" aria-hidden="true"><div class="mk-tile">
<span class="mk-c c1"><i></i></span><span class="mk-c c2"><i></i></span><span class="mk-c c3"><i></i></span><span class="mk-c c4"><i></i></span>
<svg class="mk-l" viewBox="0 0 1000 1000"><line x1="315" y1="315" x2="315" y2="685"/><line x1="685" y1="315" x2="685" y2="685"/><line x1="315" y1="315" x2="445.81" y2="445.81"/><line x1="685" y1="315" x2="554.19" y2="445.81"/></svg>
</div><div class="mk-bar"><?php foreach ($mx_rows as $i => $r): ?><span><i></i></span><?php endforeach; ?></div><span class="mk-cap"><?= mx_h($d['kucuk_not'] ?? null) ?></span></div>
<div class="mk-t"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h($d['yazi'] ?? null) ?></span><h2><?= mx_h($d['baslik'] ?? null) ?></h2><p class="mk-lead"><?= mx_h($d['metin'] ?? null) ?></p>
<ol class="mk-l2"><?php foreach ($mx_rows as $i => $r): ?><li data-s="<?= $i + 1 ?>"><span class="mk-no">0<?= $i + 1 ?></span><div><b><?= mx_h($r['baslik'] ?? null) ?></b><span><?= mx_h($r['metin'] ?? null) ?></span></div></li><?php endforeach; ?></ol>
<?php if (! empty($d['metin_2'])): ?><p class="mk-foot"><?= mx_h($d['metin_2']) ?></p><?php endif; ?></div>
</div></section>
<script>(function(){var s=document.currentScript.previousElementSibling;if(!s)return;var st=0,t=null,dur=[2200,2600,2200,3400],n=s.querySelectorAll(".mk-l2 li").length||4;
function go(k){st=k;s.setAttribute("data-s",k);if(k===1)s.classList.remove("mk-out");t=setTimeout(function(){if(k<n){go(k+1)}else{s.classList.add("mk-out");t=setTimeout(function(){s.setAttribute("data-s",0);t=setTimeout(function(){go(1)},500)},700)}},dur[k-1]||2500)}
if(window.matchMedia&&matchMedia("(prefers-reduced-motion: reduce)").matches){s.setAttribute("data-s",n);s.classList.add("mk-still");return}
s.querySelector(".mk-l2").addEventListener("click",function(e){var li=e.target.closest("li");if(!li)return;clearTimeout(t);go(+li.getAttribute("data-s"))});
var io=window.IntersectionObserver&&new IntersectionObserver(function(e){e.forEach(function(x){if(x.isIntersecting&&!st)go(1)})},{threshold:.35});io?io.observe(s):go(1)})();</script>
