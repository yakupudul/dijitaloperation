<?php
/**
 * Eski eklentiler (Elementor, Elementor Pro, BetterDocs) hâlâ açıkken bile ön yüz bu temadan çizilir:
 * şablon seçimini, içerik süzgecini ve gereksiz CSS/JS'yi devralırız. Eklentiler kaldırılınca bu dosya
 * hiçbir şey yapmaz.
 */
if (! defined('ABSPATH')) {
	exit;
}

/** Ön yüzde bu temanın dosyasını kullan (Elementor Pro Tema Oluşturucu ve BetterDocs şablonlarını aş). */
add_filter('template_include', function ($template) {
	if (is_admin() || is_feed() || is_embed()) {
		return $template;
	}
	$dir = BK_DIR . '/';
	$sec = function (array $adaylar) use ($dir) {
		foreach ($adaylar as $a) {
			if (is_file($dir . $a)) {
				return $dir . $a;
			}
		}
		return null;
	};
	$hedef = null;
	if (is_front_page()) {
		$hedef = $sec(['front-page.php']);
	} elseif (is_home()) {
		$hedef = $sec(['home.php']);
	} elseif (is_404()) {
		$hedef = $sec(['404.php']);
	} elseif (is_search()) {
		$hedef = $sec(['search.php']);
	} elseif (is_singular('docs')) {
		$hedef = $sec(['single-docs.php']);
	} elseif (is_post_type_archive('docs')) {
		$hedef = $sec(['archive-docs.php']);
	} elseif (is_tax(['doc_category', 'doc_tag'])) {
		$hedef = $sec(['taxonomy-doc_category.php']);
	} elseif (is_page()) {
		$slug = get_post_field('post_name', get_queried_object_id());
		$hedef = $sec(['page-' . $slug . '.php', 'page.php']);
	} elseif (is_singular('post')) {
		$hedef = $sec(['single.php']);
	} elseif (is_archive()) {
		$hedef = $sec(['archive.php']);
	}
	return $hedef ?: $template;
}, PHP_INT_MAX);

/** Elementor içerik süzgecini bu temada kapat (sayfa içeriği WordPress düzenleyicisinden gelir). */
add_action('wp', function () {
	if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->frontend)) {
		remove_filter('the_content', [\Elementor\Plugin::$instance->frontend, 'apply_builder_in_content'], 9);
	}
	// Elementor Pro Tema Oluşturucu konumlarını boşalt.
	add_filter('elementor/theme/get_location_templates/template_id', '__return_zero', 999);
});

/** Yalnızca bu temanın ve gerçekten gereken eklentilerin dosyaları yüklensin. */
add_action('wp_enqueue_scripts', function () {
	global $wp_styles, $wp_scripts;
	$izinli_css = ['bk-site', 'admin-bar', 'dashicons', 'query-monitor'];
	$izinli_js = ['bk-site', 'admin-bar', 'query-monitor', 'wp-hooks', 'wp-i18n'];
	foreach ((array) ($wp_styles->queue ?? []) as $h) {
		if (! in_array($h, $izinli_css, true) && ! str_starts_with($h, 'ti-') && ! str_starts_with($h, 'trustindex')) {
			wp_dequeue_style($h);
		}
	}
	foreach ((array) ($wp_scripts->queue ?? []) as $h) {
		if (! in_array($h, $izinli_js, true) && ! str_starts_with($h, 'trustindex') && ! str_starts_with($h, 'ti-')) {
			wp_dequeue_script($h);
		}
	}
}, 9999);

/** Elementor'un ve BetterDocs'un head içine bastığı satır içi stil ve meta etiketleri. */
add_action('wp_head', function () {
	remove_action('wp_head', 'print_emoji_detection_script', 7);
	remove_action('wp_print_styles', 'print_emoji_styles');
	remove_action('wp_head', 'wp_generator');
	remove_action('wp_head', 'wlwmanifest_link');
	remove_action('wp_head', 'rsd_link');
	remove_action('wp_head', 'wp_shortlink_wp_head');
}, 0);
add_filter('elementor/frontend/print_google_fonts', '__return_false');
add_action('wp_print_styles', function () {
	wp_dequeue_style('elementor-frontend');
}, 100);

/**
 * GTM: Elementor Pro açıkken onun "Özel kod" kaydı basıyor; eklenti kaldırılınca tema basar.
 * Böylece geçişin hiçbir anında etiket ne kaybolur ne iki kez yüklenir.
 */
function bk_gtm_tema_basar(): bool
{
	return ! defined('ELEMENTOR_PRO_VERSION');
}
