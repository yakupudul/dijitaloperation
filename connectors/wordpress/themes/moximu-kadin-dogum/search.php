<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
bk_sayfa_bandi('“' . get_search_query() . '” için arama sonuçları');
?>
<section class="bk-bolum">
	<div class="kap">
		<?php if (have_posts()) : ?>
			<ul class="bk-arama-liste">
				<?php while (have_posts()) : the_post(); ?>
					<li>
						<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
						<span class="bk-arama-tur"><?php echo esc_html(get_post_type() === 'docs' ? 'Soru ve cevap' : (get_post_type() === 'post' ? 'Blog' : 'Sayfa')); ?></span>
						<p><?php echo esc_html(wp_trim_words(get_the_excerpt(), 28, '…')); ?></p>
					</li>
				<?php endwhile; ?>
			</ul>
			<?php the_posts_pagination(['prev_text' => '‹', 'next_text' => '›', 'class' => 'bk-sayfalama']); ?>
		<?php else : ?>
			<p class="bk-bos">Aradığınız ifadeyle eşleşen bir içerik bulunamadı. Farklı bir kelimeyle yeniden deneyebilir ya da bize WhatsApp'tan sorabilirsiniz.</p>
			<?php bk_arama_formu('bk-ara bk-ara-genis'); ?>
		<?php endif; ?>
	</div>
</section>
<?php
get_footer();
