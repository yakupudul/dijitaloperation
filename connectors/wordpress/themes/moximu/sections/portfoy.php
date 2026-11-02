<?php
/**
 * Web sitesi portföyü — live client sites in a browser and phone frame. The desktop shot scrolls on hover.
 * The same rows feed [mx_portfoy] on the examples page, so the list is kept in one place.
 */
defined('ABSPATH') || exit;
$mx_items = mx_rows(($d['liste'] ?? null));
$mx_max = (int) ($d['adet'] ?? 0);
if ($mx_max > 0) {
    $mx_items = array_slice($mx_items, 0, $mx_max);
}
?>
<section class="mx mx-pf" id="ornekler"><div class="wrap">
<?php if (! empty($d['baslik'])) : ?><div class="mx-pf-head"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h(($d['yazi'] ?? null)) ?></span><h2><?= mx_h(($d['baslik'] ?? null)) ?></h2><?php if (! empty($d['metin'])) : ?><p class="lead"><?= mx_h(($d['metin'] ?? null)) ?></p><?php endif; ?></div><?php endif; ?>
<?= mx_portfolio_grid($mx_items) ?>
<?php if (! empty($d['baglanti_yaz'])) : ?><div class="mx-pf-more"><a class="btn" href="<?= mx_url(($d['baglanti'] ?? null)) ?>"><?= mx_h(($d['baglanti_yaz'] ?? null)) ?></a></div><?php endif; ?>
</div></section>
