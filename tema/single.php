<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
the_post();
$mxp_id = get_the_ID();
$mxp_blog = (int) get_option('page_for_posts');
?>
<main>
<section class="post-hero"><div class="wrap narrow">
<nav class="crumb" aria-label="Sayfa yolu"><a href="<?php echo esc_url(home_url('/')); ?>">Ana sayfa</a><svg class="ico"><use href="#i-arrow"/></svg><a href="<?php echo esc_url($mxp_blog ? get_permalink($mxp_blog) : home_url('/blog/')); ?>">Blog</a></nav>
<h1><?php the_title(); ?></h1>
<p class="post-meta"><img src="<?php echo esc_url(MXP_URI . '/assets/img/ali-avatar.webp'); ?>" alt="" width="40" height="40"><span><b>Uzm. Klinik Psikolog Ali Akbulut</b> · <?php echo esc_html(get_the_modified_date('j F Y')); ?> · <?php echo (int) mxp_read_min($mxp_id); ?> dk okuma</span></p>
</div></section>
<div class="wrap"><figure class="post-cover"><img src="<?php echo esc_url(mxp_cover($mxp_id)); ?>" alt="<?php echo esc_attr(get_the_title()); ?>" width="960" height="660" fetchpriority="high"></figure></div>
<section class="pg-body"><div class="wrap pg-grid">
<article class="prose"><?php echo mxp_content(); // phpcs:ignore ?>
<?php mxp_part('yazar'); ?>
</article>
<aside class="pg-side"><div class="sticky"><?php mxp_part('yan-iletisim'); ?></div></aside>
</div></section>
<?php
$mxp_rel = get_posts(['post_type' => 'post', 'posts_per_page' => 3, 'post__not_in' => [$mxp_id], 'orderby' => 'date', 'order' => 'DESC']);
if ($mxp_rel) {
	echo '<section class="pg-rel"><div class="wrap"><h2>Diğer yazılar</h2><div class="bl-grid">';
	foreach ($mxp_rel as $mxp_r) {
		echo mxp_post_card((int) $mxp_r->ID, 'h3'); // phpcs:ignore
	}
	echo '</div></div></section>';
}
mxp_part('cta');
?>
</main>
<?php
get_footer();
