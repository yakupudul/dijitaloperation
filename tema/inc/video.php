<?php
/**
 * Videolar: tek yerden yönetim (wp-admin > Videolar).
 * Her video YouTube bağlantısı ya da yüklenmiş bir dosyadır; çalışma alanı ve terapi yöntemi sayfalarına bağlanabilir.
 * Bağlı videolar o sayfada görünür; bağlı video yoksa son videolar görünür. Video sayfasında bağlı sayfaların kartları çıkar.
 */
if (! defined('ABSPATH')) {
	exit;
}

add_action('init', function () {
	register_post_type('video', [
		'labels' => [
			'name' => 'Videolar', 'singular_name' => 'Video', 'menu_name' => 'Videolar', 'all_items' => 'Tüm videolar',
			'add_new' => 'Yeni video', 'add_new_item' => 'Yeni video ekle', 'edit_item' => 'Videoyu düzenle', 'new_item' => 'Yeni video',
			'view_item' => 'Videoyu görüntüle', 'search_items' => 'Video ara', 'not_found' => 'Video bulunamadı', 'not_found_in_trash' => 'Çöpte video yok',
			'featured_image' => 'Kapak görseli', 'set_featured_image' => 'Kapak görseli seç', 'remove_featured_image' => 'Kapak görselini kaldır', 'use_featured_image' => 'Kapak görseli olarak kullan',
			'archives' => 'Videolar',
		],
		'public' => true,
		'has_archive' => 'videolar',
		'rewrite' => ['slug' => 'video', 'with_front' => false],
		'menu_icon' => 'dashicons-video-alt3',
		'menu_position' => 5,
		'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'page-attributes'],
		'show_in_rest' => true,
	]);
}, 5);

/* Tema sürümü değişince adres kurallarını bir kez yenile, ilk kurulumda videoları ve menüleri oluştur. */
add_action('init', function () {
	if (get_option('mxp_rw') !== MXP_VER) {
		flush_rewrite_rules(false);
		update_option('mxp_rw', MXP_VER, true);
	}
	mxv_tohumla();
	mxp_menu_kur();
	mxv_metin_ekle();
	mxp_menu_v2();
	mxp_v15();
}, 30);

/* ------------------------------------------------------------------ veri */

function mxv_yt_id(string $s): string
{
	$s = trim($s);
	if (preg_match('~^[A-Za-z0-9_-]{11}$~', $s)) {
		return $s;
	}
	if (preg_match('~(?:youtu\.be/|[?&]v=|/shorts/|/embed/|/live/)([A-Za-z0-9_-]{11})~', $s, $m)) {
		return $m[1];
	}
	return '';
}

function mxv_veri($p): array
{
	$p = get_post($p);
	static $cache = [];
	if (isset($cache[$p->ID])) {
		return $cache[$p->ID];
	}
	$yt = (string) get_post_meta($p->ID, '_mxv_yt', true);
	$dosya = (string) get_post_meta($p->ID, '_mxv_dosya', true);
	$on = (string) get_post_meta($p->ID, '_mxv_onizleme', true);
	$bicim = (string) get_post_meta($p->ID, '_mxv_bicim', true) ?: ($yt ? 'yatay' : 'dikey');
	$thumb_id = (int) get_post_thumbnail_id($p);
	$kapak = $thumb_id ? (string) wp_get_attachment_image_url($thumb_id, $bicim === 'dikey' ? 'medium_large' : 'large') : '';
	$kapak_tam = $thumb_id ? (string) wp_get_attachment_image_url($thumb_id, 'full') : '';
	if ($kapak === '' && $yt !== '') {
		$kapak = $kapak_tam = 'https://i.ytimg.com/vi/' . $yt . '/hqdefault.jpg';
	}
	if ($kapak === '') {
		$kapak = $kapak_tam = MXP_URI . '/assets/img/hero.webp';
	}
	$daire = ($yt !== '' && is_file(MXP_DIR . '/assets/img/c_' . $yt . '.webp')) ? MXP_URI . '/assets/img/c_' . $yt . '.webp'
		: ($thumb_id ? (string) wp_get_attachment_image_url($thumb_id, 'thumbnail') : $kapak);
	$sayfalar = array_values(array_filter(array_map('intval', (array) get_post_meta($p->ID, '_mxv_sayfa'))));
	return $cache[$p->ID] = [
		'id' => $p->ID,
		'baslik' => get_the_title($p),
		'url' => get_permalink($p),
		'yt' => $yt,
		'dosya' => $dosya,
		'onizleme' => $on !== '' ? $on : $dosya,
		'kapak' => $kapak,
		'kapak_tam' => $kapak_tam,
		'daire' => $daire,
		'bicim' => $bicim,
		'sayfalar' => $sayfalar,
		'ozet' => has_excerpt($p) ? get_the_excerpt($p) : wp_trim_words(wp_strip_all_tags((string) $p->post_content), 30),
	];
}

