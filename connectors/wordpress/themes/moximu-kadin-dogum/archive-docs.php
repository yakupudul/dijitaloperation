<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
bk_sayfa_bandi('Sıkça Sorulan Sorular', '2026/09/IMG_5329-scaled.jpg', '', true);
?>
<section class="bk-bolum bk-sss">
	<div class="kap">
		<div class="bk-sss-ara"><?php get_template_part('parts/soru-ara'); ?></div>
		<div class="bk-sss-izgara">
			<?php foreach (bk_soru_kategorileri() as $k) : ?>
				<div class="bk-sss-kat">
					<?php echo bk_gorsel('2026/09/cropped-logo-1.png', 'thumbnail', ['alt' => '', 'class' => 'bk-sss-ikon', 'width' => '100', 'height' => '100']); ?>
					<h2><a href="<?php echo esc_url(get_term_link($k)); ?>"><?php echo esc_html($k->name); ?></a></h2>
					<p class="bk-sss-sayi"><?php echo esc_html((string) $k->count); ?></p>
					<ul>
						<?php foreach (bk_kategori_sorulari($k->term_id, 5) as $p) : ?>
							<li><a href="<?php echo esc_url(get_permalink($p)); ?>"><?php echo esc_html(get_the_title($p)); ?></a></li>
						<?php endforeach; ?>
					</ul>
					<a class="bk-sss-tumu" href="<?php echo esc_url(get_term_link($k)); ?>">Tüm soruları keşfedin <?php echo bk_ikon('sag'); ?></a>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php
get_footer();
