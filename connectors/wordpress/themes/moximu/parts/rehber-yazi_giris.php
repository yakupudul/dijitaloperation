<?php defined('ABSPATH') || exit;
$cats = get_the_category();
$words = str_word_count(wp_strip_all_tags((string) get_post_field('post_content', get_the_ID())));
$minutes = max(1, (int) round($words / 200));
$eyebrow = 'Rehber'.($cats ? ' · '.$cats[0]->name : ''); ?>
<section class="mx lp-art-hero" id="yazi"><div class="wrap">
<div class="lp-art-head"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-loop.svg"/><?= esc_html($eyebrow) ?></span><h1><?php the_title(); ?></h1>
<?php if (has_excerpt()) : ?><p class="lead"><?= esc_html(get_the_excerpt()) ?></p><?php endif; ?>
<div class="lp-art-meta"><span><img alt="" src="<?= MX_URI ?>/assets/i/mark-loop.svg"/><?= mx_h($d['yazi_2'] ?? 'Moximu') ?></span><span><?= esc_html(get_the_date('j F Y')) ?></span><span><?= (int) $minutes ?> dk okuma</span></div></div>
</div></section>
