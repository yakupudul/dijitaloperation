<?php defined('ABSPATH') || exit;
$cats = wp_get_post_categories(get_the_ID());
$related = get_posts(['post_type' => 'post', 'posts_per_page' => 3, 'post__not_in' => [get_the_ID()], 'category__in' => $cats ?: [0], 'ignore_sticky_posts' => true, 'no_found_rows' => true]);
if (count($related) < 3) {
    $related = array_merge($related, get_posts(['post_type' => 'post', 'posts_per_page' => 3 - count($related), 'post__not_in' => array_merge([get_the_ID()], wp_list_pluck($related, 'ID')), 'no_found_rows' => true]));
}
if ($related) : ?>
<section class="mx lp-relsec" id="ilgili"><div class="wrap"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= mx_h($d['yazi'] ?? 'İlgili rehberler') ?></span><h2><?= mx_h($d['baslik'] ?? '') ?></h2><div class="lp-rel-g">
<?php foreach ($related as $post_item) : ?><a class="lp-rel" href="<?= esc_url(get_permalink($post_item)) ?>"><?php if (has_post_thumbnail($post_item)) : ?><span class="lp-rel-img"><?= get_the_post_thumbnail($post_item, 'medium_large', ['alt' => '', 'loading' => 'lazy', 'sizes' => '(max-width: 760px) 100vw, 380px']) ?></span><?php endif; ?><span class="lp-rel-k">Rehber</span><b><?= esc_html(get_the_title($post_item)) ?></b><span><?= esc_html(wp_trim_words(get_the_excerpt($post_item), 16)) ?></span></a><?php endforeach; ?>
</div></div></section>
<?php endif;
