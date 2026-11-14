<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
the_post();
bk_sayfa_bandi(get_the_title(), '2026/09/Gebelik-Takibine-Ne-Zaman-Baslanmali.jpeg', 'koyu-altin');
?>
<section class="bk-bolum bk-iletisim-sayfa">
	<div class="kap">
		<?php get_template_part('parts/iletisim-kartlari'); ?>
		<div class="bk-form-duzen">
			<div class="bk-form-kutu">
				<h2 class="bk-h2">Bizimle İletişime Geçin</h2>
				<?php bk_form('iletisim'); ?>
			</div>
			<div class="bk-form-gorsel"><?php echo bk_gorsel('2026/09/IMG_5329-scaled.jpg', 'large', ['alt' => 'Prof. Dr. Burcu Kısa muayenehanesinin girişi, Maidan İş Merkezi', 'sizes' => '(max-width: 767px) 100vw, 590px']); ?></div>
		</div>
	</div>
</section>
<?php
get_footer();