/** Video listesi. $q: bicim, kaynak (yt|dosya), sayfa (id), haric (id listesi). */
function mxv_liste(array $q = [], int $n = -1, string $order = 'sira'): array
{
	$meta = [];
	if (! empty($q['bicim'])) {
		$meta[] = ['key' => '_mxv_bicim', 'value' => $q['bicim']];
	}
	if (($q['kaynak'] ?? '') === 'yt') {
		$meta[] = ['key' => '_mxv_yt', 'value' => '', 'compare' => '!='];
	}
	if (($q['kaynak'] ?? '') === 'dosya') {
		$meta[] = ['key' => '_mxv_dosya', 'value' => '', 'compare' => '!='];
	}
	if (! empty($q['sayfa'])) {
		$meta[] = ['key' => '_mxv_sayfa', 'value' => (string) (int) $q['sayfa']];
	}
	$args = [
		'post_type' => 'video', 'post_status' => 'publish', 'posts_per_page' => $n, 'no_found_rows' => true,
		'orderby' => $order === 'yeni' ? ['date' => 'DESC'] : ['menu_order' => 'ASC', 'date' => 'DESC'],
		'post__not_in' => $q['haric'] ?? [],
	];
	if ($meta) {
		$args['meta_query'] = $meta;
	}
	return get_posts($args);
}

function mxv_dosyali_videolar(): array
{
	return mxv_liste(['kaynak' => 'dosya']);
}

/* ------------------------------------------------------------------ kartlar */

function mxv_play(): string
{
	return '<span class="play"><svg viewBox="0 0 10 12"><path d="M0 0l10 6-10 6z"/></svg></span>';
}

/** Oynatma öznitelikleri: tıklayınca pencerede açılır, bağlantı video sayfasıdır. */
function mxv_attr(array $v): string
{
	$a = ' href="' . esc_url($v['url']) . '" data-url="' . esc_url($v['url']) . '" data-name="' . esc_attr($v['baslik']) . '" data-img="' . esc_url($v['kapak_tam']) . '"';
	if ($v['yt'] !== '') {
		$a .= ' data-yt="' . esc_attr($v['yt']) . '"';
	} elseif ($v['dosya'] !== '') {
		$a .= ' data-mp4="' . esc_url($v['dosya']) . '" data-poster="' . esc_url($v['kapak']) . '"';
	}
	if ($v['bicim'] === 'yatay') {
		$a .= ' data-wide';
	}
	return $a;
}

function mxv_kart($p, string $tip = 'vid'): string
{
	$v = mxv_veri($p);
	$t = esc_html($v['baslik']);
	switch ($tip) {
		case 'story':
			/* Başlıklı video yuvarlağı. */
			$buyuk = mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], (string) $v['baslik']), 'UTF-8');
			return '<a class="story"' . mxv_attr($v) . ' aria-label="' . esc_attr($v['baslik']) . ' videosunu izleyin"><span class="orb" aria-hidden="true"></span><span class="tt" aria-hidden="true">' . esc_html($buyuk) . '</span><span class="izle" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 4.6v14.8a1.2 1.2 0 0 0 1.8 1l12.2-7.4a1.2 1.2 0 0 0 0-2L8.8 3.6A1.2 1.2 0 0 0 7 4.6z"/></svg>İZLE</span></a>';
		case 'reel':
			return '<a class="reel"' . mxv_attr($v) . ' aria-label="Videoyu sesli izleyin: ' . esc_attr($v['baslik']) . '"><video muted playsinline loop preload="none" poster="' . esc_url($v['kapak']) . '" data-src="' . esc_url($v['onizleme']) . '"></video>' . mxv_play() . '</a>';
		case 'clip':
			/* Yerinde oynayan dikey video kartı. */
			$src = $v['yt'] !== '' ? ' data-yt="' . esc_attr($v['yt']) . '"' : ' data-clip="' . esc_url($v['dosya']) . '"';
			return '<figure class="clip"' . $src . ' data-name="' . esc_attr($v['baslik']) . '"><div class="frame"><img class="poster" src="' . esc_url($v['kapak']) . '" alt="' . esc_attr($v['baslik']) . '" loading="lazy" width="720" height="1280"><button class="play" type="button"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4.6v14.8a1.2 1.2 0 0 0 1.8 1l12.2-7.4a1.2 1.2 0 0 0 0-2L8.8 3.6A1.2 1.2 0 0 0 7 4.6z"/></svg><span>İzle</span></button></div><figcaption>' . $t . '</figcaption></figure>';
		default:
			$wide = $v['bicim'] === 'yatay';
			return '<a class="vid"' . mxv_attr($v) . '><span class="pic" style="display:block"><img src="' . esc_url($v['kapak']) . '" alt="' . esc_attr($v['baslik']) . '" loading="lazy" width="' . ($wide ? 560 : 360) . '" height="' . ($wide ? 315 : 640) . '">' . mxv_play() . '</span><span class="t">' . $t . '</span></a>';
	}
}

