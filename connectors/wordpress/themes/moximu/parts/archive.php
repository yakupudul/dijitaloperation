<?php
/** Rehber (posts page), its categories and search: heading, topic chips, guide cards, then the analysis form. */
defined('ABSPATH') || exit;
$GLOBALS['mx_drawn'] = true;
$blog = (int) get_option('page_for_posts');
$blog_url = $blog ? get_permalink($blog) : home_url('/rehber/');
$title = $blog ? get_the_title($blog) : 'Rehber';
$lead = $blog ? (string) get_post_field('post_excerpt', $blog) : '';
if (is_category()) {
    $title = single_cat_title('', false);
    $lead = wp_strip_all_tags(category_description());
} elseif (is_search()) {
    $title = 'Arama: '.get_search_query();
    $lead = '';
}
$current = is_category() ? get_queried_object_id() : 0;
$cats = get_categories(['hide_empty' => true, 'exclude' => [(int) get_option('default_category')]]);
?>
<nav aria-label="breadcrumb" class="mx lp-bc"><div class="wrap"><a href="<?= esc_url(home_url('/')) ?>">Ana sayfa</a> › <?php if (! is_home()) : ?><a href="<?= esc_url($blog_url) ?>">Rehber</a> › <?php endif; ?><span><?= esc_html($title) ?></span></div></nav>
<section class="mx lp-art-hero mx-page-hero"><div class="wrap"><div class="lp-art-head">
<span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-loop.svg"/>Rehber</span><h1><?= esc_html($title) ?></h1>
<?php if ($lead !== '') : ?><p class="lead"><?= esc_html($lead) ?></p><?php endif; ?>
<?php if ($cats) : ?><div class="mx-chips"><a class="<?= $current ? '' : 'on' ?>" href="<?= esc_url($blog_url) ?>">Tümü</a><?php foreach ($cats as $cat) : ?><a class="<?= $current === $cat->term_id ? 'on' : '' ?>" href="<?= esc_url(get_category_link($cat)) ?>"><?= esc_html($cat->name) ?></a><?php endforeach; ?></div><?php endif; ?>
</div></div></section>
<section class="mx lp-relsec mx-page"><div class="wrap">
<?php if (have_posts()) : ?><div class="lp-rel-g mx-rel-all">
<?php while (have_posts()) : the_post(); $c = get_the_category(); ?>
<a class="lp-rel" href="<?php the_permalink(); ?>"><?php if (has_post_thumbnail()) : ?><span class="lp-rel-img"><?php the_post_thumbnail('medium_large', ['alt' => '', 'loading' => 'lazy', 'sizes' => '(max-width: 760px) 100vw, 380px']); ?></span><?php endif; ?><span class="lp-rel-k"><?= esc_html($c ? $c[0]->name : 'Rehber') ?></span><b><?php the_title(); ?></b><span><?= esc_html(wp_trim_words(get_the_excerpt(), 20)) ?></span></a>
<?php endwhile; ?>
</div>
<?php the_posts_pagination(['mid_size' => 1, 'prev_text' => '← Önceki', 'next_text' => 'Sonraki →']); ?>
<?php else : ?><p>Bu konuda henüz yazı yok.</p><?php endif; ?>
</div></section>
<?php mx_shared_section('analiz');
