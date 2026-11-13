<?php
/**
 * SEO katmanı. Başlık, açıklama, canonical ve sosyal etiketler SEOPress'te kalır; tema yalnızca eksikleri tamamlar:
 * yapılandırılmış veri (kırıntı, hekim, soru-cevap), uzun açıklamaların kısaltılması, GTM ve önizleme önbelleği.
 */
if (! defined('ABSPATH')) {
	exit;
}

/** Arama motoru açıklaması 160 karakteri geçerse kelime sınırında 155'e indir (SEOPress kalıbından gelen uzun özetler). */
function bk_kisalt(string $metin, int $sinir = 155): string
{
	$metin = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($metin)));
	if (mb_strlen($metin) <= $sinir + 5) {
		return $metin;
	}
	$kes = mb_substr($metin, 0, $sinir);
	$bosluk = mb_strrpos($kes, ' ');
	if ($bosluk !== false && $bosluk > $sinir - 30) {
		$kes = mb_substr($kes, 0, $bosluk);
	}
	return rtrim($kes, " ,;:–-") . '…';
}
add_filter('seopress_titles_desc', function ($d) {
	return is_string($d) && $d !== '' ? bk_kisalt($d) : $d;
}, 20);

/** Yapılandırılmış veri. */
function bk_hekim_semasi(): array
{
	return [
		'@type' => 'Physician',
		'@id' => bk_url('/#hekim'),
		'name' => bk_ayar('hekim'),
		'url' => bk_url('/hakkimda/'),
		'image' => bk_gorsel_url('2026/09/IMG_5024-scaled.jpg', 'large'),
		'medicalSpecialty' => ['Gynecologic', 'Obstetric'],
		'telephone' => bk_ayar('telefon'),
		'email' => bk_ayar('eposta'),
		'address' => [
			'@type' => 'PostalAddress',
			'streetAddress' => bk_ayar('sokak'),
			'addressLocality' => bk_ayar('ilce'),
			'addressRegion' => bk_ayar('il'),
			'postalCode' => bk_ayar('posta_kodu'),
			'addressCountry' => 'TR',
		],
		'geo' => ['@type' => 'GeoCoordinates', 'latitude' => (float) bk_ayar('enlem'), 'longitude' => (float) bk_ayar('boylam')],
		'openingHoursSpecification' => [[
			'@type' => 'OpeningHoursSpecification',
			'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
			'opens' => '08:00',
			'closes' => '20:00',
		]],
		'alumniOf' => [['@type' => 'CollegeOrUniversity', 'name' => 'Gazi Üniversitesi Tıp Fakültesi'], ['@type' => 'CollegeOrUniversity', 'name' => 'Başkent Üniversitesi']],
		'sameAs' => array_values(array_filter([bk_ayar('instagram')])),
	];
}

add_action('wp_head', function () {
	if (is_admin() || is_feed()) {
		return;
	}
	$graf = [];
	$kirinti = bk_kirinti_veri();
	if ($kirinti) {
		$ogeler = [];
		foreach ($kirinti as $i => [$ad, $url]) {
			$oge = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $ad];
			$u = $url !== '' ? $url : (is_singular() ? get_permalink(get_queried_object_id()) : '');
			if ($u) {
				$oge['item'] = $u;
			}
			$ogeler[] = $oge;
		}
		$graf[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $ogeler];
	}
	if (is_page('hakkimda')) {
		$graf[] = ['@type' => 'ProfilePage', 'url' => get_permalink(), 'name' => wp_get_document_title(), 'mainEntity' => ['@id' => bk_url('/#hekim')]];
		$graf[] = bk_hekim_semasi();
	} elseif (is_page(['iletisim-ankara', 'randevu-olustur'])) {
		$graf[] = ['@type' => 'ContactPage', 'url' => get_permalink(), 'name' => wp_get_document_title(), 'about' => ['@id' => bk_url('/#hekim')]];
		$graf[] = bk_hekim_semasi();
	} elseif (is_singular('docs')) {
		$id = get_queried_object_id();
		$cevap = bk_kisalt((string) get_post_field('post_content', $id), 600);
		$graf[] = [
			'@type' => 'FAQPage',
			'url' => get_permalink($id),
			'inLanguage' => 'tr-TR',
			'dateModified' => get_post_modified_time('c', true, $id),
			'reviewedBy' => ['@id' => bk_url('/#hekim')],
			'mainEntity' => [[
				'@type' => 'Question',
				'name' => get_the_title($id),
				'acceptedAnswer' => ['@type' => 'Answer', 'text' => $cevap, 'author' => ['@id' => bk_url('/#hekim')]],
			]],
		];
		$graf[] = ['@type' => 'Physician', '@id' => bk_url('/#hekim'), 'name' => bk_ayar('hekim'), 'url' => bk_url('/hakkimda/')];
	}
	if (! $graf) {
		return;
	}
	echo '<script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org', '@graph' => $graf], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
}, 30);

/** GTM: Elementor Pro "Özel kod" kaldırılınca etiketi tema basar (tek kopya). */
add_action('wp_head', function () {
	if (! bk_gtm_tema_basar() || is_admin()) {
		return;
	}
	$id = esc_js(bk_ayar('gtm'));
	echo "<!-- Google Tag Manager -->\n<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{$id}');</script>\n<!-- End Google Tag Manager -->\n";
}, 2);
add_action('wp_body_open', function () {
	if (! bk_gtm_tema_basar()) {
		return;
	}
	echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . esc_attr(bk_ayar('gtm')) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
});

/** Gizli önizlemede (Theme Switcha çerezi) sayfa önbelleğe girmesin; ziyaretçi eski temayı görmeye devam eder. */
add_action('template_redirect', function () {
	foreach (array_keys($_COOKIE) as $c) {
		if (str_starts_with($c, 'theme_switcha_theme_')) {
			do_action('litespeed_control_set_nocache', 'tema önizleme');
			nocache_headers();
			return;
		}
	}
}, 0);

/** Kategori arşivlerinde açıklama yoksa kısa, doğal bir açıklama üret (SEOPress boş bırakırsa). */
add_filter('seopress_titles_desc', function ($d) {
	if (is_string($d) && trim($d) !== '') {
		return $d;
	}
	if (is_tax('doc_category')) {
		$t = get_queried_object();
		return bk_kisalt($t->name . ' hakkında sık sorulan sorular ve kısa yanıtlar. ' . $t->count . ' soru, Prof. Dr. Burcu Kısa tarafından yanıtlandı.');
	}
	if (is_post_type_archive('docs')) {
		return 'Gebelik, jinekolojik cerrahi, menopoz, kısırlık ve kadın sağlığı hakkında sık sorulan sorular; Prof. Dr. Burcu Kısa\'nın kısa yanıtları.';
	}
	if (is_category()) {
		$t = get_queried_object();
		return bk_kisalt($t->name . ' başlığındaki blog yazıları: Ankara Çankaya\'da kadın hastalıkları ve doğum uzmanı Prof. Dr. Burcu Kısa\'dan bilgilendirici içerikler.');
	}
	if (is_home()) {
		return 'Gebelik, kadın hastalıkları, genital estetik ve menopoz üzerine Prof. Dr. Burcu Kısa\'nın Ankara\'dan bilgilendirici blog yazıları.';
	}
	return $d;
}, 15);
