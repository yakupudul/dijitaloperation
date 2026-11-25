<?php
/**
 * Moximu - Psikoloji: Uzm. Klinik Psikolog Ali Akbulut.
 * Ana sayfa parts/home.html, iç sayfalar parts/<slug>.html kalıbı + WordPress içeriği ({{CONTENT}}).
 * SEO başlığı ve açıklaması SEO eklentisinden gelir; tema yalnız yapılandırılmış veriyi ve paylaşım etiketlerini yazar.
 */
if (! defined('ABSPATH')) {
	exit;
}

define('MXP_VER', '1.5.0');
define('MXP_DIR', get_template_directory());
define('MXP_URI', get_template_directory_uri());

require MXP_DIR . '/inc/ayarlar.php';
require MXP_DIR . '/inc/menu.php';
require MXP_DIR . '/inc/video.php';

add_action('after_setup_theme', function () {
	add_theme_support('title-tag');
	add_theme_support('html5', ['style', 'script', 'search-form', 'gallery', 'caption']);
	add_theme_support('post-thumbnails');
});

function mxp_pages(): array
{
	static $pages = null;
	if ($pages === null) {
		$pages = require MXP_DIR . '/inc/sayfalar.php';
	}
	return $pages;
}

function mxp_current(): ?array
{
	$pages = mxp_pages();
	if (is_front_page()) {
		return $pages[''] ?? null;
	}
	if (is_page()) {
		$slug = (string) get_post_field('post_name', get_queried_object_id());
		return $pages[$slug] ?? null;
	}
	return null;
}

function mxp_fill(string $text, bool $json = false): string
{
	$home = untrailingslashit(home_url());
	$theme = MXP_URI . '/assets';
	if ($json) {
		$home = trim(wp_json_encode($home), '"');
		$theme = trim(wp_json_encode($theme), '"');
	}
	$text = strtr($text, ['{{H}}' => $home, '{{T}}' => $theme]);
	/* Site bilgileri ({{TEL}} ...), sayfa alanları ({{P_H1}} ...) ve WordPress'ten gelen bölümler ({{V:stories}} ...). */
	$tok = mxp_tokenler();
	$text = (string) preg_replace_callback('/\{\{([A-Z0-9_]+)(?::([a-z0-9_-]+))?\}\}/', static function (array $m) use ($tok, $json): string {
		$k = $m[1];
		$arg = $m[2] ?? '';
		if ($arg === '' && (isset($tok[$k]) || $k === 'P_H1' || $k === 'P_LEAD')) {
			$v = isset($tok[$k]) ? (string) $tok[$k] : (string) mxp_sayfa_alan($k === 'P_H1' ? 'h1' : 'lead');
			return $json ? substr((string) wp_json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1) : esc_html($v);
		}
		if ($json) {
			return $m[0];
		}
		$h = mxp_slot($k, $arg);
		return $h ?? $m[0];
	}, $text);
	/* Dikey video kapağı yoksa aynı videonun 3:4 karesini kullan. */
	return (string) preg_replace_callback('#/img/v_([A-Za-z0-9_-]+)\.webp#', static function (array $m): string {
		return is_file(MXP_DIR . '/assets/img/v_' . $m[1] . '.webp') ? $m[0] : '/img/s_' . $m[1] . '.webp';
	}, $text);
}

/** Temadaki {{...}} bölümleri; bilinmeyen işaret null döner (ör. {{CONTENT}}). */
function mxp_slot(string $k, string $arg): ?string
{
	switch ($k) {
		case 'NAV':
			return mxp_slot_nav() ?? mxp_yedek('nav-yedek');
		case 'FM':
			return mxp_slot_fm($arg) ?? mxp_yedek('fm-' . $arg);
		case 'V':
			return mxp_slot_v($arg);
		case 'BUB':
			return mxp_slot_bub();
		case 'P_FAQ':
			return mxp_slot_faq();
		case 'P_FAQSEC':
			return mxp_slot_faqsec();
		case 'NUMS':
			return mxp_slot_nums();
		case 'EDU':
			return mxp_slot_edu();
		case 'EDU_KISA':
			return mxp_slot_edu_kisa();
		case 'PRN':
			return mxp_slot_prn();
		case 'BLOG6':
			return mxp_slot_blog();
		case 'WAM':
			return mxp_slot_wam($arg);
		case 'SAAT_C':
			return mxp_slot_saat_c();
		case 'SAAT_F':
			return mxp_slot_saat_f();
	}
	return null;
}

function mxp_yedek(string $name): string
{
	$file = MXP_DIR . '/parts/' . $name . '.html';
	return is_file($file) ? mxp_fill((string) file_get_contents($file)) : '';
}

function mxp_part(string $name, array $vars = []): void
{
	$file = MXP_DIR . '/parts/' . $name . '.html';
	if (! is_file($file)) {
		return;
	}
	$html = mxp_fill((string) file_get_contents($file));
	if ($vars) {
		$html = strtr($html, $vars);
	}
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- temanın kendi dosyası
}

/** Geçerli yazının içeriği, filtrelerden geçmiş. */
function mxp_content(): string
{
	$post = get_post();
	if (! $post) {
		return '';
	}
	return (string) apply_filters('the_content', $post->post_content);
}

