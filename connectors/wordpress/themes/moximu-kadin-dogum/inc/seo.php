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

/**
 * Yapılandırılmış veri. Sitede tek işletme kaydı (#hekim) ve tek kişi kaydı (#kisi) vardır; ad, adres ve saatler
 * yalnızca buradan çıkar. SEOPress'in otomatik işletme ve hizmet şemaları da aşağıda bu kayda bağlanır.
 */
function bk_hekim_semasi(): array
{
	return [
		'@type' => 'Physician',
		'@id' => bk_url('/#hekim'),
		'name' => bk_ayar('hekim'),
		'alternateName' => 'Prof. Dr. Burcu Kısa - Kadın Hastalıkları Ve Doğum Uzmanı Ankara',
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
		'hasMap' => bk_ayar('harita'),
		'openingHoursSpecification' => [[
			'@type' => 'OpeningHoursSpecification',
			'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
			'opens' => '08:00',
			'closes' => '20:00',
		]],
		'employee' => ['@id' => bk_url('/#kisi')],
		'sameAs' => bk_profil_adresleri(),
	];
}

/** Hekimin başka sitelerdeki kendi profilleri (şemada sameAs). */
function bk_profil_adresleri(): array
{
	return array_values(array_filter([bk_ayar('instagram'), 'https://www.doktortakvimi.com/profil/burcu-kisa']));
}

/** Hekimin kişi kaydı: eğitim, unvan, çalıştığı yer. */
function bk_kisi_semasi(): array
{
	return [
		'@type' => 'Person',
		'@id' => bk_url('/#kisi'),
		'name' => 'Burcu Kısa',
		'honorificPrefix' => 'Prof. Dr.',
		'jobTitle' => bk_ayar('unvan'),
		'url' => bk_url('/hakkimda/'),
		'image' => bk_gorsel_url('2026/09/IMG_5024-scaled.jpg', 'large'),
		'knowsLanguage' => ['tr', 'en'],
		'worksFor' => ['@id' => bk_url('/#hekim')],
		'alumniOf' => [['@type' => 'CollegeOrUniversity', 'name' => 'Gazi Üniversitesi Tıp Fakültesi'], ['@type' => 'CollegeOrUniversity', 'name' => 'Başkent Üniversitesi']],
		'sameAs' => bk_profil_adresleri(),
	];
}

/**
 * Hekimin PubMed'de doğrulanmış yayınları: [yazarlar, başlık, dergi, yıl, PubMed no, DOI, Türkçe konu].
 * Yeni yayın eklerken PubMed kaydındaki yazımı aynen kullanın.
 */
function bk_yayinlar(): array
{
	return [
		['Kısa B, Kansu-Celik H, Candar T, Erol Koc EM, Sert UY, Uzunlar O', 'Severe 25-OH vitamin D deficiency as a reason for adverse pregnancy outcomes', 'J Matern Fetal Neonatal Med', 2020, '30614393', '10.1080/14767058.2018.1554040', 'Gebelikte ağır D vitamini eksikliği ve gebelik sonuçları'],
		['Kısa B, Sert UY, Celik HK, Candar T, Erol Koç EM, Taşcı Y, Çağlar GS', 'Myo-inositol oxygenese activity in second trimester of pregnancy: altered myoinositol catabolism in gestational diabetes mellitus', 'Arch Physiol Biochem', 2022, '32191130', '10.1080/13813455.2020.1739714', 'Gebelik şekerinde miyo-inositol metabolizması'],
		['Kansu-Celik H, Ozgu-Erdinc AS, Kisa B, Findik RB, Yilmaz C, Tasci Y', 'Prediction of gestational diabetes mellitus in the first trimester: comparison of maternal fetuin-A, N-terminal proatrial natriuretic peptide, high-sensitivity C-reactive protein, and fasting glucose levels', 'Arch Endocrinol Metab', 2019, '31038593', '10.20945/2359-3997000000126', 'Gebelik şekerinin ilk üç ayda öngörülmesi'],
		['Kansu-Celik H, Ozgu-Erdinc AS, Kisa B, Eldem S, Hancerliogullari N, Engin-Ustun Y', 'Maternal serum glycosylated hemoglobin and fasting plasma glucose predicts gestational diabetes at the first trimester in Turkish women with a low-risk pregnancy and its relationship with fetal birth weight; a retrospective cohort study', 'J Matern Fetal Neonatal Med', 2021, '31370710', '10.1080/14767058.2019.1651837', 'İlk üç ayda HbA1c ve açlık şekeri ile gebelik şekeri öngörüsü, bebeğin doğum kilosu'],
		['Hancerliogullari N, Kansu-Celik H, Oskovi-Kaplan ZA, Kisa B, Engin-Ustun Y, Ozgu-Erdinc AS', 'Optimal maternal neck and waist circumference cutoff values for prediction of gestational diabetes mellitus at the first trimester in Turkish population; a prospective cohort study', 'Gynecol Endocrinol', 2020, '32274939', '10.1080/09513590.2020.1750003', 'Boyun ve bel çevresi ölçümü ile gebelik şekeri öngörüsü'],
		['Buyuk GN, Kansu-Celik H, Kaplan ZAO, Kisa B, Ozel S, Engin-Ustun Y', 'Risk Factors for Intrapartum Cesarean Section Delivery in Low-risk Multiparous Women Following at Least a Prior Vaginal Birth (Robson Classification 3 and 4)', 'Rev Bras Ginecol Obstet', 2021, '34318468', '', 'Daha önce normal doğum yapmış kadınlarda doğum sırasında sezaryen gerektiren etkenler'],
		['Kadioglu N, Oskovi Kaplan ZA, Sariaslan S, Kansu Celik H, Kisa B, Yenicesu O, Celik Sarikaya E, Evliyaoglu O, Erkaya S', 'A retrospective analysis of the women hospitalized and treated due to anemia caused by gynecological pathologies: the role of intravenous iron (ferric carboxymaltose) and blood transfusion in clinical practice', 'Minerva Obstet Gynecol', 2023, '35389035', '10.23736/S2724-606X.22.05044-8', 'Jinekolojik nedenli kansızlıkta damardan demir tedavisi ve kan nakli'],
	];
}