function mxv_kartlar(array $posts, string $tip = 'vid'): string
{
	return implode('', array_map(static fn ($p) => mxv_kart($p, $tip), $posts));
}

/** Kayan şerit sonsuz görünsün diye üç kez yazılır; kopyalar ekran okuyucudan gizlenir. */
function mxv_serit(array $posts): string
{
	$one = mxv_kartlar($posts, 'reel');
	$dup = str_replace('<a class="reel"', '<a class="reel" tabindex="-1" aria-hidden="true"', $one);
	return $one . $dup . $dup;
}

/** Temadaki {{V:...}} yerleri. */
function mxp_slot_v(string $where): string
{
	switch ($where) {
		case 'stories':
			return mxv_kartlar(mxv_liste(['bicim' => 'dikey', 'kaynak' => 'yt'], 10), 'story');
		case 'side':
			return mxv_kartlar(mxv_liste(['bicim' => 'dikey', 'kaynak' => 'yt'], 4, 'yeni'));
		case 'reels':
			/* Önce kendi videoları, sonra yukarıdaki yuvarlaklarda olmayan dikey YouTube videoları; az kalırsa onlar da. */
			$r = mxv_liste(['kaynak' => 'dosya', 'bicim' => 'dikey'], 12);
			$st = wp_list_pluck(mxv_liste(['bicim' => 'dikey', 'kaynak' => 'yt'], 10), 'ID');
			$r = array_merge($r, mxv_liste(['bicim' => 'dikey', 'kaynak' => 'yt', 'haric' => $st], max(0, 12 - count($r))));
			if (count($r) < 8) {
				$r = array_merge($r, mxv_liste(['bicim' => 'dikey', 'kaynak' => 'yt', 'haric' => wp_list_pluck($r, 'ID')], 12 - count($r)));
			}
			return mxv_kartlar($r, 'clip');
		case 'yt':
			return mxv_kartlar(mxv_liste(['bicim' => 'yatay'], 8));
		case 'yt4':
			return mxv_kartlar(mxv_liste(['bicim' => 'yatay'], 4));
		case 'page':
			return mxv_sayfa_videolari(mxp_sayfa_id());
	}
	return '';
}

/** Hizmet sayfasının yan sütunu: bağlı videolar, yoksa son videolar. */
function mxv_sayfa_videolari(int $page_id): string
{
	$dikey = mxv_liste(['sayfa' => $page_id, 'bicim' => 'dikey'], 4);
	$yatay = mxv_liste(['sayfa' => $page_id, 'bicim' => 'yatay'], 3);
	$link = add_query_arg('konu', get_post_field('post_name', $page_id), get_post_type_archive_link('video'));
	if (! $dikey && ! $yatay) {
		$dikey = mxv_liste(['bicim' => 'dikey', 'kaynak' => 'yt'], 4, 'yeni');
		$link = get_post_type_archive_link('video');
	}
	if (! $dikey && ! $yatay) {
		return '';
	}
	$h = '<div class="side-h"><h3>Videolar</h3><a href="' . esc_url($link) . '">Tümü</a></div>';
	if ($dikey) {
		$h .= '<div class="vids">' . mxv_kartlar($dikey) . '</div>';
	}
	if ($yatay) {
		$h .= '<div class="vids-w">' . mxv_kartlar($yatay) . '</div>';
	}
	return $h;
}

