<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
$mxv_konu = sanitize_title(wp_unslash($_GET['konu'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
$mxv_bag = mxv_baglanabilir();
$mxv_pid = 0;
foreach ($mxv_bag as $mxv_g) {
	foreach ($mxv_g as $mxv_id => $mxv_ad) {
		if (get_post_field('post_name', $mxv_id) === $mxv_konu) {
			$mxv_pid = (int) $mxv_id;
		}
	}
}
$mxv_q = $mxv_pid ? ['sayfa' => $mxv_pid] : [];
$mxv_kisa = mxv_liste($mxv_q + ['bicim' => 'dikey']);
$mxv_uzun = mxv_liste($mxv_q + ['bicim' => 'yatay']);
$mxv_arsiv = get_post_type_archive_link('video');
?>
<main>
<section class="pg-hero slim"><div class="wrap">
<nav class="crumb" aria-label="Sayfa yolu"><a href="<?php echo esc_url(home_url('/')); ?>">Ana sayfa</a><svg class="ico"><use href="#i-arrow"/></svg><span>Videolar</span></nav>
<span class="eyebrow">Uzm. Klinik Psikolog Ali Akbulut anlatıyor</span>
<h1><?php echo $mxv_pid ? esc_html(get_the_title($mxv_pid) . ' videoları') : 'Videolar'; ?></h1>
<p class="lead">Çocuk, ergen, ilişki, çift ve cinsel terapi üzerine kısa ve uzun videolar. Bir konu seçerek süzebilirsiniz.</p>
<div class="chips">
<a class="chip<?php echo $mxv_pid ? '' : ' on'; ?>" href="<?php echo esc_url($mxv_arsiv); ?>">Tümü</a>
<?php
foreach ($mxv_bag as $mxv_g) {
	foreach ($mxv_g as $mxv_id => $mxv_ad) {
		if (! mxv_liste(['sayfa' => $mxv_id], 1)) {
			continue;
		}
		printf('<a class="chip%s" href="%s">%s</a>', $mxv_id === $mxv_pid ? ' on' : '', esc_url(add_query_arg('konu', get_post_field('post_name', $mxv_id), $mxv_arsiv)), esc_html($mxv_ad));
	}
}
?>
</div>
</div></section>
<?php if ($mxv_kisa) : ?>
<section class="v-sec"><div class="wrap"><h2>Kısa videolar</h2><div class="v-grid"><?php echo mxv_kartlar($mxv_kisa); // phpcs:ignore ?></div></div></section>
<?php endif; ?>
<?php if ($mxv_uzun) : ?>
<section class="v-sec"><div class="wrap"><h2>Uzun videolar</h2><div class="v-grid wide"><?php echo mxv_kartlar($mxv_uzun); // phpcs:ignore ?></div></div></section>
<?php endif; ?>
<?php if ($mxv_pid) : ?>
<section class="pg-rel vd-rel"><div class="wrap"><h2>Konunun sayfası</h2><div class="cards4 flat"><?php echo mxv_hizmet_karti($mxv_pid); // phpcs:ignore ?></div></div></section>
<?php endif; ?>
<?php mxp_part('cta'); ?>
</main>
<?php
get_footer();
