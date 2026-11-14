<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
$terim = get_queried_object();
$duzen = ($terim instanceof WP_Term && function_exists('bk_kategori_duzeni'))
	? bk_kategori_duzeni($terim->term_id)
	: ['ayri' => $GLOBALS['wp_query']->posts, 'konular' => [], 'toplam' => count($GLOBALS['wp_query']->posts)];
$ayri = $duzen['ayri'];
$konular = $duzen['konular'];
?>
<div class="kap bk-sorukat-duzen">
	<section class="bk-sorukat bk-golge">
		<header class="bk-sorukat-ust">
			<?php echo bk_gorsel('2026/09/cropped-logo-1.png', 'thumbnail', ['alt' => '', 'class' => 'bk-sorukat-ikon', 'width' => '56', 'height' => '56']); ?>
			<h1><?php single_term_title(); ?></h1>
			<p><?php echo esc_html($duzen['toplam'] . ' Sorular ve Cevaplar'); ?></p>
		</header>
		<?php if (! empty($terim->description)) : ?>
			<div class="bk-sorukat-aciklama"><?php echo wp_kses_post(wpautop($terim->description)); ?></div>
		<?php endif; ?>
		<?php if ($konular) : ?>
			<nav aria-label="Bu sayfadaki konular">
				<ul class="bk-konu-dizin">
					<?php if ($ayri) : ?>
						<li><a href="#en-cok-aranan">En çok aranan sorular <span><?php echo esc_html((string) count($ayri)); ?></span></a></li>
					<?php endif; ?>
					<?php foreach ($konular as $i => [$ad, $liste]) : ?>
						<li><a href="#konu-<?php echo esc_attr((string) ($i + 1)); ?>"><?php echo esc_html($ad); ?> <span><?php echo esc_html((string) count($liste)); ?></span></a></li>
					<?php endforeach; ?>
				</ul>
			</nav>
		<?php endif; ?>
		<?php if ($ayri) : ?>
			<?php if ($konular) : ?>
				<h2 class="bk-sorukat-alt bk-konu" id="en-cok-aranan">En çok aranan sorular</h2>
			<?php endif; ?>
			<ul class="bk-soru-liste">
				<?php foreach ($ayri as $p) : ?>
					<li><a href="<?php echo esc_url(get_permalink($p)); ?>"><?php echo esc_html(get_the_title($p)); ?></a></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php foreach ($konular as $i => [$ad, $liste]) : ?>
			<section class="bk-konu" id="konu-<?php echo esc_attr((string) ($i + 1)); ?>">
				<h2 class="bk-sorukat-alt"><?php echo esc_html($ad); ?></h2>
				<?php foreach ($liste as $p) : ?>
					<div class="bk-sc" id="soru-<?php echo esc_attr((string) $p->ID); ?>">
						<h3><?php echo esc_html(get_the_title($p)); ?></h3>
						<?php echo wp_kses_post(wpautop($p->post_content)); ?>
					</div>
				<?php endforeach; ?>
			</section>
		<?php endforeach; ?>
	</section>
	<aside class="bk-yan bk-golge bk-sorukat-yan">
		<?php bk_kirinti(); ?>
		<?php get_template_part('parts/soru-ara'); ?>
		<?php bk_hekim_karti('soru'); ?>
		<?php bk_iletisim_kutusu('Prof. Dr. Burcu Kısa - Kadın Hastalıkları Ve Doğum Uzmanı Ankara', false); ?>
	</aside>
</div>
<?php
get_footer();