/** Köşe videosu: sayfaya bağlı video (önce dosyalı, sonra YouTube dikey), yoksa genel köşe videosu. */
function mxp_slot_bub(): string
{
	if (! mxp_ayar('kose_acik')) {
		return '';
	}
	$p = null;
	$pid = (is_page() && ! is_front_page()) ? (int) get_queried_object_id() : 0;
	if ($pid) {
		$p = mxv_liste(['sayfa' => $pid, 'kaynak' => 'dosya'], 1)[0] ?? null;
		if (! $p) {
			$p = mxv_liste(['sayfa' => $pid, 'kaynak' => 'yt', 'bicim' => 'dikey'], 1)[0] ?? null;
		}
	}
	if (! $p) {
		$id = (int) mxp_ayar('kose_video');
		$p = $id ? get_post($id) : null;
		if (! $p || $p->post_type !== 'video' || $p->post_status !== 'publish') {
			$p = mxv_dosyali_videolar()[0] ?? null;
		}
	}
	if (! $p) {
		return '';
	}
	$v = mxv_veri($p);
	if ($v['dosya'] === '' && $v['yt'] !== '') {
		return '<div class="bub yt" data-v="' . esc_attr($v['yt']) . '" hidden>'
			. '<button class="bub-open" type="button" data-yt="' . esc_attr($v['yt']) . '" data-name="' . esc_attr($v['baslik']) . '" data-url="' . esc_url($v['url']) . '" data-img="' . esc_url($v['kapak']) . '" aria-label="Videoyu sesli izleyin: ' . esc_attr($v['baslik']) . '">'
			. '<img src="' . esc_url($v['kapak']) . '" alt="" loading="lazy"><span class="bub-fr"></span></button></div>';
	}
	if ($v['dosya'] === '') {
		return '';
	}
	return '<div class="bub" data-src="' . esc_url($v['onizleme']) . '" data-full="' . esc_url($v['dosya']) . '" data-poster="' . esc_url($v['kapak']) . '" data-name="' . esc_attr($v['baslik']) . '" hidden>'
		. '<button class="bub-open" type="button" aria-label="Videoyu sesli izleyin: ' . esc_attr($v['baslik']) . '"><video muted playsinline loop preload="none" poster="' . esc_url($v['kapak']) . '"></video></button></div>';
}

/** Hizmet kartı (video sayfasında bağlı sayfalar için). */
function mxv_hizmet_karti(int $page_id): string
{
	$slug = (string) get_post_field('post_name', $page_id);
	$row = mxp_pages()[$slug] ?? null;
	if (! $row || empty($row['menu'])) {
		return '';
	}
	$img = MXP_URI . '/assets/img/' . $row['card'];
	return '<a class="card rv" href="' . esc_url(get_permalink($page_id)) . '"><div class="pic"><img src="' . esc_url($img) . '" alt="' . esc_attr($row['menu']) . '" loading="lazy" width="520" height="520"></div><span class="eyebrow">' . esc_html($row['kind'] === 'alan' ? 'Çalışma alanı' : 'Terapi yöntemi') . '</span><h4>' . esc_html($row['menu']) . '</h4><p>' . esc_html($row['short']) . '</p><span class="more">Sayfayı okuyun</span></a>';
}

/** Bağlanabilir sayfalar: [alan => [id => ad], yontem => [...]]. */
function mxv_baglanabilir(): array
{
	$out = ['alan' => [], 'yontem' => []];
	foreach (mxp_pages() as $slug => $row) {
		if (empty($row['kind']) || $slug === '') {
			continue;
		}
		$page = get_page_by_path($slug);
		if ($page) {
			$out[$row['kind']][$page->ID] = $row['menu'];
		}
	}
	return $out;
}

/* ------------------------------------------------------------------ head: paylaşım etiketleri ve VideoObject */

