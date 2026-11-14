<?php
/**
 * Moximu Kadın Hastalıkları ve Doğum: sayfa oluşturucusuz, hafif özel tema.
 * Tasarım eski Elementor sitesinin birebir karşılığıdır; içerik WordPress'te,
 * iletişim bilgileri "Kurumsal Detaylar" (ACF) ekranından okunur.
 */
if (! defined('ABSPATH')) {
	exit;
}

define('BK_VER', '1.0.0');
define('BK_DIR', get_template_directory());
define('BK_URI', get_template_directory_uri());

require BK_DIR . '/inc/ayar.php';
require BK_DIR . '/inc/temizlik.php';
require BK_DIR . '/inc/sorular.php';
require BK_DIR . '/inc/soru-duzeni.php';
require BK_DIR . '/inc/parcalar.php';
require BK_DIR . '/inc/icerik.php';
require BK_DIR . '/inc/form.php';
require BK_DIR . '/inc/seo.php';
require BK_DIR . '/inc/seo-ek.php';
/* Tek seferlik bakım dosyası (varsa): iş bitince silinir. */
if (is_file(BK_DIR . '/inc/yama.php')) {
	require BK_DIR . '/inc/yama.php';
}

add_action('after_setup_theme', function () {
	add_theme_support('title-tag');
	add_theme_support('post-thumbnails');
	add_theme_support('html5', ['search-form', 'gallery', 'caption', 'style', 'script']);
	add_theme_support('custom-logo');
	register_nav_menus([
		'ust' => 'Üst menü',
		'hizmet' => 'Hizmet menüsü (ikinci satır)',
		'mobil' => 'Mobil menü bağlantıları',
	]);
});

/** Klasik düzenleyici: eski sitede Disable Gutenberg eklentisinin yaptığı iş. */
add_filter('use_block_editor_for_post', '__return_false', 100);
add_filter('use_widgets_block_editor', '__return_false');

add_action('wp_enqueue_scripts', function () {
	$css = BK_DIR . '/assets/site.css';
	$js = BK_DIR . '/assets/site.js';
	wp_enqueue_style('bk-site', BK_URI . '/assets/site.css', [], BK_VER . '.' . (file_exists($css) ? filemtime($css) : 0));
	wp_enqueue_script('bk-site', BK_URI . '/assets/site.js', [], BK_VER . '.' . (file_exists($js) ? filemtime($js) : 0), ['in_footer' => true, 'strategy' => 'defer']);
	wp_dequeue_style('wp-block-library');
	wp_dequeue_style('wp-block-library-theme');
	wp_dequeue_style('classic-theme-styles');
	wp_dequeue_style('global-styles');
}, 20);

/** Yazı tipleri: Poppins sunucudan, ilk ekranda kullanılanlar önceden yüklenir. */
add_action('wp_head', function () {
	foreach (['400', '600'] as $w) {
		printf('<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url(BK_URI . '/assets/fonts/poppins-' . $w . '-latin.woff2'));
	}
}, 1);

add_filter('excerpt_length', fn() => 18);
add_filter('excerpt_more', fn() => '…');
