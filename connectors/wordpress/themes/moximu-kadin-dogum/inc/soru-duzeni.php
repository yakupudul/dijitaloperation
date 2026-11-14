<?php
/**
 * Soru düzeni. Aramalarda görünmeyen kısa sorular ayrı sayfa olmaktan çıkar: metinleri kategori sayfasında
 * konu başlıkları altında durur, eski adresleri oradaki yerlerine kalıcı olarak (301) yönlenir.
 * Hangi sorunun ayrı sayfa kaldığı inc/soru-veri.php içindedir. Bu tarihten sonra eklenen sorular hep ayrı sayfadır.
 */
if (! defined('ABSPATH')) {
	exit;
}

const BK_SORU_DUZENI_TARIHI = '2026-09-20 00:00:00';

function bk_soru_veri(): array
{
	static $veri = null;
	if ($veri === null) {
		$dosya = BK_DIR . '/inc/soru-veri.php';
		$veri = is_file($dosya) ? (array) require $dosya : [];
	}
	return $veri;
}

/** "11200-11210,11215" biçimindeki kimlik listesini açar (dizi verilirse olduğu gibi kullanır). */
function bk_id_listesi($deger): array
{
	if (is_array($deger)) {
		return array_map('intval', $deger);
	}
	$idler = [];
	foreach (explode(',', (string) $deger) as $parca) {
		$uc = array_map('intval', explode('-', trim($parca)));
		if ($uc[0] < 1) {
			continue;
		}
		$son = min(max($uc[1] ?? $uc[0], $uc[0]), $uc[0] + 500);
		for ($id = $uc[0]; $id <= $son; $id++) {
			$idler[] = $id;
		}
	}
	return $idler;
}

/** Ayrı sayfa kalan eski sorular, en çok aranandan aza doğru. */
function bk_kalan_soru_idleri(): array
{
	return array_map('intval', (array) (bk_soru_veri()['kalan'] ?? []));
}

/** Soru kategori sayfasına taşınmış mı? */
function bk_soru_katli($yazi): bool
{
	static $kalan = null;
	$p = get_post($yazi);
	if (! $p || $p->post_type !== 'docs' || $p->post_date >= BK_SORU_DUZENI_TARIHI) {
		return false;
	}
	if ($kalan === null) {
		$kalan = array_flip(bk_kalan_soru_idleri());
	}
	return $kalan && ! isset($kalan[$p->ID]);
}

/** Sorunun gidilecek adresi: ayrı sayfaysa kendi adresi, taşındıysa kategori sayfasındaki yeri. */
function bk_soru_adresi($yazi): string
{
	$p = get_post($yazi);
	if (! $p) {
		return '';
	}
	if (! bk_soru_katli($p)) {
		return (string) get_permalink($p);
	}
	$kat = get_the_terms($p->ID, 'doc_category');
	$adres = ($kat && ! is_wp_error($kat)) ? get_term_link($kat[0]) : '';
	if (! $adres || is_wp_error($adres)) {
		return (string) get_post_type_archive_link('docs');
	}
	return $adres . '#soru-' . $p->ID;
}

/** Ayrı sayfası olan tüm sorular: önce yeni eklenenler, sonra en çok arananlar. */
function bk_ayri_soru_idleri(): array
{
	static $idler = null;
	if ($idler === null) {
		$yeni = get_posts([
			'post_type' => 'docs',
			'post_status' => 'publish',
			'fields' => 'ids',
			'numberposts' => 200,
			'orderby' => 'date',
			'order' => 'DESC',
			'date_query' => [['after' => BK_SORU_DUZENI_TARIHI, 'inclusive' => true]],
		]);
		$idler = array_values(array_unique(array_merge(array_map('intval', $yeni), bk_kalan_soru_idleri())));
	}
	return $idler;
}

/** Kategorinin ayrı sayfası olan soruları (listelerde bunlar gösterilir). */
function bk_ayri_sorular(int $term_id, int $adet = -1): array
{
	$idler = bk_ayri_soru_idleri();
	if (! $idler) {
		return function_exists('bk_kategori_sorulari') ? bk_kategori_sorulari($term_id, $adet) : [];
	}
	return get_posts([
		'post_type' => 'docs',
		'post_status' => 'publish',
		'numberposts' => $adet,
		'post__in' => $idler,
		'orderby' => 'post__in',
		'tax_query' => [['taxonomy' => 'doc_category', 'field' => 'term_id', 'terms' => $term_id, 'include_children' => false]],
	]);
}

