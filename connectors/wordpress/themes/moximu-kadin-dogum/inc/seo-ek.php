<?php
/**
 * SEO eklemeleri: eski adreslerin doğru sayfaya gitmesi, başlık uzunluğu, ek yapılandırılmış veri,
 * soru sayfalarında ilgili hizmet ve ilgili sorular.
 */
if (! defined('ABSPATH')) {
	exit;
}

/* WordPress'in "benzer adrese yönlendir" tahmini kapalı: eski hizmet adreslerini tek cümlelik soru sayfalarına gönderiyordu. */
add_filter('do_redirect_guess_404_permalink', '__return_false');

/** Bulunamayan tek parçalı adres bir hizmet sayfasının kısa adıysa (ör. /miyom-ameliyati) o sayfaya 301. */
add_action('template_redirect', function () {
	if (! is_404()) {
		return;
	}
	$yol = trim((string) wp_parse_url((string) wp_unslash($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
	if ($yol === 'blog' || str_starts_with($yol, 'blog/')) {
		wp_safe_redirect(bk_url('/blog-ankara/'), 301, 'Tema');
		exit;
	}
	if ($yol === '' || ! preg_match('/^[a-z0-9-]{3,80}$/', $yol)) {
		return;
	}
	$ad = preg_replace('/-ankara$/', '', $yol);
	$p = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'post_name__in' => [$ad, $ad . '-ankara'], 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'ASC']);
	if ($p && ! bk_yonlendirilmis($p[0])) {
		wp_safe_redirect(get_permalink($p[0]), 301, 'Tema');
		exit;
	}
}, 1);

/* MoxDOP yönlendirmesi olan adresler site haritasında yer almaz. */
add_filter('seopress_sitemaps_single_query', function ($args) {
	$ids = [];
	foreach (array_keys((array) get_option('moxdop_connector_redirects', [])) as $yol) {
		$id = url_to_postid(home_url(trailingslashit((string) $yol)));
		if ($id) {
			$ids[] = $id;
		}
	}
	if ($ids) {
		$args['post__not_in'] = array_merge((array) ($args['post__not_in'] ?? []), $ids);
	}
	return $args;
}, 11);

/** Başlık 60 karakteri geçerse marka eki kısalır; çift boşluklar temizlenir. */
add_filter('seopress_titles_title', function ($t) {
	if (! is_string($t) || $t === '') {
		return $t;
	}
	if (is_category()) {
		$t = single_cat_title('', false) . ' Yazıları | Prof. Dr. Burcu Kısa';
	}
	$t = trim(preg_replace('/\s+/u', ' ', $t));
	$kes = function (string $t, string $ek, string $yeni): string {
		return (mb_strlen($t) > 60 && str_ends_with($t, $ek)) ? mb_substr($t, 0, -mb_strlen($ek)) . $yeni : $t;
	};
	$t = $kes($t, ' - Ankara Kadın Doğum Uzmanı | Prof. Dr. Burcu Kısa', ' | Prof. Dr. Burcu Kısa');
	$t = $kes($t, ' | Prof. Dr. Burcu Kısa', ' | Dr. Burcu Kısa');
	$t = $kes($t, ' | Dr. Burcu Kısa', '');
	return $t;
}, 20);

/* Yorum beslemesi bağlantıları (her yazı için ayrı /feed/) taranmasın. */
remove_action('wp_head', 'feed_links_extra', 3);

/** Ek yapılandırılmış veri: ana sayfada hekim, hizmet sayfalarında hekim tarafından gözden geçirilmiş tıbbi sayfa. */
add_action('wp_head', function () {
	if (is_admin() || is_feed()) {
		return;
	}
	$graf = [];
	if (is_front_page()) {
		$graf[] = bk_hekim_semasi();
	} elseif (is_page() && bk_hizmet_sayfasi_mi()) {
		$id = get_queried_object_id();
		$graf[] = [
			'@type' => 'MedicalWebPage',
			'@id' => get_permalink($id) . '#sayfa',
			'url' => get_permalink($id),
			'name' => get_the_title($id),
			'inLanguage' => 'tr-TR',
			'datePublished' => get_post_time('c', true, $id),
			'dateModified' => get_post_modified_time('c', true, $id),
			'lastReviewed' => get_post_modified_time('Y-m-d', true, $id),
			'audience' => ['@type' => 'Patient'],
			'reviewedBy' => ['@id' => bk_url('/#hekim')],
			'author' => ['@id' => bk_url('/#hekim')],
		];
		$graf[] = ['@type' => 'Physician', '@id' => bk_url('/#hekim'), 'name' => bk_ayar('hekim'), 'url' => bk_url('/hakkimda/')];
	}
	if ($graf) {
		echo '<script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org', '@graph' => $graf], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
	}
}, 31);

