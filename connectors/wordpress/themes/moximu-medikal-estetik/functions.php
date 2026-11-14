<?php
/**
 * Moximu - Medikal Estetik: Dr. Serap Korkmaz randevu sayfaları.
 * Sayfa gövdeleri parts/<ad>.html dosyalarındadır (src/tema.py üretir); bu dosya yalnız adresleri doldurur,
 * başlık, açıklama ve yapılandırılmış veriyi yazar, gereksiz WordPress çıktısını kapatır.
 */
if (! defined('ABSPATH')) {
	exit;
}

define('MME_VER', '1.0.0');
define('MME_DIR', get_template_directory());
define('MME_URI', get_template_directory_uri());

add_action('after_setup_theme', function () {
	add_theme_support('title-tag');
	add_theme_support('html5', ['style', 'script']);
});

/** Sayfa bilgileri: kısa adres => başlık, açıklama, gövde dosyası, yapılandırılmış veri. */
function mme_pages(): array
{
	static $pages = null;
	if ($pages === null) {
		$pages = require MME_DIR . '/inc/sayfalar.php';
	}
	return $pages;
}

/** Şu an gösterilen sayfanın bilgisi; tasarımı olmayan sayfada null. */
function mme_current(): ?array
{
	$pages = mme_pages();
	if (is_front_page()) {
		return $pages[''] ?? null;
	}
	if (is_page()) {
		$slug = get_post_field('post_name', get_queried_object_id());
		return $pages[$slug] ?? null;
	}
	return null;
}

function mme_fill(string $text, bool $json = false): string
{
	$home = untrailingslashit(home_url());
	$theme = MME_URI . '/assets';
	if ($json) {
		$home = trim(wp_json_encode($home), '"');
		$theme = trim(wp_json_encode($theme), '"');
	}
	return strtr($text, ['{{H}}' => $home, '{{T}}' => $theme]);
}

/** Sayfa gövdesi: prototipte derlenen HTML, adresler bu sitenin adresleriyle. */
function mme_render(array $page): void
{
	$file = MME_DIR . '/parts/' . $page['part'] . '.html';
	if (is_file($file)) {
		echo mme_fill((string) file_get_contents($file)); // phpcs:ignore WordPress.Security.EscapeOutput -- temanın kendi dosyası
	}
}

add_action('wp_enqueue_scripts', function () {
	wp_enqueue_style('mme-site', MME_URI . '/assets/site.css', [], MME_VER . '.' . filemtime(MME_DIR . '/assets/site.css'));
	wp_enqueue_script('mme-site', MME_URI . '/assets/site.js', [], MME_VER . '.' . filemtime(MME_DIR . '/assets/site.js'), ['in_footer' => true, 'strategy' => 'defer']);
	foreach (['wp-block-library', 'wp-block-library-theme', 'classic-theme-styles', 'global-styles'] as $h) {
		wp_dequeue_style($h);
	}
}, 20);

add_action('wp_head', function () {
	printf('<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url(MME_URI . '/assets/fonts/inter-latin.woff2'));
	echo '<meta name="theme-color" content="#ffffff">' . "\n";
	$page = mme_current();
	if (! $page) {
		return;
	}
	$title = $page['title'];
	$desc = $page['desc'];
	printf('<meta name="description" content="%s">' . "\n", esc_attr($desc));
	printf('<meta property="og:title" content="%s">' . "\n", esc_attr($title));
	printf('<meta property="og:description" content="%s">' . "\n", esc_attr($desc));
	echo '<meta property="og:type" content="website">' . "\n" . '<meta property="og:locale" content="tr_TR">' . "\n";
	printf('<meta property="og:url" content="%s">' . "\n", esc_url(is_front_page() ? home_url('/') : get_permalink()));
	printf('<meta property="og:image" content="%s">' . "\n", esc_url(MME_URI . '/assets/img/hekim.webp'));
	echo '<script type="application/ld+json">' . mme_fill($page['schema'], true) . '</script>' . "\n"; // phpcs:ignore
}, 2);

add_filter('pre_get_document_title', function ($title) {
	$page = mme_current();
	return $page ? $page['title'] : $title;
}, 20);

/* Gereksiz çıktılar: emoji betiği, oEmbed, RSD, kısa bağlantı, yorum akışları. */
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'wp_shortlink_wp_head');
remove_action('wp_head', 'wp_oembed_add_discovery_links');
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'feed_links_extra', 3);
add_filter('comments_open', '__return_false');
add_filter('pings_open', '__return_false');
add_filter('wp_img_tag_add_auto_sizes', '__return_false');

/* Tek seferlik bakım dosyası (varsa): iş bitince silinir. */
if (is_file(MME_DIR . '/inc/yama.php')) {
	require MME_DIR . '/inc/yama.php';
}
