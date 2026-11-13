<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
$terim = get_queried_object();
$sorular = $terim && $terim->taxonomy === 'doc_category'
	? get_posts(['post_type' => 'docs', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC', 'tax_query' => [['taxonomy' => 'doc_category', 'field' => 'term_id', 'terms' => $terim->term_id, 'include_children' => false]]])
	: $GLOBALS['wp_query']->posts;
?>
<div class="kap bk-sorukat-duzen">
	<section class="bk-sorukat bk-golge">
		<header class="bk-sorukat-ust">
			<?php echo bk_gorsel('2026/09/cropped-logo-1.png', 'thumbnail', ['alt' => '', 'class' => 'bk-sorukat-ikon', 'width' => '56', 'height' => '56']); ?>
			<h1><?php single_term_title(); ?></h1>
			<p><?php echo esc_html(count($sorular) . ' Sorular ve Cevaplar'); ?></p>
		</header>
		<?php if (! empty($terim->description)) : ?>
			<div class="bk-sorukat-aciklama"><?php echo wp_kses_post(wpautop($terim->description)); ?></div>
		<?php endif; ?>
		<ul class="bk-soru-liste">
			<?php foreach ($sorular as $p) : ?>
				<li><a href="<?php echo esc_url(get_permalink($p)); ?>"><?php echo esc_html(get_the_title($p)); ?></a></li>
			<?php endforeach; ?>
		</ul>
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
