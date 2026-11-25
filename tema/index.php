<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
?>
<main>
<section class="pg-hero slim"><div class="wrap">
<nav class="crumb" aria-label="Sayfa yolu"><a href="<?php echo esc_url(home_url('/')); ?>">Ana sayfa</a><svg class="ico"><use href="#i-arrow"/></svg><span>Blog</span></nav>
<span class="eyebrow">Uzm. Klinik Psikolog Ali Akbulut yazıyor</span>
<h1>Blog</h1>
<p class="lead">Çocuk, ergen, ilişki ve kaygı üzerine yazılar.</p>
</div></section>
<section class="pg-body"><div class="wrap"><div class="bl-grid">
<?php
if (have_posts()) {
	while (have_posts()) {
		the_post();
		echo mxp_post_card((int) get_the_ID()); // phpcs:ignore
	}
} else {
	echo '<p>Henüz yazı yok.</p>';
}
?>
</div>
<?php the_posts_pagination(['prev_text' => 'Önceki', 'next_text' => 'Sonraki']); ?>
</div></section>
<?php mxp_part('cta'); ?>
</main>
<?php
get_footer();