add_action('wp_enqueue_scripts', function () {
	wp_enqueue_style('mxp-site', MXP_URI . '/assets/site.css', [], MXP_VER . '.' . filemtime(MXP_DIR . '/assets/site.css'));
	wp_enqueue_script('mxp-site', MXP_URI . '/assets/site.js', [], MXP_VER . '.' . filemtime(MXP_DIR . '/assets/site.js'), ['in_footer' => true, 'strategy' => 'defer']);
	foreach (['wp-block-library', 'wp-block-library-theme', 'classic-theme-styles', 'global-styles'] as $h) {
		wp_dequeue_style($h);
	}
}, 20);

add_action('wp_head', function () {
	echo '<meta name="theme-color" content="#0a1c3a">' . "\n";
	printf('<link rel="icon" href="%s" type="image/svg+xml">' . "\n", esc_url(MXP_URI . '/assets/img/favicon.svg'));
	if (is_singular('video')) {
		mxv_head();
		return;
	}
	if (is_post_type_archive('video')) {
		mxv_arsiv_head();
		return;
	}
	$page = mxp_current();
	$url = is_front_page() ? home_url('/') : (is_singular() ? get_permalink() : '');
	echo '<meta property="og:locale" content="tr_TR">' . "\n" . '<meta property="og:type" content="website">' . "\n";
	printf('<meta property="og:site_name" content="%s">' . "\n", esc_attr('Uzm. Klinik Psikolog Ali Akbulut'));
	printf('<meta property="og:title" content="%s">' . "\n", esc_attr(wp_get_document_title()));
	if ($url) {
		printf('<meta property="og:url" content="%s">' . "\n", esc_url($url));
	}
	$img = is_singular('post') ? mxp_cover(get_queried_object_id()) : MXP_URI . '/assets/img/' . ($page['image'] ?? 'hero.webp');
	printf('<meta property="og:image" content="%s">' . "\n", esc_url($img));
	if ($page && $page['schema'] !== '') {
		echo '<script type="application/ld+json">' . mxp_fill($page['schema'], true) . '</script>' . "\n"; // phpcs:ignore
	}
	$saat = (is_front_page() || mxp_sayfa_anahtar() === 'iletisim') ? mxp_saat_ld() : [];
	if ($saat) {
		$ld = ['@context' => 'https://schema.org', '@type' => 'LocalBusiness', '@id' => untrailingslashit(home_url()) . '/#isletme', 'name' => 'Uzm. Klinik Psikolog Ali Akbulut', 'openingHoursSpecification' => $saat];
		echo '<script type="application/ld+json">' . wp_json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n"; // phpcs:ignore
	}
	$faq = $page ? mxp_sayfa_alan('faq') : [];
	if ($faq) {
		$ld = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(static fn ($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faq)];
		echo '<script type="application/ld+json">' . wp_json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n"; // phpcs:ignore
	}
	if (is_singular('post')) {
		$id = get_queried_object_id();
		$home = untrailingslashit(home_url());
		$ld = [
			'@context' => 'https://schema.org',
			'@type' => 'BlogPosting',
			'headline' => get_the_title($id),
			'image' => $img,
			'datePublished' => get_the_date('c', $id),
			'dateModified' => get_the_modified_date('c', $id),
			'mainEntityOfPage' => get_permalink($id),
			'inLanguage' => 'tr',
			'author' => ['@type' => 'Person', '@id' => $home . '/#ali-akbulut', 'name' => 'Ali Akbulut', 'jobTitle' => 'Uzman Klinik Psikolog', 'url' => $home . '/ali-akbulut/'],
			'publisher' => ['@id' => $home . '/#isletme'],
		];
		echo '<script type="application/ld+json">' . wp_json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n"; // phpcs:ignore
	}
}, 2);

/** Yazının kapak görseli: assets/img/st_<kısa ad>.webp, yoksa öne çıkan görsel, o da yoksa genel görsel. */
function mxp_cover(int $id): string
{
	$slug = (string) get_post_field('post_name', $id);
	if ($slug !== '' && is_file(MXP_DIR . '/assets/img/st_' . $slug . '.webp')) {
		return MXP_URI . '/assets/img/st_' . $slug . '.webp';
	}
	$thumb = get_the_post_thumbnail_url($id, 'large');
	return $thumb ? (string) $thumb : MXP_URI . '/assets/img/hero.webp';
}

/** Okuma süresi (dakika). */
function mxp_read_min(int $id): int
{
	$words = preg_split('/\s+/u', trim(wp_strip_all_tags((string) get_post_field('post_content', $id))), -1, PREG_SPLIT_NO_EMPTY);
	return max(1, (int) ceil(count((array) $words) / 200));
}

/** Blog kartı. */
function mxp_post_card(int $id, string $tag = 'h2'): string
{
	return sprintf(
		'<a class="bl-card rv" href="%1$s"><div class="pic"><img src="%2$s" alt="" loading="lazy" width="960" height="660"></div><span class="mt">%3$s · %4$d dk okuma</span><%6$s>%5$s</%6$s><p>%7$s</p></a>',
		esc_url(get_permalink($id)),
		esc_url(mxp_cover($id)),
		esc_html(get_the_date('j F Y', $id)),
		mxp_read_min($id),
		esc_html(get_the_title($id)),
		$tag,
		esc_html(wp_trim_words(wp_strip_all_tags((string) get_post_field('post_content', $id)), 20))
	);
}

/* Gereksiz çıktılar. */
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'wp_shortlink_wp_head');
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'feed_links_extra', 3);
add_filter('comments_open', '__return_false');
add_filter('pings_open', '__return_false');
add_filter('wp_img_tag_add_auto_sizes', '__return_false');