function mxv_head(): void
{
	$v = mxv_veri(get_queried_object_id());
	$desc = $v['ozet'] !== '' ? $v['ozet'] : rtrim($v['baslik'], '.') . (preg_match('/[?!.]$/u', $v['baslik']) ? '' : '.') . ' Uzm. Klinik Psikolog Ali Akbulut anlatıyor.';
	printf('<meta name="description" content="%s">' . "\n", esc_attr($desc));
	echo '<meta property="og:locale" content="tr_TR">' . "\n" . '<meta property="og:type" content="video.other">' . "\n";
	printf('<meta property="og:site_name" content="%s">' . "\n", esc_attr('Uzm. Klinik Psikolog Ali Akbulut'));
	printf('<meta property="og:title" content="%s">' . "\n", esc_attr($v['baslik']));
	printf('<meta property="og:description" content="%s">' . "\n", esc_attr($desc));
	printf('<meta property="og:url" content="%s">' . "\n", esc_url($v['url']));
	printf('<meta property="og:image" content="%s">' . "\n", esc_url($v['kapak_tam']));
	$embed = $v['yt'] !== '' ? 'https://www.youtube.com/embed/' . $v['yt'] : '';
	if ($v['dosya'] !== '') {
		printf('<meta property="og:video" content="%s">' . "\n" . '<meta property="og:video:type" content="video/mp4">' . "\n", esc_url($v['dosya']));
	} elseif ($embed) {
		printf('<meta property="og:video" content="%s">' . "\n" . '<meta property="og:video:type" content="text/html">' . "\n", esc_url($embed));
	}
	echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
	printf('<meta name="twitter:title" content="%s">' . "\n" . '<meta name="twitter:image" content="%s">' . "\n", esc_attr($v['baslik']), esc_url($v['kapak_tam']));
	$home = untrailingslashit(home_url());
	$ld = [
		'@context' => 'https://schema.org',
		'@graph' => [
			array_filter([
				'@type' => 'VideoObject',
				'name' => $v['baslik'],
				'description' => $desc,
				'thumbnailUrl' => [$v['kapak_tam']],
				'uploadDate' => get_the_date('c', $v['id']),
				'contentUrl' => $v['dosya'] ?: null,
				'embedUrl' => $embed ?: null,
				'inLanguage' => 'tr',
				'url' => $v['url'],
				'author' => ['@type' => 'Person', '@id' => $home . '/#ali-akbulut', 'name' => 'Ali Akbulut', 'jobTitle' => 'Uzman Klinik Psikolog', 'url' => $home . '/ali-akbulut/'],
				'publisher' => ['@id' => $home . '/#isletme'],
				'about' => array_map(static fn ($id) => ['@type' => 'WebPage', 'url' => get_permalink($id), 'name' => get_the_title($id)], $v['sayfalar']) ?: null,
			]),
			['@type' => 'BreadcrumbList', 'itemListElement' => [
				['@type' => 'ListItem', 'position' => 1, 'name' => 'Ana sayfa', 'item' => $home . '/'],
				['@type' => 'ListItem', 'position' => 2, 'name' => 'Videolar', 'item' => get_post_type_archive_link('video')],
				['@type' => 'ListItem', 'position' => 3, 'name' => $v['baslik'], 'item' => $v['url']],
			]],
		],
	];
	echo '<script type="application/ld+json">' . wp_json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n"; // phpcs:ignore
}

function mxv_arsiv_head(): void
{
	$desc = 'Uzm. Klinik Psikolog Ali Akbulut\'un çocuk, ergen, ilişki, çift ve cinsel terapi üzerine kısa ve uzun videoları.';
	printf('<meta name="description" content="%s">' . "\n", esc_attr($desc));
	echo '<meta property="og:locale" content="tr_TR">' . "\n" . '<meta property="og:type" content="website">' . "\n";
	printf('<meta property="og:title" content="%s">' . "\n", esc_attr(wp_get_document_title()));
	printf('<meta property="og:url" content="%s">' . "\n", esc_url(get_post_type_archive_link('video')));
	printf('<meta property="og:image" content="%s">' . "\n", esc_url(MXP_URI . '/assets/img/hero.webp'));
	$items = [];
	foreach (mxv_liste([], 30) as $n => $p) {
		$items[] = ['@type' => 'ListItem', 'position' => $n + 1, 'url' => get_permalink($p), 'name' => get_the_title($p)];
	}
	echo '<script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => 'Videolar', 'url' => get_post_type_archive_link('video'), 'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => $items]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n"; // phpcs:ignore
}