/* ------------------------------------------------ Soru sayfası: ilgili hizmet ve ilgili sorular */

function bk_kucuk(string $s): string
{
	return mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $s));
}

/** Sorudaki anahtar kelimeye göre ilgili hizmet sayfası: [sayfa, eşleşen kelime]. Bulunamazsa kategori sayfası. */
function bk_soru_hizmeti(int $id, $kat): array
{
	$g = 'gebelik-ve-dogum/';
	$e = 'genital-estetik-uygulamalari/';
	$c = 'jinekolojik-cerrahi/';
	$k = 'kadin-hastaliklari-ve-cinsel-saglik/';
	$t = 'kisirlik-ve-tup-bebek-tedavileri/';
	$m = 'menopoz-ve-ileri-yas-kadin-sagligi/';
	$u = 'urojinekoloji-ve-pelvik-taban/';
	$harita = [
		['labioplasti|iç dudak', $e . 'ic-dudak-estetigi-labioplasti-ankara'],
		['hudoplasti|klitoris', $e . 'klitoris-ustu-deri-estetigi-hudoplasti-ankara'],
		['lazerle vajina|vajinal lazer|lazer ile vajina', $e . 'lazerle-vajina-daraltma-ankara'],
		['vajinoplasti|vajina daraltma|vajinal daraltma', $e . 'vajina-daraltma-ameliyati-vajinoplasti-ankara'],
		['perine|epizyotomi', $e . 'perine-estetigi-dogum-izi-onarimi-ankara'],
		['o-shot|o shot|prp', $e . 'o-shot-uygulamasi-ankara'],
		['g noktası', $e . 'g-noktasi-dolgusu-ankara'],
		['dolgu', $e . 'genital-dolgu-uygulamalari-ankara'],
		['beyazlatma', $e . 'genital-bolge-beyazlatma-ankara'],
		['himenoplasti|kızlık zarı', $k . 'kizlik-zari-onarimi-himenoplasti-ankara'],
		['vajinismus', $k . 'vajinismus-tedavisi-ankara'],
		['siğil|kondilom', $k . 'genital-sigil-tedavisi-ve-hpv-takibi-ankara'],
		['hpv aşı', $k . 'hpv-asisi-ankara'],
		['kolposkopi', $k . 'kolposkopi-ankara'],
		['smear|rahim ağzı kanser|kanser tarama', $k . 'kadin-kanserleri-taramalari-ankara'],
		['hpv', $k . 'genital-sigil-tedavisi-ve-hpv-takibi-ankara'],
		['hormonlu spiral', $k . 'hormonlu-spiral-ankara'],
		['spiral|rahim içi araç', $k . 'spiral-takilmasi-ve-cikarilmasi-ankara'],
		['pkos|polikistik', $k . 'polikistik-over-sendromu-pkos-tedavisi-ankara'],
		['endometriozis|çikolata kist', $k . 'endometriozis-ve-cikolata-kisti-ankara'],
		['adenomyozis', $k . 'adenomyozis-ankara'],
		['vajinal kuruluk', $k . 'vajinal-kuruluk-tedavisi-ankara'],
		['akıntı|kaşıntı|mantar', $k . 'vajinal-akinti-ve-kasinti-tedavisi-ankara'],
		['ağrılı adet|adet sancı|dismenore', $k . 'agrili-adet-gorme-ankara'],
		['adet düzensiz|adet gecik', $k . 'adet-duzensizligi-tedavisi-ankara'],
		['cinsel isteksiz|libido', $k . 'cinsel-isteksizlik-danismanligi-ankara'],
		['orgazm', $k . 'orgazm-sorunlari-ve-danismanlik-ankara'],
		['askı ameliyat|(tot)', $u . 'idrar-kacirma-ameliyati-tot-ankara'],
		['idrar kaçırmada lazer|lazerle idrar', $u . 'idrar-kacirmada-lazer-uygulamalari-ankara'],
		['idrar kaçır|inkontinans', $u . 'idrar-kacirma-ankara'],
		['rahim sarkma', $u . 'rahim-sarkmasi-tedavisi-ankara'],
		['organ sarkma|mesane sarkma|vajina sarkma|sarkma ameliyat|prolapsus|sistosel|rektosel', $u . 'rahim-ve-vajina-sarkmasi-ankara'],
		['pelvik taban|kegel', $u . 'pelvik-taban-degerlendirmesi-ankara'],
		['pelvik ağrı', $u . 'pelvik-agri-ankara'],
		['biyoözdeş', $m . 'menopozda-biyoozdes-hormon-tedavisi-ankara'],
		['hrt|hormon replasman|hormon tedavi', $m . 'menopozda-hormon-tedavisi-degerlendirmesi-ankara'],
		['sıcak basma|ateş basma', $m . 'menopozda-sicak-basmasi-tedavisi-ankara'],
		['menopoz sonrası kanama', $m . 'menopoz-sonrasi-kanama-ankara'],
		['menopoz', $m . 'menopoz-takibi-ve-tedavisi-ankara'],
		['tüp bebek|ivf|embriyo', $t . 'tup-bebek-tedavisi-ankara'],
		['aşılama|iui', $t . 'asilama-tedavisi-iui-ankara'],
		['hsg|rahim filmi', $t . 'rahim-filmi-hsg-ankara'],
		['yumurtalık rezerv|amh', $t . 'yumurtalik-rezervi-degerlendirmesi-ankara'],
		['yumurtlama', $t . 'yumurtlama-takibi-ankara'],
		['tekrarlayan düşük|tekrarlayan gebelik kayb', $t . 'tekrarlayan-gebelik-kaybi-degerlendirmesi-ankara'],
		['kısırlık|infertilite|azospermi', $t . 'kisirlik-degerlendirmesi-ve-tedavisi-ankara'],
		['histeroskopi', $c . 'histeroskopi-ankara'],
		['polip', $c . 'rahim-ici-polip-tedavisi-ankara'],
		['miyom', $c . 'miyom-ameliyati-ankara'],
		['histerektomi|rahim alma|rahim alın', $c . 'rahim-alma-ameliyati-ankara'],
		['yumurtalık kist', $c . 'yumurtalik-kisti-takibi-ve-tedavisi-ankara'],
		['laparoskopi|kapalı ameliyat', $c . 'laparoskopik-cerrahi-ankara'],
		['tüp bağla|tüp ligasyon', $g . 'tup-baglama-ankara'],
		['dış gebelik', $g . 'dis-gebelik-tani-ve-tedavisi-ankara'],
		['düşük sonrası|düşük yap|düşük risk|düşük tehdi', $g . 'dusuk-sonrasi-takip-ve-tedavi-ankara'],
		[' nst', $g . 'nst-testi-ankara'],
		['4 boyutlu|gebelik ultrason|detaylı ultrason', $g . '4-boyutlu-gebelik-ultrasonu-ankara'],
		['ikiz|çoğul gebelik', $g . 'cogul-gebelik-takibi-ankara'],
		['sezaryen sonrası normal|sezaryen sonrası vajinal', $g . 'sezaryen-sonrasi-vajinal-dogum-degerlendirmesi-ankara'],
		['sezaryen', $g . 'sezaryen-dogum-ankara'],
		['normal doğum|vajinal doğum|epidural', $g . 'vajinal-dogum-normal-dogum-ankara'],
		['lohusa|emzir|anne sütü', $g . 'lohusalik-takibi-ankara'],
		['doğum kontrol|korunma yöntem', $g . 'dogum-kontrol-yontemleri-danismanligi-ankara'],
		['tarama test|ikili test|üçlü test|amniyosentez', $g . 'gebelikte-tarama-testleri-ankara'],
		['riskli gebelik|preeklampsi|gebelik şekeri', $g . 'riskli-gebelik-takibi-ankara'],
		['gebelik öncesi|folik asit', $g . 'gebelik-oncesi-danismanlik-ankara'],
	];
	$baslik = ' ' . bk_kucuk(get_the_title($id)) . ' ';
	foreach ($harita as [$kelimeler, $yol]) {
		foreach (explode('|', $kelimeler) as $kelime) {
			if (str_contains($baslik, $kelime)) {
				$p = get_page_by_path('hizmetlerim/' . $yol);
				if ($p && $p->post_status === 'publish' && ! bk_yonlendirilmis($p)) {
					return [$p, $kelime];
				}
			}
		}
	}
	if ($kat) {
		$p = get_page_by_path('hizmetlerim/' . $kat->slug);
		if ($p && $p->post_status === 'publish') {
			return [$p, ''];
		}
	}
	return [null, ''];
}

