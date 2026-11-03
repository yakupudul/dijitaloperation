<?php
/**
 * Örnek rapor — raporun bir sayfasının canlı görünümü ve çevresinde dört açıklama (panelden düzenlenir).
 * Rapor içindeki değerler örnektir; "örnek" etiketiyle gösterilir. liste: dört açıklama (baslik + metin).
 */
defined('ABSPATH') || exit;
$mx_n = mx_rows($d['liste'] ?? null);
?>
<section class="mx an-rep" id="rapor"><div class="wrap">
<div class="rf-head center"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h($d['yazi'] ?? null) ?></span><h2><?= mx_h($d['baslik'] ?? null) ?></h2><?php if (! empty($d['metin'])): ?><p><?= mx_h($d['metin']) ?></p><?php endif; ?></div>
<div class="an-rep-g">
<ol class="an-notes l"><?php foreach (array_slice($mx_n, 0, 2) as $i => $r): ?><li data-n="<?= $i + 1 ?>"><i><?= $i + 1 ?></i><b><?= mx_h($r['baslik'] ?? null) ?></b><span><?= mx_h($r['metin'] ?? null) ?></span></li><?php endforeach; ?></ol>
<div class="an-doc" aria-label="Örnek rapor görünümü">
<div class="an-doc-h"><img src="<?= MX_URI ?>/assets/i/mark.webp" alt="" width="28" height="28"/><div><b>Dijital durum raporu</b><span>Örnek Diş Kliniği · İzmir, Bornova</span></div><em>örnek</em></div>
<div class="an-scores">
<div class="an-sc" style="--v:58" data-n="1"><svg viewBox="0 0 36 36"><circle cx="18" cy="18" r="15.9"/><circle class="v" cx="18" cy="18" r="15.9"/></svg><b>58</b><span>Web sitesi</span></div>
<div class="an-sc" style="--v:41" data-n="2"><svg viewBox="0 0 36 36"><circle cx="18" cy="18" r="15.9"/><circle class="v" cx="18" cy="18" r="15.9"/></svg><b>41</b><span>İşletme Profili</span></div>
<div class="an-sc warn" style="--v:20" data-n="3"><svg viewBox="0 0 36 36"><circle cx="18" cy="18" r="15.9"/><circle class="v" cx="18" cy="18" r="15.9"/></svg><b>!</b><span>Reklam ölçümü</span></div>
</div>
<div class="an-rank" data-n="2"><span>“bornova implant” haritada sıra</span><div class="an-rank-b"><i style="--p:1">1</i><i style="--p:2">2</i><i style="--p:3">3</i><i style="--p:4">4</i><i style="--p:5">5</i><i class="me" style="--p:6">6</i></div></div>
<ul class="an-finds">
<li class="r" data-n="1"><i></i>Telefonda ilk açılış 4,8 sn; hastaların yarısı beklemeden çıkıyor.</li>
<li class="r" data-n="2"><i></i>İşletme Profilinde ana kategori “Diş hekimi”; “Diş kliniği” değil.</li>
<li class="o" data-n="3"><i></i>WhatsApp tıklaması dönüşüm olarak sayılmıyor.</li>
<li class="o" data-n="1"><i></i>İmplant sayfası yok; tedavi tek sayfada toplanmış.</li>
</ul>
<div class="an-plan" data-n="4"><b>İlk 30 gün</b><span><i>1. hafta</i>Profil kategorisi ve hizmetler</span><span><i>2. hafta</i>Dönüşüm ölçümü</span><span><i>3–4. hafta</i>İmplant ve zirkonyum sayfaları</span></div>
</div>
<ol class="an-notes r"><?php foreach (array_slice($mx_n, 2, 2) as $i => $r): ?><li data-n="<?= $i + 3 ?>"><i><?= $i + 3 ?></i><b><?= mx_h($r['baslik'] ?? null) ?></b><span><?= mx_h($r['metin'] ?? null) ?></span></li><?php endforeach; ?></ol>
</div>
<?php if (! empty($d['kucuk_not'])): ?><p class="rf-note center"><?= mx_h($d['kucuk_not']) ?></p><?php endif; ?>
</div></section>
<script>(function(){var s=document.currentScript.previousElementSibling;if(!s)return;var io=window.IntersectionObserver&&new IntersectionObserver(function(e){e.forEach(function(x){if(x.isIntersecting){s.classList.add("in");io.disconnect()}})},{threshold:.25});io?io.observe(s):s.classList.add("in");s.addEventListener("mouseover",function(e){var li=e.target.closest&&e.target.closest(".an-notes li");s.setAttribute("data-hl",li?li.getAttribute("data-n"):"")});s.addEventListener("mouseleave",function(){s.setAttribute("data-hl","")})})();</script>