add_filter('document_title_parts', function (array $parts) {
	if (is_post_type_archive('video')) {
		$parts['title'] = 'Videolar';
		$k = sanitize_title(wp_unslash($_GET['konu'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
		$row = $k !== '' ? (mxp_pages()[$k] ?? null) : null;
		if ($row && ! empty($row['menu'])) {
			$parts['title'] = $row['menu'] . ' videoları';
		}
		$parts['site'] = 'Uzm. Klinik Psikolog Ali Akbulut';
	}
	return $parts;
});

/* Arşivin tek adresi /videolar/ (konu süzgeci başka adres sayılmaz). */
add_action('wp_head', function () {
	if (is_post_type_archive('video')) {
		printf('<link rel="canonical" href="%s">' . "\n", esc_url(get_post_type_archive_link('video')));
	}
}, 1);

/* ------------------------------------------------------------------ yönetim: video kutusu */

/* Videolar klasik düzenleyiciyle açılır: video alanları başlığın hemen altında görünür. */
add_filter('use_block_editor_for_post_type', static fn ($use, $type) => $type === 'video' ? false : $use, 10, 2);

add_action('add_meta_boxes_video', function () {
	add_meta_box('mxv-kutu', 'Video', 'mxv_kutu', 'video', 'normal', 'high');
	add_meta_box('mxv-bag', 'Bağlı sayfalar', 'mxv_bag_kutu', 'video', 'side', 'default');
});

add_action('admin_enqueue_scripts', function () {
	$s = get_current_screen();
	if ($s && $s->post_type === 'video' && $s->base === 'post') {
		wp_enqueue_media();
	}
});

function mxv_kutu(WP_Post $post): void
{
	wp_nonce_field('mxv_kaydet', 'mxv_n');
	$yt = (string) get_post_meta($post->ID, '_mxv_yt', true);
	$bicim = (string) get_post_meta($post->ID, '_mxv_bicim', true) ?: 'oto';
	$f = static function (string $name, string $label, string $val, string $help, string $media = '') {
		echo '<p><label for="mxv-' . esc_attr($name) . '"><b>' . esc_html($label) . '</b></label><br><span style="display:flex;gap:6px"><input class="widefat" type="url" id="mxv-' . esc_attr($name) . '" name="mxv_' . esc_attr($name) . '" value="' . esc_attr($val) . '">';
		if ($media) {
			echo '<button type="button" class="button mxv-sec" data-hedef="mxv-' . esc_attr($name) . '" data-tur="' . esc_attr($media) . '">Medyadan seç</button>';
		}
		echo '</span>' . ($help ? '<span class="description">' . esc_html($help) . '</span>' : '') . '</p>';
	};
	$f('yt', 'YouTube bağlantısı', $yt ? 'https://www.youtube.com/watch?v=' . $yt : '', 'Video YouTube\'daysa bağlantısını yapıştırın (kısa video bağlantısı da olur). Kapak görseli seçmezseniz YouTube\'un kapağı kullanılır.');
	$f('dosya', 'Ya da video dosyası (mp4)', (string) get_post_meta($post->ID, '_mxv_dosya', true), 'Videoyu siteye yüklediyseniz. Sesli oynayan asıl dosya.', 'video');
	$f('onizleme', 'Sessiz önizleme dosyası (isteğe bağlı)', (string) get_post_meta($post->ID, '_mxv_onizleme', true), 'Kayan şeritte ve sağ alttaki küçük pencerede oynayan kısa, hafif dosya. Boşsa asıl dosya kullanılır.', 'video');
	echo '<p><b>Biçim</b><br>';
	foreach (['oto' => 'Kendiliğinden belirle', 'dikey' => 'Dikey (kısa video)', 'yatay' => 'Yatay'] as $k => $l) {
		printf('<label style="margin-right:16px"><input type="radio" name="mxv_bicim" value="%s"%s> %s</label>', esc_attr($k), checked($bicim, $k, false), esc_html($l));
	}
	echo '</p><p class="description">Kapak görseli sağdaki "Kapak görseli" kutusundan seçilir. Paylaşırken başlık ve bu kapak görünür. Açıklamaya videoda ne anlatıldığını iki üç cümleyle yazmanız Google\'da bulunmasına yardım eder.</p>';
	echo "<script>document.querySelectorAll('.mxv-sec').forEach(function(b){b.addEventListener('click',function(){var fr=wp.media({title:'Video seçin',library:{type:b.dataset.tur},multiple:false});fr.on('select',function(){var a=fr.state().get('selection').first().toJSON();document.getElementById(b.dataset.hedef).value=a.url});fr.open()})});</script>";
}

function mxv_bag_kutu(WP_Post $post): void
{
	$sel = array_map('intval', (array) get_post_meta($post->ID, '_mxv_sayfa'));
	echo '<p class="description" style="margin-top:0">Video seçtiğiniz sayfalarda görünür; video sayfasında da bu sayfaların kartları çıkar.</p>';
	foreach (['alan' => 'Çalışma alanları', 'yontem' => 'Terapi yöntemleri'] as $kind => $label) {
		echo '<p style="margin-bottom:4px"><b>' . esc_html($label) . '</b></p>';
		foreach (mxv_baglanabilir()[$kind] as $id => $name) {
			printf('<label style="display:block;margin:3px 0"><input type="checkbox" name="mxv_sayfa[]" value="%d"%s> %s</label>', $id, checked(in_array($id, $sel, true), true, false), esc_html($name));
		}
	}
}

add_action('save_post_video', function (int $id) {
	if (! isset($_POST['mxv_n']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mxv_n'])), 'mxv_kaydet') || ! current_user_can('edit_post', $id) || wp_is_post_revision($id)) {
		return;
	}
	$raw = sanitize_text_field(wp_unslash($_POST['mxv_yt'] ?? ''));
	$yt = mxv_yt_id($raw);
	$dosya = esc_url_raw(trim(wp_unslash($_POST['mxv_dosya'] ?? '')));
	$on = esc_url_raw(trim(wp_unslash($_POST['mxv_onizleme'] ?? '')));
	update_post_meta($id, '_mxv_yt', $yt);
	update_post_meta($id, '_mxv_dosya', $dosya);
	update_post_meta($id, '_mxv_onizleme', $on);
	$b = sanitize_key(wp_unslash($_POST['mxv_bicim'] ?? 'oto'));
	if (! in_array($b, ['dikey', 'yatay'], true)) {
		$b = ($yt && ! str_contains($raw, '/shorts/')) ? 'yatay' : 'dikey';
	}
	update_post_meta($id, '_mxv_bicim', $b);
	delete_post_meta($id, '_mxv_sayfa');
	$ok = array_keys(mxv_baglanabilir()['alan'] + mxv_baglanabilir()['yontem']);
	foreach (array_map('intval', (array) wp_unslash($_POST['mxv_sayfa'] ?? [])) as $pid) {
		if (in_array($pid, $ok, true)) {
			add_post_meta($id, '_mxv_sayfa', $pid);
		}
	}
});

/* Yönetim listesinde kapak, kaynak, biçim ve bağlı sayfalar. */
add_filter('manage_video_posts_columns', function (array $c) {
	$new = [];
	foreach ($c as $k => $v) {
		if ($k === 'title') {
			$new['mxv_kapak'] = 'Kapak';
		}
		$new[$k] = $v;
		if ($k === 'title') {
			$new['mxv_kaynak'] = 'Kaynak';
			$new['mxv_bag'] = 'Bağlı sayfalar';
		}
	}
	return $new;
});

add_action('manage_video_posts_custom_column', function (string $col, int $id) {
	$v = mxv_veri($id);
	if ($col === 'mxv_kapak') {
		printf('<img src="%s" alt="" style="width:%dpx;height:64px;object-fit:cover;border-radius:6px">', esc_url($v['kapak']), $v['bicim'] === 'dikey' ? 36 : 112);
	} elseif ($col === 'mxv_kaynak') {
		echo esc_html(($v['yt'] ? 'YouTube' : ($v['dosya'] ? 'Dosya' : 'Eksik')) . ', ' . ($v['bicim'] === 'dikey' ? 'dikey' : 'yatay'));
	} elseif ($col === 'mxv_bag') {
		echo esc_html(implode(', ', array_map('get_the_title', $v['sayfalar'])) ?: '—');
	}
}, 10, 2);

/* ------------------------------------------------------------------ ilk kurulum: mevcut videolar (bir kez) */

function mxv_tohumla(): void
{
	if (get_option('mxv_tohum')) {
		return;
	}
	update_option('mxv_tohum', 1, false);
	if (get_posts(['post_type' => 'video', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids'])) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$pages = [];
	foreach (array_keys(mxp_pages()) as $slug) {
		if ($slug !== '' && ($pg = get_page_by_path($slug))) {
			$pages[$slug] = $pg->ID;
		}
	}
	$now = time();
	foreach ((mxp_varsayilan()['videolar'] ?? []) as $n => $v) {
		$id = wp_insert_post([
			'post_type' => 'video', 'post_status' => 'publish', 'post_title' => $v['baslik'], 'post_content' => '',
			'menu_order' => $v['sira'], 'post_date' => wp_date('Y-m-d H:i:s', $now - 60 * $v['yas']),
		], true);
		if (is_wp_error($id)) {
			continue;
		}
		update_post_meta($id, '_mxv_yt', $v['yt']);
		update_post_meta($id, '_mxv_bicim', $v['bicim']);
		update_post_meta($id, '_mxv_dosya', $v['dosya'] ? mxv_kutuphaneye($v['dosya'], $id, $v['baslik']) : '');
		update_post_meta($id, '_mxv_onizleme', $v['onizleme'] ? mxv_kutuphaneye($v['onizleme'], $id, $v['baslik'] . ' (önizleme)') : '');
		if ($v['kapak']) {
			$att = mxv_kutuphaneye($v['kapak'], $id, $v['baslik'], true);
			if ($att) {
				set_post_thumbnail($id, (int) $att);
			}
		}
		foreach ($v['sayfalar'] as $slug) {
			if (isset($pages[$slug])) {
				add_post_meta($id, '_mxv_sayfa', $pages[$slug]);
			}
		}
	}
}

/** Temadaki dosyayı medya kütüphanesine kopyalar; adres (ya da $id_dondur ise ek kimliği) döner. */
function mxv_kutuphaneye(string $rel, int $parent, string $title, bool $id_dondur = false)
{
	$src = MXP_DIR . '/assets/' . $rel;
	if (! is_file($src)) {
		return $id_dondur ? 0 : '';
	}
	$up = wp_upload_bits(basename($rel), null, (string) file_get_contents($src));
	if (! empty($up['error'])) {
		return $id_dondur ? 0 : '';
	}
	$type = wp_check_filetype($up['file']);
	$att = wp_insert_attachment(['post_mime_type' => $type['type'], 'post_title' => $title, 'post_status' => 'inherit'], $up['file'], $parent);
	if (is_wp_error($att) || ! $att) {
		return $id_dondur ? 0 : $up['url'];
	}
	if (str_starts_with((string) $type['type'], 'image/')) {
		wp_update_attachment_metadata($att, wp_generate_attachment_metadata($att, $up['file']));
		update_post_meta($att, '_wp_attachment_image_alt', $title);
	}
	return $id_dondur ? (int) $att : (string) wp_get_attachment_url($att);
}

/* ------------------------------------------------------------------ v1.3: video metinleri ve yeni sayfa bağlantıları (bir kez) */

/** Videonun anahtarı: YouTube kimliği ya da dosya adındaki Instagram kodu (t_<kod>.mp4). */
function mxv_anahtar(int $id): string
{
	$yt = (string) get_post_meta($id, '_mxv_yt', true);
	if ($yt !== '') {
		return $yt;
	}
	return preg_match('/t_([A-Za-z0-9_]+?)(?:-\d+)?\.mp4/', (string) get_post_meta($id, '_mxv_dosya', true), $m) ? $m[1] : '';
}

function mxv_metin_ekle(): void
{
	if (get_option('mxv_metin_v1') || ! get_page_by_path('konya-aile-danismanligi')) {
		return;
	}
	update_option('mxv_metin_v1', 1, false);
	$v = mxp_varsayilan();
	$metin = $v['video_metin'] ?? [];
	$bag = $v['ek_bag'] ?? [];
	foreach (get_posts(['post_type' => 'video', 'post_status' => 'any', 'posts_per_page' => -1]) as $p) {
		$k = mxv_anahtar((int) $p->ID);
		if ($k === '') {
			continue;
		}
		if (isset($metin[$k]) && trim((string) $p->post_content) === '') {
			[$baslik, $ozet, $govde] = $metin[$k];
			$up = ['ID' => $p->ID, 'post_content' => $govde];
			if (trim((string) $p->post_excerpt) === '') {
				$up['post_excerpt'] = $ozet;
			}
			if ($baslik !== '') {
				$up['post_title'] = $baslik;
			}
			wp_update_post(wp_slash($up));
		}
		$var = array_map('intval', (array) get_post_meta($p->ID, '_mxv_sayfa'));
		foreach ($bag[$k] ?? [] as $slug) {
			$pg = get_page_by_path($slug);
			if ($pg && ! in_array((int) $pg->ID, $var, true)) {
				add_post_meta($p->ID, '_mxv_sayfa', (int) $pg->ID);
				$var[] = (int) $pg->ID;
			}
		}
	}
}
