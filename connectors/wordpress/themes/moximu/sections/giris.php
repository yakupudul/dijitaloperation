<?php
/** Giriş (hero) — bölüm şablonu (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<?php $mx_v = $d['tasarim'] ?? 'v1'; ?>
<?php if ($mx_v === 'v1'): ?>
<section class="mx lp-hero svc lp-hero2" id="seo"><div class="wrap">
<div class="lp-hero-g">
<div class="lp-hero-t">
<span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span>
<h1><?= mx_h(($d['baslik'] ?? null)) ?></h1>
<p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p>
<div class="lp-cta"><a class="btn lp-wa" href="<?= esc_url(mx_wa_url()) ?>" rel="noopener" target="_blank"><?= mx_wa_icon() ?>WhatsApp'tan yazın</a><a class="btn outline" href="<?= mx_url(($d['baglanti'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></div>
<ul class="lp-proof"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ul>
</div>
<div class="lp-hero-v">
<div class="lp-stage">
<div class="lp-photo"><img <?= mx_img_wh(($d['gorsel'] ?? null)) ?> alt="<?= mx_img_alt(($d['gorsel'] ?? null)) ?>" decoding="async" loading="<?= mx_loading() ?>" src="<?= mx_img_src(($d['gorsel'] ?? null)) ?>"/></div>
<div class="lp-serp"><div class="lp-sbox"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><div class="lp-pack"><div class="lp-pack-h"><?= mx_h(($d['yazi_4'] ?? null)) ?></div><div class="rank anim"><div class="rows"><div class="r a"><?= mx_h(($d['yazi_5'] ?? null)) ?></div><div class="r b"><?= mx_h(($d['yazi_6'] ?? null)) ?></div><div class="r on"><?= mx_h(($d['yazi_7'] ?? null)) ?></div></div></div></div><div class="lp-org"><div class="u"><?= mx_h(($d['yazi_8'] ?? null)) ?></div><div class="t"><?= mx_h(($d['yazi_9'] ?? null)) ?></div><div class="d"><?= mx_h(($d['yazi_10'] ?? null)) ?></div></div><div class="lp-src"><?= mx_h(($d['yazi_11'] ?? null)) ?></div></div>
<div class="lp-pop p1"><?= mx_h(($d['yazi_12'] ?? null)) ?></div><div class="lp-pop p2"><?= mx_h(($d['yazi_13'] ?? null)) ?></div>
</div>
</div>
</div>
</div></section>
<?php elseif ($mx_v === 'v2'): ?>
<section class="mx lp-hero svc lp-hero2" id="paket"><div class="wrap">
<div class="lp-hero-g">
<div class="lp-hero-t">
<span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span>
<h1><?= mx_h(($d['baslik'] ?? null)) ?></h1>
<p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p>
<div class="lp-cta"><a class="btn lp-wa" href="<?= esc_url(mx_wa_url()) ?>" rel="noopener" target="_blank"><?= mx_wa_icon() ?>WhatsApp'tan yazın</a><a class="btn outline" href="<?= mx_url(($d['baglanti'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></div>
<ul class="lp-proof"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ul>
</div>
<div class="lp-hero-v">
<div class="lp-stage st-paket">
<div class="lp-photo"><img <?= mx_img_wh(($d['gorsel'] ?? null)) ?> alt="<?= mx_img_alt(($d['gorsel'] ?? null)) ?>" decoding="async" loading="<?= mx_loading() ?>" src="<?= mx_img_src(($d['gorsel'] ?? null)) ?>"/></div>
<div class="lp-serp"><div class="lp-4"><div><?= mx_h(($d['yazi_3'] ?? null)) ?></div><div><?= mx_h(($d['yazi_4'] ?? null)) ?></div><div><?= mx_h(($d['yazi_5'] ?? null)) ?></div><div><?= mx_h(($d['yazi_6'] ?? null)) ?></div></div><div class="lp-org"><div class="feed"><div class="notif" style="box-shadow:none;border:1px solid #e3e8ef"><?= mx_h(($d['yazi_7'] ?? null)) ?></div><div class="notif" style="box-shadow:none;border:1px solid #e3e8ef"><?= mx_h(($d['yazi_8'] ?? null)) ?></div></div></div><div class="lp-src"><?= mx_h(($d['yazi_9'] ?? null)) ?></div></div>
<div class="lp-pop p1"><?= mx_h(($d['yazi_10'] ?? null)) ?></div><div class="lp-pop p2"><?= mx_h(($d['yazi_11'] ?? null)) ?></div>
</div>
</div>
</div>
</div></section>
<?php elseif ($mx_v === 'v3'): ?>
<section class="mx lp-hero svc lp-hero2" id="gbp"><div class="wrap">
<div class="lp-hero-g">
<div class="lp-hero-t">
<span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span>
<h1><?= mx_h(($d['baslik'] ?? null)) ?></h1>
<p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p>
<div class="lp-cta"><a class="btn lp-wa" href="<?= esc_url(mx_wa_url()) ?>" rel="noopener" target="_blank"><?= mx_wa_icon() ?>WhatsApp'tan yazın</a><a class="btn outline" href="<?= mx_url(($d['baglanti'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></div>
<ul class="lp-proof"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ul>
</div>
<div class="lp-hero-v">
<div class="lp-stage">
<div class="lp-photo"><img <?= mx_img_wh(($d['gorsel'] ?? null)) ?> alt="<?= mx_img_alt(($d['gorsel'] ?? null)) ?>" decoding="async" loading="<?= mx_loading() ?>" src="<?= mx_img_src(($d['gorsel'] ?? null)) ?>"/></div>
<div class="lp-serp"><div class="lp-sbox"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><div class="lp-pack"><div class="lp-pack-h"><?= mx_h(($d['yazi_4'] ?? null)) ?></div><div class="rank anim"><div class="rows"><div class="r a"><?= mx_h(($d['yazi_5'] ?? null)) ?></div><div class="r b"><?= mx_h(($d['yazi_6'] ?? null)) ?></div><div class="r on"><?= mx_h(($d['yazi_7'] ?? null)) ?></div></div></div></div><div class="lp-org"><div class="notif" style="box-shadow:none;border:1px solid #e3e8ef"><?= mx_h(($d['yazi_8'] ?? null)) ?></div></div><div class="lp-src"><?= mx_h(($d['yazi_9'] ?? null)) ?></div></div>
<div class="lp-pop p1"><?= mx_h(($d['yazi_10'] ?? null)) ?></div><div class="lp-pop p2"><?= mx_h(($d['yazi_11'] ?? null)) ?></div>
</div>
</div>
</div>
</div></section>
<?php elseif ($mx_v === 'v4'): ?>
<section class="mx lp-hero svc lp-hero2" id="ads"><div class="wrap">
<div class="lp-hero-g">
<div class="lp-hero-t">
<span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span>
<h1><?= mx_h(($d['baslik'] ?? null)) ?></h1>
<p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p>
<div class="lp-cta"><a class="btn lp-wa" href="<?= esc_url(mx_wa_url()) ?>" rel="noopener" target="_blank"><?= mx_wa_icon() ?>WhatsApp'tan yazın</a><a class="btn outline" href="<?= mx_url(($d['baglanti'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></div>
<ul class="lp-proof"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ul>
</div>
<div class="lp-hero-v">
<div class="lp-stage st-ads">
<div class="lp-photo"><img <?= mx_img_wh(($d['gorsel'] ?? null)) ?> alt="<?= mx_img_alt(($d['gorsel'] ?? null)) ?>" decoding="async" loading="<?= mx_loading() ?>" src="<?= mx_img_src(($d['gorsel'] ?? null)) ?>"/></div>
<div class="lp-serp"><div class="lp-sbox"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><div class="lp-org ad"><div class="u"><?= mx_h(($d['yazi_4'] ?? null)) ?></div><div class="t"><?= mx_h(($d['yazi_5'] ?? null)) ?></div><div class="d"><?= mx_h(($d['yazi_6'] ?? null)) ?></div><div class="x"><?= mx_h(($d['yazi_7'] ?? null)) ?></div></div><div class="lp-org"><div class="notif" style="box-shadow:none;border:1px solid #e3e8ef"><?= mx_h(($d['yazi_8'] ?? null)) ?></div></div><div class="lp-src"><?= mx_h(($d['yazi_9'] ?? null)) ?></div></div>
<div class="lp-pop p1"><?= mx_h(($d['yazi_10'] ?? null)) ?></div><div class="lp-pop p2"><?= mx_h(($d['yazi_11'] ?? null)) ?></div>
</div>
</div>
</div>
</div></section>
<?php elseif ($mx_v === 'v5'): ?>
<section class="mx lp-hero svc lp-hero2" id="meta"><div class="wrap">
<div class="lp-hero-g">
<div class="lp-hero-t">
<span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span>
<h1><?= mx_h(($d['baslik'] ?? null)) ?></h1>
<p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p>
<div class="lp-cta"><a class="btn lp-wa" href="<?= esc_url(mx_wa_url()) ?>" rel="noopener" target="_blank"><?= mx_wa_icon() ?>WhatsApp'tan yazın</a><a class="btn outline" href="<?= mx_url(($d['baglanti'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></div>
<ul class="lp-proof"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ul>
</div>
<div class="lp-hero-v">
<div class="lp-stage st-meta">
<div class="lp-photo"><img <?= mx_img_wh(($d['gorsel'] ?? null)) ?> alt="<?= mx_img_alt(($d['gorsel'] ?? null)) ?>" decoding="async" loading="<?= mx_loading() ?>" src="<?= mx_img_src(($d['gorsel'] ?? null)) ?>"/></div>
<div class="lp-serp"><div class="lp-ig"><div class="phone2 inhero"><div class="top"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><div class="ph"><img <?= mx_img_wh(($d['gorsel_2'] ?? null)) ?> alt="<?= mx_img_alt(($d['gorsel_2'] ?? null)) ?>" decoding="async" loading="<?= mx_loading() ?>" src="<?= mx_img_src(($d['gorsel_2'] ?? null)) ?>"/></div><div class="f"><label><?= mx_h(($d['etiket'] ?? null)) ?></label><div><span class="type" data-text="Liposuction"></span></div><label><?= mx_h(($d['etiket_2'] ?? null)) ?></label><div><span class="type" data-text="0532 ··· ·· ··"></span></div><button><?= mx_h(($d['buton_yazisi'] ?? null)) ?></button></div></div><div class="wapop inhero"><i></i><div><?= mx_h(($d['yazi_4'] ?? null)) ?></div></div></div><div class="lp-src"><?= mx_h(($d['yazi_5'] ?? null)) ?></div></div>
<div class="lp-pop p1"><?= mx_h(($d['yazi_6'] ?? null)) ?></div><div class="lp-pop p2"><?= mx_h(($d['yazi_7'] ?? null)) ?></div>
</div>
</div>
</div>
</div></section>
<?php elseif ($mx_v === 'v6'): ?>
<section class="mx lp-hero svc lp-hero2" id="websitesi"><div class="wrap">
<div class="lp-hero-g">
<div class="lp-hero-t">
<span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span>
<h1><?= mx_h(($d['baslik'] ?? null)) ?></h1>
<p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p>
<div class="lp-cta"><a class="btn lp-wa" href="<?= esc_url(mx_wa_url()) ?>" rel="noopener" target="_blank"><?= mx_wa_icon() ?>WhatsApp'tan yazın</a><a class="btn outline" href="<?= mx_url(($d['baglanti'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></div>
<ul class="lp-proof"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ul>
</div>
<div class="lp-hero-v">
<div class="lp-stage st-site">
<div class="lp-photo"><img <?= mx_img_wh(($d['gorsel'] ?? null)) ?> alt="<?= mx_img_alt(($d['gorsel'] ?? null)) ?>" decoding="async" loading="<?= mx_loading() ?>" src="<?= mx_img_src(($d['gorsel'] ?? null)) ?>"/></div>
<div class="lp-serp"><div class="lp-site"><div class="browser build inhero"><div class="bar"><i></i><i></i><i></i><b class="url"><span class="type" data-text="kliniginiz.com/implant-izmir"></span></b></div><div class="pg"><div class="h"></div><div class="t"></div><div class="t s"></div><div class="row"><div class="t"></div><div class="ph"><img <?= mx_img_wh(($d['gorsel_2'] ?? null)) ?> alt="<?= mx_img_alt(($d['gorsel_2'] ?? null)) ?>" decoding="async" loading="<?= mx_loading() ?>" src="<?= mx_img_src(($d['gorsel_2'] ?? null)) ?>"/></div></div><div class="btns"><i></i><i></i></div></div></div><div class="lp-cwv"><?= mx_h(($d['yazi_3'] ?? null)) ?></div></div><div class="lp-src"><?= mx_h(($d['yazi_4'] ?? null)) ?></div></div>
<div class="lp-pop p1"><?= mx_h(($d['yazi_5'] ?? null)) ?></div><div class="lp-pop p2"><?= mx_h(($d['yazi_6'] ?? null)) ?></div>
</div>
</div>
</div>
</div></section>
<?php elseif ($mx_v === 'v7'): ?>
<section class="mx lp-hero svc lp-hero2" id="dis"><div class="wrap">
<div class="lp-hero-g">
<div class="lp-hero-t">
<span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span>
<h1><?= mx_h(($d['baslik'] ?? null)) ?></h1>
<p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p>
<div class="lp-cta"><a class="btn lp-wa" href="<?= esc_url(mx_wa_url()) ?>" rel="noopener" target="_blank"><?= mx_wa_icon() ?>WhatsApp'tan yazın</a><a class="btn outline" href="<?= mx_url(($d['baglanti'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></div>
<ul class="lp-proof"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ul>
</div>
<div class="lp-hero-v">
<div class="lp-stage">
<div class="lp-photo"><img <?= mx_img_wh(($d['gorsel'] ?? null)) ?> alt="<?= mx_img_alt(($d['gorsel'] ?? null)) ?>" decoding="async" loading="<?= mx_loading() ?>" src="<?= mx_img_src(($d['gorsel'] ?? null)) ?>"/></div>
<div class="lp-serp"><div class="lp-sbox"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><div class="lp-pack"><div class="lp-pack-h"><?= mx_h(($d['yazi_4'] ?? null)) ?></div><div class="rank anim"><div class="rows"><div class="r a"><?= mx_h(($d['yazi_5'] ?? null)) ?></div><div class="r b"><?= mx_h(($d['yazi_6'] ?? null)) ?></div><div class="r on"><?= mx_h(($d['yazi_7'] ?? null)) ?></div></div></div></div><div class="lp-org"><div class="notif" style="box-shadow:none;border:1px solid #e3e8ef"><?= mx_h(($d['yazi_8'] ?? null)) ?></div></div><div class="lp-src"><?= mx_h(($d['yazi_9'] ?? null)) ?></div></div>
<div class="lp-pop p1"><?= mx_h(($d['yazi_10'] ?? null)) ?></div><div class="lp-pop p2"><?= mx_h(($d['yazi_11'] ?? null)) ?></div>
</div>
</div>
</div>
</div></section>
<?php elseif ($mx_v === 'v8'): ?>
<section class="mx lp-hero svc lp-hero2" id="sehir"><div class="wrap">
<div class="lp-hero-g">
<div class="lp-hero-t">
<span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span>
<h1><?= mx_h(($d['baslik'] ?? null)) ?></h1>
<p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p>
<div class="lp-cta"><a class="btn lp-wa" href="<?= esc_url(mx_wa_url()) ?>" rel="noopener" target="_blank"><?= mx_wa_icon() ?>WhatsApp'tan yazın</a><a class="btn outline" href="<?= mx_url(($d['baglanti'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></div>
<ul class="lp-proof"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ul>
</div>
<div class="lp-hero-v">
<div class="lp-stage st-city">
<div class="lp-photo"><img <?= mx_img_wh(($d['gorsel'] ?? null)) ?> alt="<?= mx_img_alt(($d['gorsel'] ?? null)) ?>" decoding="async" loading="<?= mx_loading() ?>" src="<?= mx_img_src(($d['gorsel'] ?? null)) ?>"/></div>
<div class="lp-serp"><div class="lp-sbox"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><div class="lp-pack"><div class="lp-pack-h"><?= mx_h(($d['yazi_4'] ?? null)) ?></div><div class="rank anim"><div class="rows"><div class="r a"><?= mx_h(($d['yazi_5'] ?? null)) ?></div><div class="r b"><?= mx_h(($d['yazi_6'] ?? null)) ?></div><div class="r on"><?= mx_h(($d['yazi_7'] ?? null)) ?></div></div></div></div><div class="lp-org"><div class="notif" style="box-shadow:none;border:1px solid #e3e8ef"><?= mx_h(($d['yazi_8'] ?? null)) ?></div></div><div class="lp-src"><?= mx_h(($d['yazi_9'] ?? null)) ?></div></div>
<div class="lp-pop p1"><?= mx_h(($d['yazi_10'] ?? null)) ?></div><div class="lp-pop p2"><?= mx_h(($d['yazi_11'] ?? null)) ?></div>
</div>
</div>
</div>
</div></section>
<?php elseif ($mx_v === 'v9'): ?>
<section class="mx lp-hero svc lp-hero2" id="fiyat"><div class="wrap">
<div class="lp-hero-g">
<div class="lp-hero-t">
<span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span>
<h1><?= mx_h(($d['baslik'] ?? null)) ?></h1>
<p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p>
<div class="lp-cta"><a class="btn lp-wa" href="<?= esc_url(mx_wa_url()) ?>" rel="noopener" target="_blank"><?= mx_wa_icon() ?>WhatsApp'tan yazın</a><a class="btn outline" href="<?= mx_url(($d['baglanti'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></div>
<ul class="lp-proof"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ul>
</div>
<div class="lp-hero-v">
<div class="lp-stage st-price">
<div class="lp-photo"><img <?= mx_img_wh(($d['gorsel'] ?? null)) ?> alt="<?= mx_img_alt(($d['gorsel'] ?? null)) ?>" decoding="async" loading="<?= mx_loading() ?>" src="<?= mx_img_src(($d['gorsel'] ?? null)) ?>"/></div>
<div class="lp-serp"><div class="lp-quote"><div class="h"><?= mx_h(($d['yazi_3'] ?? null)) ?></div>
<?php foreach (mx_rows(($d['liste_2'] ?? null)) as $i1 => $r1): ?><div class="r"><?= mx_h(($r1['yazi'] ?? null)) ?></div><?php endforeach; ?>


<div class="r t"><?= mx_h(($d['yazi_4'] ?? null)) ?></div>
<div class="notif lp-q-n"><?= mx_h(($d['yazi_5'] ?? null)) ?></div></div><div class="lp-src"><?= mx_h(($d['yazi_6'] ?? null)) ?></div></div>
<div class="lp-pop p1"><?= mx_h(($d['yazi_7'] ?? null)) ?></div><div class="lp-pop p2"><?= mx_h(($d['yazi_8'] ?? null)) ?></div>
</div>
</div>
</div>
</div></section>
<?php elseif ($mx_v === 'v10'): ?>
<section class="mx lp-hero svc lp-hero2" id="hakkimizda"><div class="wrap">
<div class="lp-hero-g">
<div class="lp-hero-t">
<span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span>
<h1><?= mx_h(($d['baslik'] ?? null)) ?></h1>
<p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p>
<div class="lp-cta"><a class="btn lp-wa" href="<?= esc_url(mx_wa_url()) ?>" rel="noopener" target="_blank"><?= mx_wa_icon() ?>WhatsApp'tan yazın</a><a class="btn outline" href="<?= mx_url(($d['baglanti'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></div>
<ul class="lp-proof"><?php foreach (mx_rows(($d['liste'] ?? null)) as $i1 => $r1): ?><li><?= mx_h(($r1['madde'] ?? null)) ?></li><?php endforeach; ?></ul>
</div>
<div class="lp-hero-v">
<div class="lp-stage st-about">
<div class="lp-photo"><img <?= mx_img_wh(($d['gorsel'] ?? null)) ?> alt="<?= mx_img_alt(($d['gorsel'] ?? null)) ?>" decoding="async" loading="<?= mx_loading() ?>" src="<?= mx_img_src(($d['gorsel'] ?? null)) ?>"/></div>
<div class="lp-serp"><div class="lp-feedbox"><div class="lp-feed-h"><?= mx_h(($d['yazi_3'] ?? null)) ?></div><div class="feed"><div class="notif"><?= mx_h(($d['yazi_4'] ?? null)) ?></div><div class="notif"><?= mx_h(($d['yazi_5'] ?? null)) ?></div><div class="notif"><?= mx_h(($d['yazi_6'] ?? null)) ?></div><div class="notif"><?= mx_h(($d['yazi_7'] ?? null)) ?></div></div></div><div class="lp-src"><?= mx_h(($d['yazi_8'] ?? null)) ?></div></div>
<div class="lp-pop p1"><?= mx_h(($d['yazi_9'] ?? null)) ?></div><div class="lp-pop p2"><?= mx_h(($d['yazi_10'] ?? null)) ?></div>
</div>
</div>
</div>
</div></section>
<?php endif; ?>
