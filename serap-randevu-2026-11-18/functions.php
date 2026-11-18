<?php
/**
 * Moximu - Medikal Estetik: Dr. Serap Korkmaz randevu sayfaları.
 * Sayfa gövdeleri parts/<ad>.html dosyalarındadır (src/tema.py üretir); bu dosya yalnız adresleri doldurur,
 * başlık, açıklama ve yapılandırılmış veriyi yazar, gereksiz WordPress çıktısını kapatır.
 */
if (! defined('ABSPATH')) {
	exit;
}

define('MME_VER', '1.2.0');
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
		$html = mme_fill((string) file_get_contents($file));
		$html = str_replace('Göndermeden önce değiştirebilirsiniz.</p>', 'Göndermeden önce değiştirebilirsiniz. Mesajlar çalışma saatlerinde, Pazartesi–Cumartesi 10.00–18.00 arasında yanıtlanır.</p>', $html);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- temanın kendi dosyası
	}
}

add_action('wp_enqueue_scripts', function () {
	wp_enqueue_style('mme-site', MME_URI . '/assets/site.css', [], MME_VER . '.' . filemtime(MME_DIR . '/assets/site.css'));
	wp_enqueue_script('mme-site', MME_URI . '/assets/site.js', [], MME_VER . '.' . filemtime(MME_DIR . '/assets/site.js'), ['in_footer' => true, 'strategy' => 'defer']);
	foreach (['wp-block-library', 'wp-block-library-theme', 'classic-theme-styles', 'global-styles'] as $h) {
		wp_dequeue_style($h);
	}
}, 20);

function mme_gtm_id(): string
{
	$id = (string) get_option('mme_gtm_id', '');
	return preg_match('/^GTM-[A-Z0-9]{4,12}$/', $id) ? $id : '';
}

/* Ölçüm: Google Tag Manager kapsayıcısı (seçenek mme_gtm_id). Onay kipi: ziyaretçi şeritte "Reddet" demişse
   çerezler kapalı kalır. Kimlik yoksa hiçbir şey yüklenmez, şerit de çıkmaz. Olaylarda sağlık bilgisi yoktur. */
add_action('wp_head', function () {
	$id = mme_gtm_id();
	if ($id === '') {
		return;
	}
	echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}"
		. "(function(){var v=null;try{v=localStorage.getItem('mme_cerez')}catch(e){}var g=v==='0'?'denied':'granted';"
		. "gtag('consent','default',{ad_storage:g,ad_user_data:g,ad_personalization:g,analytics_storage:g});"
		. "gtag('set','url_passthrough',true);gtag('set','ads_data_redaction',g==='denied');})();</script>\n";
	echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});"
		. "var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;"
		. "j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);"
		. "})(window,document,'script','dataLayer'," . wp_json_encode($id) . ");</script>\n";
}, 1);

add_action('wp_body_open', function () {
	$id = mme_gtm_id();
	if ($id !== '') {
		echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . esc_attr($id) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
	}
}, 1);