/**
 * Kategori sayfası için sorular: ['ayri' => WP_Post[], 'konular' => [[başlık, WP_Post[]], ...]].
 * Veri dosyasında konusu yazmayan taşınmış sorular "Diğer sorular" başlığında toplanır.
 */
function bk_kategori_duzeni(int $term_id): array
{
	$tum = get_posts([
		'post_type' => 'docs',
		'post_status' => 'publish',
		'numberposts' => -1,
		'orderby' => 'title',
		'order' => 'ASC',
		'tax_query' => [['taxonomy' => 'doc_category', 'field' => 'term_id', 'terms' => $term_id, 'include_children' => false]],
	]);
	$ayri = [];
	$katli = [];
	foreach ($tum as $p) {
		if (bk_soru_katli($p)) {
			$katli[$p->ID] = $p;
		} else {
			$ayri[$p->ID] = $p;
		}
	}
	$sira = array_flip(bk_ayri_soru_idleri());
	uasort($ayri, function ($a, $b) use ($sira) {
		$sa = $sira[$a->ID] ?? PHP_INT_MAX;
		$sb = $sira[$b->ID] ?? PHP_INT_MAX;
		return $sa === $sb ? strcmp($a->post_title, $b->post_title) : $sa <=> $sb;
	});
	$konular = [];
	$konum = array_flip(wp_list_pluck($tum, 'ID'));
	foreach ((array) (bk_soru_veri()['konular'][$term_id] ?? []) as $konu) {
		$liste = [];
		foreach (bk_id_listesi($konu[1] ?? []) as $id) {
			if (isset($katli[$id])) {
				$liste[] = $katli[$id];
				unset($katli[$id]);
			}
		}
		if ($liste) {
			usort($liste, fn($a, $b) => $konum[$a->ID] <=> $konum[$b->ID]);
			$konular[] = [(string) $konu[0], $liste];
		}
	}
	if ($katli) {
		$konular[] = ['Diğer sorular', array_values($katli)];
	}
	return ['ayri' => array_values($ayri), 'konular' => $konular, 'toplam' => count($tum)];
}

/** Taşınmış sorunun eski adresi kategori sayfasındaki yerine gider. */
add_action('template_redirect', function () {
	if (! is_singular('docs') || is_preview()) {
		return;
	}
	$p = get_queried_object();
	if (! $p instanceof WP_Post || ! bk_soru_katli($p)) {
		return;
	}
	$hedef = bk_soru_adresi($p);
	if ($hedef !== '') {
		wp_safe_redirect($hedef, 301, 'Moximu soru duzeni');
		exit;
	}
}, 2);

/** Kategori sayfasının sayfalanmış adresleri (…/page/2/) aynı içeriği gösterir; ilk sayfaya gider. */
add_action('template_redirect', function () {
	if (is_tax('doc_category') && is_paged()) {
		$adres = get_term_link(get_queried_object());
		if ($adres && ! is_wp_error($adres)) {
			wp_safe_redirect($adres, 301, 'Moximu soru duzeni');
			exit;
		}
	}
}, 3);

/** Yazı ve sayfa metinlerindeki soru bağlantıları, soru taşındıysa doğrudan yeni yerine gider. */
add_filter('the_content', function ($icerik) {
	if (! is_string($icerik) || strpos($icerik, '/soru-cevap/') === false) {
		return $icerik;
	}
	return preg_replace_callback('~href=(["\'])(?:https?://[^"\'/]+)?/soru-cevap/([^"\'/#?]+)/?\1~i', function ($m) {
		$p = get_page_by_path(sanitize_title(rawurldecode($m[2])), OBJECT, 'docs');
		return ($p && bk_soru_katli($p)) ? 'href=' . $m[1] . esc_url(bk_soru_adresi($p)) . $m[1] : $m[0];
	}, $icerik);
}, 20);

