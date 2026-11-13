<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
the_post();
$icerik = bk_icerik_hazirla(get_the_ID());
?>
<div class="kap bk-yazi-duzen">
	<article class="bk-yazi">
		<?php bk_kirinti(); ?>
		<h1><?php the_title(); ?></h1>
		<?php if (has_post_thumbnail()) : ?>
			<div class="bk-yazi-gorsel"><?php the_post_thumbnail('large', ['loading' => 'eager', 'fetchpriority' => 'high', 'sizes' => '(max-width: 767px) 100vw, 826px']); ?></div>
		<?php endif; ?>
		<p class="bk-yazi-meta">
			<span><?php echo bk_ikon('takvim'); ?> <time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date('F j, Y')); ?></time></span>
			<span><?php echo bk_ikon('kisi'); ?> <a href="<?php echo esc_url(bk_url('/hakkimda/')); ?>" rel="author"><?php echo esc_html(bk_ayar('hekim')); ?></a></span>
		</p>
		<?php bk_icindekiler($icerik['toc']); ?>
		<div class="bk-icerik"><?php echo $icerik['html']; ?></div>
	</article>
	<aside class="bk-yan">
		<div class="bk-kutu bk-son-yazilar">
			<p class="bk-son-baslik">Son Blog Yazıları</p>
			<ul>
				<?php foreach (get_posts(['numberposts' => 6, 'post__not_in' => [get_the_ID()]]) as $p) : ?>
					<li><a href="<?php echo esc_url(get_permalink($p)); ?>"><?php echo esc_html(get_the_title($p)); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php bk_iletisim_kutusu("Ankara'da bir kadın hastalıkları ve doğum uzmanı", true, false); ?>
	</aside>
</div>
<?php
get_footer();
