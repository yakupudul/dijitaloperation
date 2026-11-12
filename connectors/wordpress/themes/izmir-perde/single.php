<?php
/**
 * Rehber yazısı.
 */
get_header();
while ( have_posts() ) :
	the_post();
	$np_id = get_the_ID();
	?>
<section class="sec" style="--pt:28px;--pb:24px;--g:24px"><div class="wrap">
	<?php echo np_crumb( 'Perde rehberi', '/rehber/', get_the_title() ); // phpcs:ignore ?>
	<div class="col" style="--g:14px;max-width:900px"><h1 class="t-h1 c-ink"><?php the_title(); ?></h1><div class="t-small c-text">Güncelleme: <time datetime="<?php echo esc_attr( get_the_modified_date( 'c' ) ); ?>"><?php echo esc_html( get_the_modified_date() ); ?></time></div></div>
</div></section>
<section class="sec" style="--pt:16px;--pb:88px"><div class="wrap"><div class="post-row">
	<article class="l post">
		<?php the_post_thumbnail( 'full', array( 'loading' => 'eager', 'fetchpriority' => 'high', 'sizes' => '(max-width:1024px) 100vw, 845px' ) ); ?>
		<div class="prose"><?php the_content(); ?></div>
	</article>
	<aside class="post-side">
		<div class="t-eyebrow c-plum">Perdeniz için</div>
		<h3 class="t-h3 c-ink">Fotoğraf gönderin, ön fiyat verelim</h3>
		<p class="t-small c-text">Pencerenizin fotoğrafını ve yaklaşık ölçüsünü WhatsApp'tan gönderin. Hangi perdenin uygun olduğunu ve ön fiyatı yazalım.</p>
		<?php echo np_wa( "WhatsApp'tan yazın", 'wa', 'btn-full' ) . np_call( 'line', 'btn-full' ); // phpcs:ignore ?>
	</aside>
</div></div></section>
<section class="sec bg-mist"><div class="wrap"><?php echo np_head( 'Perde rehberi', 'Diğer rehber yazıları' ) . np_rehber_grid( 3, $np_id ); // phpcs:ignore ?></div></section>
<section class="sec"><div class="wrap"><?php echo np_head( 'Perde modelleri', 'Ölçüye özel perde modelleri' ) . np_perde_grid( array( 'n' => 4, 'cols' => 4, 'fixed' => true ) ); // phpcs:ignore ?></div></section>
	<?php
	echo np_final_cta(); // phpcs:ignore
endwhile;
get_footer();
