<?php
/** Alt sayfa girişi: yol, H1, kısa cevap kutusu, etiketler, iki buton (panelden düzenlenir). */
defined('ABSPATH') || exit;
$mx_crumbs = [];
foreach (array_reverse(get_post_ancestors(get_the_ID())) as $mx_a) {
    $mx_crumbs[] = '<a href="'.esc_url(get_permalink($mx_a)).'">'.esc_html(get_the_title($mx_a)).'</a>';
}
?>
<section class="mx np-hero"><div class="wrap">
<nav class="np-crumb" aria-label="breadcrumb"><a href="<?= esc_url(home_url('/')) ?>">Ana sayfa</a> › <?= $mx_crumbs ? implode(' › ', $mx_crumbs).' › ' : '' ?><span><?= esc_html(get_the_title()) ?></span></nav>
<h1><?= mx_h(($d['baslik'] ?? null)) ?></h1>
<?php if (! empty($d['metin'])) : ?><div class="short"><?= mx_h($d['metin']) ?></div><?php endif; ?>
<?php if ($mx_l = mx_np_lines($d['etiketler'] ?? '')) : ?><div class="meta"><?php foreach ($mx_l as $mx_t) : ?><span><?= mx_h($mx_t) ?></span><?php endforeach; ?></div><?php endif; ?>
<?php if (! empty($d['buton_yazisi']) || ! empty($d['buton_2_yazisi'])) : ?><div class="cta">
<?php if (! empty($d['buton_yazisi'])) : $mx_u = (string) ($d['buton_link'] ?? ''); ?><a class="btn np-k" href="<?= mx_url($mx_u) ?>"<?= mx_np_target($mx_u) ?>><?= mx_h($d['buton_yazisi']) ?></a><?php endif; ?>
<?php if (! empty($d['buton_2_yazisi'])) : $mx_u = (string) ($d['buton_2_link'] ?? ''); ?><a class="btn np-o" href="<?= mx_url($mx_u) ?>"<?= mx_np_target($mx_u) ?>><?= mx_h($d['buton_2_yazisi']) ?></a><?php endif; ?>
</div><?php endif; ?>
</div></section>