/** Sorunun altındaki blok: yanıtlayan hekim, ilgili hizmet sayfası, aynı konudaki diğer sorular. */
function bk_soru_eki(int $id, $kat): void
{
	[$hizmet, $kelime] = bk_soru_hizmeti($id, $kat);
	/* Yalnızca ayrı sayfası olan sorular önerilir (kategori sayfasına taşınanlar değil), en çok aranan önce. */
	$ayri = function_exists('bk_ayri_soru_idleri') ? array_values(array_diff(bk_ayri_soru_idleri(), [$id])) : [];
	$sorgu = ['post_type' => 'docs', 'post_status' => 'publish', 'numberposts' => 6, 'orderby' => 'date', 'order' => 'DESC', 'suppress_filters' => false];
	if ($ayri) {
		$sorgu['post__in'] = $ayri;
		$sorgu['orderby'] = 'post__in';
	} else {
		$sorgu['post__not_in'] = [$id];
	}
	if ($kat) {
		$sorgu['tax_query'] = [['taxonomy' => 'doc_category', 'field' => 'term_id', 'terms' => $kat->term_id]];
	}
	$ilgili = $kelime !== '' ? get_posts($sorgu + ['s' => trim($kelime, '() ')]) : [];
	if (count($ilgili) < 4) {
		$var = array_merge([$id], wp_list_pluck($ilgili, 'ID'));
		$ek = ['numberposts' => 6 - count($ilgili)] + $sorgu;
		if ($ayri) {
			$ek['post__in'] = array_values(array_diff($ayri, $var));
		} else {
			$ek['post__not_in'] = $var;
		}
		if (! $ayri || $ek['post__in']) {
			$ilgili = array_merge($ilgili, get_posts($ek));
		}
	}
	echo '<div class="bk-soru-ek">';
	echo '<p class="bk-soru-yanit">Yanıtlayan: <a href="' . esc_url(bk_url('/hakkimda/')) . '">' . esc_html(bk_ayar('hekim')) . '</a>, Kadın Hastalıkları ve Doğum Uzmanı. Bu yanıt genel bilgilendirme içindir; kişisel değerlendirme muayene ile yapılır.</p>';
	if ($hizmet) {
		echo '<p class="bk-soru-hizmet">Ayrıntılı bilgi: <a href="' . esc_url(get_permalink($hizmet)) . '">' . esc_html(get_the_title($hizmet)) . '</a></p>';
	}
	if ($ilgili) {
		echo '<h2>İlgili sorular</h2><ul>';
		foreach ($ilgili as $p) {
			echo '<li><a href="' . esc_url(get_permalink($p)) . '">' . esc_html(get_the_title($p)) . '</a></li>';
		}
		echo '</ul>';
	}
	echo '</div>';
}