/** Taşınmış sorular site haritasında yer almaz. */
function bk_katli_soru_idleri(): array
{
	static $idler = null;
	if ($idler === null) {
		$kalan = bk_kalan_soru_idleri();
		$idler = ! $kalan ? [] : array_map('intval', get_posts([
			'post_type' => 'docs',
			'post_status' => 'publish',
			'fields' => 'ids',
			'numberposts' => -1,
			'post__not_in' => $kalan,
			'date_query' => [['before' => BK_SORU_DUZENI_TARIHI]],
		]));
	}
	return $idler;
}
function bk_site_haritasi_sorulari($args)
{
	if (! is_array($args) || ! in_array('docs', (array) ($args['post_type'] ?? []), true)) {
		return $args;
	}
	$katli = bk_katli_soru_idleri();
	if ($katli) {
		$args['post__not_in'] = array_merge((array) ($args['post__not_in'] ?? []), $katli);
	}
	return $args;
}
add_filter('seopress_sitemaps_single_query', 'bk_site_haritasi_sorulari', 12);
add_filter('seopress_sitemaps_index_post_types_query', 'bk_site_haritasi_sorulari', 12);

/** Kategori sayfasındaki soru ve yanıt listesinin görünümü (yalnızca o sayfalarda yüklenir). */
add_action('wp_enqueue_scripts', function () {
	if (! is_tax('doc_category')) {
		return;
	}
	$css = '.bk-sorukat-alt{font-size:22px;font-weight:500;line-height:1.3;color:#1d2939;margin:40px 20px 0;padding-bottom:10px;border-bottom:1px solid var(--kenar)}'
		. '.bk-sorukat-alt+.bk-soru-liste{margin-top:18px}'
		. '.bk-konu-dizin{display:flex;flex-wrap:wrap;gap:8px;margin:18px 20px 0;padding:0;list-style:none}'
		. '.bk-konu-dizin a{display:inline-flex;align-items:baseline;gap:6px;border:1px solid var(--kenar);border-radius:3px;padding:6px 10px;font-size:14px;line-height:1.35;color:#1d2939;background:#fff}'
		. '.bk-konu-dizin a:hover{border-color:var(--altin);color:var(--altin)}'
		. '.bk-konu-dizin span{font-size:12px;color:#8a8f98}'
		. '.bk-konu{scroll-margin-top:20px}'
		. '.bk-sc{padding:16px 20px;border-bottom:1px solid #efefef;scroll-margin-top:20px}'
		. '.bk-sc:last-child{border-bottom:0}'
		. '.bk-sc h3{font-size:16px;font-weight:600;line-height:1.45;color:#1d2939;margin:0 0 6px}'
		. '.bk-sc p{font-size:15px;line-height:1.65;color:var(--metin);margin:0}'
		. '.bk-sc:target{background:var(--krem);box-shadow:inset 3px 0 0 var(--altin)}'
		. '@media (max-width:1024px){.bk-konu,.bk-sc{scroll-margin-top:95px}}'
		. '@media (max-width:767px){.bk-sorukat-alt{font-size:20px;margin:32px 16px 0}.bk-konu-dizin{margin-left:16px;margin-right:16px}.bk-sc{padding:14px 16px}}';
	wp_add_inline_style('bk-site', $css);
}, 21);

/**
 * Adres bir soruyu gösteriyorsa (…#soru-123) sayfa yazı tipleri yüklendikten sonra o soruya yeniden iner.
 * Ziyaretçi sayfaya dokunduysa karışmaz.
 */
add_action('wp_footer', function () {
	if (! is_tax('doc_category')) {
		return;
	}
	echo "<script data-no-optimize=\"1\">(function(){var d=false;['touchstart','wheel','keydown','mousedown'].forEach(function(e){addEventListener(e,function(){d=true},{passive:true,once:true})});"
		. "function git(){if(d||location.hash.length<2)return;var el;try{el=document.getElementById(decodeURIComponent(location.hash.slice(1)))}catch(e){}if(!el)return;"
		. "var k=document.documentElement,o=k.style.scrollBehavior;k.style.scrollBehavior='auto';el.scrollIntoView();k.style.scrollBehavior=o}"
		. "if(document.fonts&&document.fonts.ready)document.fonts.ready.then(function(){setTimeout(git,60)});"
		. "addEventListener('load',function(){setTimeout(git,150)})})();</script>\n";
}, 30);
