<?php
/** Yazı — bölüm şablonu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<section class="mx lp-art" id="icerik"><div class="wrap"><div class="lp-art-g">
<aside class="lp-toc"><b><?= mx_h(($d['kalin_yazi'] ?? null)) ?></b><nav><?= mx_h(($d['yazi'] ?? null)) ?></nav><a class="btn" href="<?= mx_url(($d['baglanti'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></aside>
<article class="lp-art-body">
<p class="lp-art-lead"><?= mx_h(($d['metin'] ?? null)) ?></p>
<h2 id="neden"><?= mx_h(($d['baslik'] ?? null)) ?></h2>
<?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><p><?= mx_h(($r1['metin'] ?? null)) ?></p><?php endforeach; ?>

<div class="lp-gbp"><div class="hd"><?= mx_h(($d['yazi_2'] ?? null)) ?></div><div class="ph"><i></i><i></i><i></i></div><div class="chips"><?= mx_h(($d['yazi_3'] ?? null)) ?></div></div><ul class="lp-chk" data-cycle=""><?php foreach (mx_rows(($d['liste_2'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ul><div class="badge art"><?= mx_h(($d['yazi_4'] ?? null)) ?></div>
<h2 id="kategori"><?= mx_h(($d['baslik_2'] ?? null)) ?></h2>
<?php foreach (mx_rows(($d['liste_3'] ?? null)) as $i1 => $r1): ?><p><?= mx_h(($r1['metin'] ?? null)) ?></p><?php endforeach; ?>

<div class="lp-callout"><?= mx_h(($d['yazi_5'] ?? null)) ?></div>
<h2 id="hizmetler"><?= mx_h(($d['baslik_3'] ?? null)) ?></h2>
<p><?= mx_h(($d['metin_2'] ?? null)) ?></p>
<ul class="lp-art-list"><?php foreach (mx_rows(($d['liste_4'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ul>
<h2 id="fotograf"><?= mx_h(($d['baslik_4'] ?? null)) ?></h2>
<p><?= mx_h(($d['metin_3'] ?? null)) ?></p>
<h2 id="yorum"><?= mx_h(($d['baslik_5'] ?? null)) ?></h2>
<p><?= mx_h(($d['metin_4'] ?? null)) ?></p>
<div class="lp-art-two"><div><b><?= mx_h(($d['kalin_yazi_2'] ?? null)) ?></b><p><?= mx_h(($d['metin_5'] ?? null)) ?></p></div><div class="bad"><b><?= mx_h(($d['kalin_yazi_3'] ?? null)) ?></b><p><?= mx_h(($d['metin_6'] ?? null)) ?></p></div></div>
<div class="lp-art-cta"><div><?= mx_h(($d['yazi_6'] ?? null)) ?></div><a class="btn" href="<?= mx_url(($d['baglanti_2'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz_2'] ?? null)) ?></a></div>
<h2 id="gonderi"><?= mx_h(($d['baslik_6'] ?? null)) ?></h2>
<p><?= mx_h(($d['metin_7'] ?? null)) ?></p>
<h2 id="olcum"><?= mx_h(($d['baslik_7'] ?? null)) ?></h2>
<p><?= mx_h(($d['metin_8'] ?? null)) ?></p>
<div class="lp-chart"><div class="h"><?= mx_h(($d['yazi_7'] ?? null)) ?></div><div class="lp-bars"><i style="--h:40%"></i><i style="--h:44%"></i><i style="--h:47%"></i><i style="--h:55%"></i><i style="--h:61%"></i><i style="--h:66%"></i><i style="--h:74%"></i><i style="--h:80%"></i><i style="--h:86%"></i><i style="--h:92%"></i><i style="--h:96%"></i><i style="--h:100%"></i></div><div class="lp-chart-n"><?= mx_h(($d['yazi_8'] ?? null)) ?></div></div><div class="badge art"><?= mx_h(($d['yazi_9'] ?? null)) ?></div>
<h2 id="hatalar"><?= mx_h(($d['baslik_8'] ?? null)) ?></h2>
<ol class="lp-art-ol"><?php foreach (mx_rows(($d['liste_5'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ol>
<p><?= mx_h(($d['metin_9'] ?? null)) ?></p>
<div class="lp-author"><img alt="" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><div><?= mx_h(($d['yazi_10'] ?? null)) ?></div></div>
</article>
</div></div></section>