add_action('wp_head', function () {
	foreach (['poppins-latin-400', 'poppins-latin-ext-400', 'poppins-latin-600'] as $f) {
		printf('<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url(MME_URI . '/assets/fonts/' . $f . '.woff2'));
	}
	$icon = esc_url(MME_URI . '/assets/img/icon-192.png');
	echo '<link rel="icon" href="' . $icon . '" type="image/png" sizes="192x192">' . "\n" . '<link rel="apple-touch-icon" href="' . $icon . '">' . "\n";
	echo '<meta name="theme-color" content="#ffffff">' . "\n";
	$page = mme_current();
	if (! $page) {
		return;
	}
	$title = $page['title'];
	$desc = $page['desc'];
	$image = esc_url(MME_URI . '/assets/img/hekim.webp');
	printf('<meta name="description" content="%s">' . "\n", esc_attr($desc));
	printf('<meta property="og:title" content="%s">' . "\n", esc_attr($title));
	printf('<meta property="og:description" content="%s">' . "\n", esc_attr($desc));
	echo '<meta property="og:type" content="website">' . "\n" . '<meta property="og:locale" content="tr_TR">' . "\n" . '<meta property="og:site_name" content="Dr. Serap Korkmaz">' . "\n";
	printf('<meta property="og:url" content="%s">' . "\n", esc_url(is_front_page() ? home_url('/') : get_permalink()));
	echo '<meta property="og:image" content="' . $image . '">' . "\n" . '<meta name="twitter:card" content="summary_large_image">' . "\n";
	echo '<script type="application/ld+json">' . mme_fill($page['schema'], true) . '</script>' . "\n"; // phpcs:ignore
}, 2);

add_filter('pre_get_document_title', function ($title) {
	$page = mme_current();
	return $page ? $page['title'] : $title;
}, 20);

/* Çerez şeridi: tercih bir kez sorulur; "Reddet" onayı kapatır. */
add_action('wp_footer', function () {
	if (mme_gtm_id() === '') {
		return;
	}
	echo '<style>.cerez{position:fixed;left:16px;right:16px;bottom:16px;z-index:40;max-width:620px;margin:0 auto;display:flex;align-items:center;gap:12px 16px;flex-wrap:wrap;background:var(--bg);border:1px solid var(--line-strong);border-radius:12px;padding:12px 16px;font-size:14px;line-height:21px;color:var(--mute);box-shadow:0 6px 24px rgba(20,20,20,.08)}
.cerez p{margin:0;flex:1 1 260px}
.cerez a{color:var(--brown)}
.cerez__act{display:flex;gap:8px}
.cerez button{font:inherit;font-size:14px;font-weight:500;height:38px;padding:0 14px;border-radius:8px;cursor:pointer;border:1px solid var(--line-strong);background:var(--bg);color:var(--ink)}
.cerez button[data-cerez-ok]{background:var(--brown);border-color:var(--brown);color:#fff}
@media (max-width:860px){.cerez{bottom:calc(84px + env(safe-area-inset-bottom,0px));left:12px;right:12px;padding:10px 12px}}</style>';
	echo '<div class="cerez" data-cerez hidden role="region" aria-label="Çerez tercihi"><p>Sayfanın nasıl kullanıldığını ölçmek için çerez kullanıyoruz. <a href="https://drserapkorkmaz.com/kvkk" target="_blank" rel="noopener">KVKK metni</a></p><div class="cerez__act"><button type="button" data-cerez-no>Reddet</button><button type="button" data-cerez-ok>Kabul et</button></div></div>' . "\n";
	echo "<script>(function(){var k='mme_cerez',v=null;try{v=localStorage.getItem(k)}catch(e){}if(v==='1'||v==='0')return;"
		. "var b=document.querySelector('[data-cerez]');if(!b)return;b.hidden=false;"
		. "b.addEventListener('click',function(e){var t=e.target.closest('button');if(!t)return;var ok=t.hasAttribute('data-cerez-ok');try{localStorage.setItem(k,ok?'1':'0')}catch(x){}b.hidden=true;"
		. "if(!ok&&window.gtag){gtag('consent','update',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied'});gtag('set','ads_data_redaction',true);}});})();</script>\n";
}, 5);

add_action('wp_footer', function () {
	echo '<script>(function () { document.addEventListener(\'click\', function (ev) { var a = ev.target.closest ? ev.target.closest(\'a\') : null; if (!a) return; var soru = a.getAttribute(\'data-soru\'); if (!soru && a.hasAttribute(\'data-ask-link\')) { var r = document.querySelector(\'[data-ask] input[name="soru"]:checked\'); var m = r && (r.getAttribute(\'data-qr\') || \'\').match(/qr-[a-z]+-([a-z]+)\\.svg/); soru = m ? m[1] : \'\'; } if (soru) (window.dataLayer = window.dataLayer || []).push({ event: \'hazir_soru\', soru: soru }); }, true); })();</script>' . "\n";
}, 30);

/* Gereksiz çıktılar: emoji betiği, oEmbed, RSD, kısa bağlantı, yorum akışları. */
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'wp_shortlink_wp_head');
remove_action('wp_head', 'wp_oembed_add_discovery_links');
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'feed_links', 2);
remove_action('wp_head', 'feed_links_extra', 3);
add_filter('comments_open', '__return_false');
add_filter('pings_open', '__return_false');
add_filter('wp_img_tag_add_auto_sizes', '__return_false');

/* Arama motorları: site haritasında yalnız sayfalar; yazar, kategori, tarih, arama ve yazı adresleri ana sayfaya gider. */
add_filter('wp_sitemaps_add_provider', function ($provider, $name) {
	return in_array($name, ['users', 'taxonomies'], true) ? false : $provider;
}, 10, 2);
add_filter('wp_sitemaps_post_types', function ($types) {
	return array_intersect_key($types, ['page' => true]);
});
add_action('template_redirect', function () {
	if (is_author() || is_category() || is_tag() || is_date() || is_search() || is_attachment() || is_singular('post') || (is_home() && ! is_front_page())) {
		wp_safe_redirect(home_url('/'), 301);
		exit;
	}
});
/* robots.txt kuralı site kök adrese taşındıktan sonra bir kez yeniden kurulur. */
add_action('init', function () {
	if (get_option('mme_flush') !== '1') {
		flush_rewrite_rules(false);
		update_option('mme_flush', '1');
	}
}, 99);

/* Tek seferlik bakım dosyası (varsa): iş bitince silinir. */
if (is_file(MME_DIR . '/inc/yama.php')) {
	require MME_DIR . '/inc/yama.php';
}
