<?php
/** Kurucu — photo, name and a short first-person story (panelden düzenlenir). */
defined('ABSPATH') || exit;
?>
<section class="mx mx-fd" id="kurucu"><div class="wrap"><div class="mx-fd-g">
<figure class="mx-fd-ph"><?php if (! empty($d['gorsel'])) : ?><img <?= mx_img_wh(($d['gorsel'] ?? null)) ?> alt="<?= mx_img_alt(($d['gorsel'] ?? null)) ?>" decoding="async" loading="<?= mx_loading() ?>" src="<?= mx_img_src(($d['gorsel'] ?? null)) ?>"/><?php endif; ?><?php if (! empty($d['gorsel_2'])) : ?><img class="mx-fd-ph2" <?= mx_img_wh(($d['gorsel_2'] ?? null)) ?> alt="<?= mx_img_alt(($d['gorsel_2'] ?? null)) ?>" decoding="async" loading="lazy" src="<?= mx_img_src(($d['gorsel_2'] ?? null)) ?>"/><?php endif; ?><figcaption><b><?= mx_h(($d['ad'] ?? null)) ?></b><span><?= mx_h(($d['unvan'] ?? null)) ?></span></figcaption></figure>
<div class="mx-fd-t"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2>
<?php if (! empty($d['alinti'])) : ?><blockquote><?= mx_h(($d['alinti'] ?? null)) ?></blockquote><?php endif; ?>
<?php foreach (mx_rows(($d['liste'] ?? null)) as $r1) : ?><p><?= mx_h(($r1['metin'] ?? null)) ?></p><?php endforeach; ?>
<?php if (mx_rows(($d['liste_2'] ?? null))) : ?><div class="mx-fd-l"><?php foreach (mx_rows(($d['liste_2'] ?? null)) as $r1) : $u = mx_url(($r1['baglanti'] ?? null)); ?><a href="<?= $u ?>"<?= str_starts_with($u, 'http') && ! str_contains($u, (string) wp_parse_url(home_url(), PHP_URL_HOST)) ? ' rel="noopener" target="_blank"' : '' ?>><?= mx_h(($r1['yazi'] ?? null)) ?></a><?php endforeach; ?></div><?php endif; ?>
</div></div></div></section>
