<?php
/**
 * Yazı listeleri (kategori, arama, arşiv) için yedek şablon.
 */
get_header();
$np_t = is_search() ? 'Arama sonuçları' : ( is_archive() ? wp_strip_all_tags( get_the_archive_title() ) : 'Perde rehberi' );
?>
<section class="sec hl-b" style="--pt:72px;--pb:56px"><div class="wrap"><div class="col" style="--w:70%;--g:22px"><div class="t-eyebrow c-plum">Perde rehberi</div><h1 class="t-h1 c-ink"><?php echo esc_html( $np_t ); ?></h1></div></div></section>
<section class="sec" style="--pt:56px"><div class="wrap">
<?php if ( have_posts() ) : ?>
	<div class="g-rehber">
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<a class="np-card c-rehber" href="<?php the_permalink(); ?>"><div class="im"><?php the_post_thumbnail( 'medium_large' ); ?></div><div class="t-eyebrow c-plum">Perde rehberi</div><h3 class="t-h3 c-ink"><?php the_title(); ?></h3></a>
	<?php endwhile; ?>
	</div>
	<nav class="pager" aria-label="Sayfalar"><?php echo paginate_links( array( 'prev_next' => false ) ); // phpcs:ignore ?></nav>
<?php else : ?>
	<p class="t-lead c-text">Aradığınız içerik bulunamadı.</p>
<?php endif; ?>
</div></section>
<?php
echo np_final_cta(); // phpcs:ignore
get_footer();