/** Yayınların şema karşılığı: yazar listesinde hekim kendi kişi kaydına bağlanır. */
function bk_yayin_semalari(): array
{
	$cikti = [];
	foreach (bk_yayinlar() as [$yazarlar, $baslik, $dergi, $yil, $pmid, $doi]) {
		$yazar = [];
		foreach (explode(', ', $yazarlar) as $ad) {
			$yazar[] = preg_match('/^K[ıi]sa B$/u', $ad) ? ['@id' => bk_url('/#kisi')] : ['@type' => 'Person', 'name' => $ad];
		}
		$cikti[] = array_filter([
			'@type' => 'ScholarlyArticle',
			'headline' => $baslik,
			'url' => 'https://pubmed.ncbi.nlm.nih.gov/' . $pmid . '/',
			'sameAs' => $doi !== '' ? 'https://doi.org/' . $doi : '',
			'datePublished' => (string) $yil,
			'inLanguage' => 'en',
			'isPartOf' => ['@type' => 'Periodical', 'name' => $dergi],
			'author' => $yazar,
		]);
	}
	return $cikti;
}

/** SEOPress'in ana sayfa için ürettiği işletme şeması yerine sitedeki tek hekim kaydı basılır. */
add_filter('seopress_schemas_auto_lb_json', function ($json) {
	return ['@context' => 'https://schema.org'] + bk_hekim_semasi();
});

/** SEOPress'in hizmet şeması: sağlayıcı tek hekim kaydıdır; hizmet türü üst başlık, açıklama sayfanın kendi açıklamasıdır. */
add_filter('seopress_schemas_auto_service_json', function ($json) {
	if (! is_array($json) || ! is_singular()) {
		return $json;
	}
	$id = get_queried_object_id();
	$json['@id'] = get_permalink($id) . '#hizmet';
	$json['url'] = get_permalink($id);
	$json['provider'] = bk_hekim_semasi();
	$ust = (int) wp_get_post_parent_id($id);
	if ($ust) {
		$json['serviceType'] = get_the_title($ust);
	} else {
		unset($json['serviceType']);
	}
	$aciklama = trim((string) get_post_meta($id, '_seopress_titles_desc', true));
	if ($aciklama !== '') {
		$json['description'] = $aciklama;
	}
	unset($json['slogan']);
	return $json;
});

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
		$graf[] = ['@type' => 'ProfilePage', 'url' => get_permalink(), 'name' => wp_get_document_title(), 'dateModified' => get_post_modified_time('c', true), 'mainEntity' => ['@id' => bk_url('/#kisi')]];
		$graf[] = bk_kisi_semasi();
		$graf[] = bk_hekim_semasi();
		$graf = array_merge($graf, bk_yayin_semalari());
	} elseif (is_page(['iletisim-ankara', 'randevu-olustur'])) {
		$graf[] = ['@type' => 'ContactPage', 'url' => get_permalink(), 'name' => wp_get_document_title(), 'about' => ['@id' => bk_url('/#hekim')]];
		$graf[] = bk_hekim_semasi();
	} elseif (is_singular('docs')) {
		$id = get_queried_object_id();
		$cevap = bk_kisalt((string) get_post_field('post_content', $id), 2500);
		$graf[] = [
			'@type' => 'FAQPage',
			'url' => get_permalink($id),
			'inLanguage' => 'tr-TR',
			'dateModified' => get_post_modified_time('c', true, $id),
			'reviewedBy' => ['@id' => bk_url('/#kisi')],
			'mainEntity' => [[
				'@type' => 'Question',
				'name' => get_the_title($id),
				'acceptedAnswer' => ['@type' => 'Answer', 'text' => $cevap, 'author' => ['@id' => bk_url('/#kisi')]],
			]],
		];
		$graf[] = bk_kisi_semasi();
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

/* Yönlendirilmiş sayfalar site haritasında yer almaz (301 veren adres haritada durmasın). */
add_filter('seopress_sitemaps_single_query', function ($args) {
	$ids = get_posts([
		'post_type'      => 'any',
		'post_status'    => 'publish',
		'fields'         => 'ids',
		'posts_per_page' => 200,
		'meta_query'     => [['key' => '_seopress_redirections_enabled', 'value' => 'yes']],
	]);
	if ($ids) {
		$args['post__not_in'] = array_merge((array) ($args['post__not_in'] ?? []), $ids);
	}
	return $args;
});
