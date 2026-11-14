<?php
/**
 * Tekrar eden parçalar: başlık, menüler, kırıntı, kartlar, yan sütun kutuları, alt bilgi.
 */
if (! defined('ABSPATH')) {
	exit;
}

require BK_DIR . '/inc/menu-veri.php';

function bk_url(string $yol = '/'): string
{
	return home_url($yol);
}

/** Bir sayfanın bağlantısı: SEOPress'te 301 tanımlıysa doğrudan hedefe gider (zincir olmaz). */
function bk_sayfa_link($p): string
{
	$p = get_post($p);
	if (! $p) {
		return '';
	}
	if (get_post_meta($p->ID, '_seopress_redirections_enabled', true) === 'yes') {
		$hedef = (string) get_post_meta($p->ID, '_seopress_redirections_value', true);
		if ($hedef !== '') {
			return $hedef;
		}
	}
	return get_permalink($p);
}

/** Yönlendirilmiş (yalnızca 301 için duran) sayfa mı? */
function bk_yonlendirilmis($p): bool
{
	$p = get_post($p);
	return $p && get_post_meta($p->ID, '_seopress_redirections_enabled', true) === 'yes'
		&& get_post_meta($p->ID, '_seopress_redirections_value', true) !== '';
}

function bk_hizmetler_sayfasi(): int
{
	static $id = null;
	if ($id === null) {
		$p = get_page_by_path('hizmetlerim');
		$id = $p ? (int) $p->ID : 0;
	}
	return $id;
}

/** Bir sayfanın yayımdaki alt sayfaları, sayfa sırasıyla. */
function bk_cocuklar(int $id): array
{
	if (! $id) {
		return [];
	}
	return get_pages(['parent' => $id, 'sort_column' => 'menu_order,post_title', 'post_status' => 'publish']);
}

/** Hizmet ana kategorileri (Hizmetlerim'in alt sayfaları). */
function bk_hizmet_kategorileri(): array
{
	static $k = null;
	if ($k === null) {
		$k = bk_cocuklar(bk_hizmetler_sayfasi());
	}
	return $k;
}

/** Hizmet sayfası mı (Hizmetlerim'in altındaki herhangi bir sayfa)? */
function bk_hizmet_sayfasi_mi($id = null): bool
{
	$id = $id ?: get_queried_object_id();
	$h = bk_hizmetler_sayfasi();
	return $h && $id && $id !== $h && in_array($h, get_post_ancestors($id), true);
}

function bk_logo(bool $ust = false): string
{
	return bk_gorsel('2026/09/Prof.dr_.burcukisa-logo.png', 'medium_large', [
		'alt' => bk_ayar('hekim') . ' – ' . bk_ayar('unvan'),
		'class' => 'bk-logo-img',
		'loading' => $ust ? 'eager' : 'lazy',
		'fetchpriority' => $ust ? 'high' : 'auto',
		'data-no-lazy' => '1',
		'width' => '267',
		'height' => '55',
	]);
}

/* ---------------------------------------------------------------- Kırıntı */

/** Geçerli sayfanın kırıntı adımları: [[ad, url], …]; son adımın url'si boş. */
function bk_kirinti_veri(): array
{
	$a = [['Ana sayfa', bk_url('/')]];
	if (is_front_page()) {
		return [];
	}
	$blog = (int) get_option('page_for_posts');
	$blog_adim = $blog ? ['Blog', get_permalink($blog)] : null;
	if (is_singular('docs')) {
		$a[] = ['Sorular ve Cevaplar', get_post_type_archive_link('docs')];
		$t = get_the_terms(get_queried_object_id(), 'doc_category');
		if ($t && ! is_wp_error($t)) {
			$a[] = [$t[0]->name, get_term_link($t[0])];
		}
		$a[] = [get_the_title(get_queried_object_id()), ''];
	} elseif (is_post_type_archive('docs')) {
		$a[] = ['Sorular ve Cevaplar', ''];
	} elseif (is_tax(['doc_category', 'doc_tag'])) {
		$a[] = ['Sorular ve Cevaplar', get_post_type_archive_link('docs')];
		$a[] = [single_term_title('', false), ''];
	} elseif (is_singular('post')) {
		if ($blog_adim) {
			$a[] = $blog_adim;
		}
		$a[] = [get_the_title(get_queried_object_id()), ''];
	} elseif (is_page()) {
		$id = get_queried_object_id();
		foreach (array_reverse(get_post_ancestors($id)) as $ata) {
			$a[] = [get_the_title($ata), get_permalink($ata)];
		}
		$a[] = [get_the_title($id), ''];
	} elseif (is_home()) {
		$a[] = ['Blog', ''];
	} elseif (is_category() || is_tag()) {
		if ($blog_adim) {
			$a[] = $blog_adim;
		}
		$a[] = [single_term_title('', false), ''];
	} elseif (is_search()) {
		$a[] = ['Arama sonuçları', ''];
	} elseif (is_404()) {
		$a[] = ['Sayfa bulunamadı', ''];
	} else {
		$a[] = [wp_strip_all_tags(get_the_archive_title()), ''];
	}
	return $a;
}

function bk_kirinti(string $sinif = ''): void
{
	$adimlar = bk_kirinti_veri();
	if (! $adimlar) {
		return;
	}
	echo '<nav class="bk-kirinti ' . esc_attr($sinif) . '" aria-label="Sayfa yolu"><ol>';
	foreach ($adimlar as $i => [$ad, $url]) {
		echo '<li>';
		if ($url !== '' && $i < count($adimlar) - 1) {
			echo '<a href="' . esc_url($url) . '">' . esc_html($ad) . '</a>';
		} else {
			echo '<span aria-current="page">' . esc_html($ad) . '</span>';
		}
		echo '</li>';
	}
	echo '</ol></nav>';
}

/* ---------------------------------------------------------------- Başlık */

function bk_ust_menu(): void
{
	$blog = (int) get_option('page_for_posts');
	$hak = get_page_by_path('hakkimda');
	$ilt = get_page_by_path('iletisim-ankara');
	$aktif = function (bool $kosul) {
		return $kosul ? ' class="aktif" aria-current="page"' : '';
	};
	echo '<nav class="bk-menu" aria-label="Ana menü"><ul>';
	echo '<li><a href="' . esc_url(bk_url('/')) . '"' . $aktif(is_front_page()) . '>Ana sayfa</a></li>';
	if ($hak) {
		echo '<li><a href="' . esc_url(get_permalink($hak)) . '"' . $aktif(is_page($hak->ID)) . '>Hakkımda</a></li>';
	}
	$bilgi_aktif = is_home() || is_singular(['post', 'docs']) || is_post_type_archive('docs') || is_tax(['doc_category', 'doc_tag']) || is_category();
	echo '<li class="alt-var"><button type="button" class="bk-menu-ac' . ($bilgi_aktif ? ' aktif' : '') . '" aria-expanded="false">Bilgi ' . bk_ikon('ok') . '</button><ul class="bk-alt">';
	if ($blog) {
		echo '<li><a href="' . esc_url(get_permalink($blog)) . '">Blog</a></li>';
	}
	echo '<li><a href="' . esc_url((string) get_post_type_archive_link('docs')) . '">Sıkça Sorulan Sorular ve Cevapları</a></li>';
	echo '</ul></li>';
	if ($ilt) {
		echo '<li><a href="' . esc_url(get_permalink($ilt)) . '"' . $aktif(is_page($ilt->ID)) . '>İletişim</a></li>';
	}
	echo '</ul></nav>';
}

/** İkinci satır: hizmet kategorileri ve açılır alt hizmetler. */
function bk_hizmet_menusu(): void
{
	$suanki = trailingslashit((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
	echo '<nav class="bk-hmenu" aria-label="Hizmetler"><div class="kap"><ul class="bk-hmenu-liste">';
	foreach (bk_hizmet_menusu_veri() as $i => [$ad, $yol, $sutunlar]) {
		$aktif = str_starts_with($suanki, $yol);
		echo '<li class="alt-var' . ($aktif ? ' aktif' : '') . '">';
		echo '<a class="bk-hm-ust" href="' . esc_url(bk_url($yol)) . '" aria-haspopup="true">' . esc_html($ad) . ' ' . bk_ikon('ok') . '</a>';
		echo '<div class="bk-hm-panel' . (count($sutunlar) > 1 ? ' iki' : '') . '">';
		foreach ($sutunlar as $sutun) {
			echo '<ul>';
			foreach ($sutun as [$sad, $syol]) {
				echo '<li><a href="' . esc_url(bk_url($syol)) . '">' . bk_ikon('ok') . '<span>' . esc_html($sad) . '</span></a></li>';
			}
			echo '</ul>';
		}
		echo '</div></li>';
	}
	echo '</ul></div></nav>';
}

function bk_arama_formu(string $sinif = 'bk-ara', string $yer = 'arama yap...'): void
{
	echo '<form class="' . esc_attr($sinif) . '" role="search" method="get" action="' . esc_url(bk_url('/')) . '">';
	echo '<label class="gizli" for="' . esc_attr($sinif) . '-s">Sitede ara</label>';
	echo '<input id="' . esc_attr($sinif) . '-s" type="search" name="s" placeholder="' . esc_attr($yer) . '" value="' . esc_attr(get_search_query()) . '">';
	echo '<button type="submit">Sor</button></form>';
}

/** Mobil yan menü (off-canvas). */
function bk_mobil_menu(): void
{
	$blog = (int) get_option('page_for_posts');
	$hak = get_page_by_path('hakkimda');
	$ilt = get_page_by_path('iletisim-ankara');
	?>
	<div class="bk-oc" id="bk-oc" aria-hidden="true">
		<div class="bk-oc-arka" data-kapat></div>
		<div class="bk-oc-panel" role="dialog" aria-modal="true" aria-label="Menü">
			<div class="bk-oc-ust">
				<a class="bk-oc-logo" href="<?php echo esc_url(bk_url('/')); ?>"><?php echo bk_logo(); ?></a>
				<button type="button" class="bk-oc-kapat" data-kapat aria-label="Menüyü kapat"><?php echo bk_ikon('kapat'); ?></button>
			</div>
			<div class="bk-oc-btn">
				<a class="bk-btn yesil" href="<?php echo esc_url(bk_ayar('whatsapp_link')); ?>" target="_blank" rel="noopener"><?php echo bk_ikon('wa'); ?> WhatsApp</a>
				<a class="bk-btn koyu" href="<?php echo esc_url(bk_ayar('telefon_link')); ?>"><?php echo bk_ikon('tel'); ?> Arayın</a>
			</div>
			<ul class="bk-oc-linkler">
				<li><a href="<?php echo esc_url(bk_url('/')); ?>">Ana Sayfa</a></li>
				<?php if ($hak) : ?><li><a href="<?php echo esc_url(get_permalink($hak)); ?>">Hakkımda</a></li><?php endif; ?>
				<?php if (bk_hizmetler_sayfasi()) : ?><li><a href="<?php echo esc_url(get_permalink(bk_hizmetler_sayfasi())); ?>">Tüm Hizmetler</a></li><?php endif; ?>
				<?php if ($blog) : ?><li><a href="<?php echo esc_url(get_permalink($blog)); ?>">Blog</a></li><?php endif; ?>
				<?php if ($ilt) : ?><li><a href="<?php echo esc_url(get_permalink($ilt)); ?>">İletişim</a></li><?php endif; ?>
			</ul>
			<p class="bk-oc-etiket">HİZMETLERİMİZ</p>
			<div class="bk-oc-akordiyon">
				<?php foreach (bk_hizmet_kategorileri() as $k) : ?>
					<div class="bk-oc-kat">
						<button type="button" class="bk-oc-kat-ac" aria-expanded="false"><span><?php echo esc_html(get_the_title($k)); ?></span><i aria-hidden="true"></i></button>
						<div class="bk-oc-kat-ic" hidden>
							<a class="genel" href="<?php echo esc_url(get_permalink($k)); ?>">Kategoriye genel bakış →</a>
							<?php foreach (bk_cocuklar($k->ID) as $c) : ?>
								<a href="<?php echo esc_url(bk_sayfa_link($c)); ?>"><?php echo esc_html(get_the_title($c)); ?></a>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<div class="bk-oc-alt">
				<a href="<?php echo esc_url(bk_ayar('telefon_link')); ?>"><?php echo bk_ikon('tel'); ?> <?php echo esc_html(bk_ayar('telefon')); ?></a>
				<a href="<?php echo esc_url(bk_ayar('yol_tarifi')); ?>" target="_blank" rel="noopener">Yol tarifi al ↗</a>
				<a href="<?php echo esc_url(bk_ayar('instagram')); ?>" target="_blank" rel="noopener">Instagram ↗</a>
			</div>
		</div>
	</div>
	<?php
}

/* ---------------------------------------------------------------- Kartlar */

/** Hizmet kartı (ana sayfa, Hizmetlerim, Diğer Hizmetlerim, kategori içi liste). */
function bk_hizmet_karti(int $id, string $baslik_etiket = 'h3'): string
{
	$baslik = (string) get_post_meta($id, 'kart_basligi', true);
	if ($baslik === '') {
		$baslik = get_the_title($id);
	}
	$alt = (string) get_post_meta($id, 'kart_alt_metin', true);
	$url = bk_sayfa_link($id);
	$h = '<a class="bk-hkart" href="' . esc_url($url) . '">';
	$thumb = get_post_thumbnail_id($id);
	if ($thumb) {
		$h .= wp_get_attachment_image($thumb, 'medium_large', false, ['class' => 'bk-hkart-img', 'alt' => $baslik, 'sizes' => '(max-width: 767px) 100vw, 290px']);
	}
	$h .= '<span class="bk-hkart-ic"><' . $baslik_etiket . ' class="bk-hkart-baslik">' . esc_html($baslik) . '</' . $baslik_etiket . '>';
	if ($alt !== '') {
		$h .= '<span class="bk-hkart-alt">' . esc_html($alt) . '</span>';
	}
	$h .= '<span class="bk-hkart-btn">Detaylı Bilgi</span></span></a>';
	return $h;
}

/** Blog kartı. */
function bk_blog_karti($p): string
{
	$p = get_post($p);
	$url = get_permalink($p);
	$h = '<article class="bk-bkart"><a class="bk-bkart-gorsel" href="' . esc_url($url) . '" tabindex="-1" aria-hidden="true">';
	if (has_post_thumbnail($p)) {
		$h .= get_the_post_thumbnail($p, 'medium_large', ['alt' => get_the_title($p), 'sizes' => '(max-width: 767px) 100vw, 280px']);
	}
	$h .= '</a><div class="bk-bkart-ic"><h3><a href="' . esc_url($url) . '">' . esc_html(get_the_title($p)) . '</a></h3>';
	$h .= '<time datetime="' . esc_attr(get_the_date('c', $p)) . '">' . esc_html(get_the_date('j F Y', $p)) . '</time>';
	$h .= '<p>' . esc_html(wp_trim_words(get_the_excerpt($p), 14, '…')) . '</p>';
	$h .= '<a class="bk-bkart-oku" href="' . esc_url($url) . '" aria-label="' . esc_attr(get_the_title($p) . ' yazısını oku') . '">Oku</a></div></article>';
	return $h;
}

/** Kaydırmalı şerit (carousel): oklar + yatay kaydırma. */
function bk_serit(string $ic, string $sinif = '', string $etiket = 'Kaydır'): string
{
	return '<div class="bk-serit ' . esc_attr($sinif) . '" data-serit>'
		. '<button type="button" class="bk-serit-ok sol" aria-label="Önceki">' . bk_ikon('sol') . '</button>'
		. '<div class="bk-serit-yol" tabindex="0" aria-label="' . esc_attr($etiket) . '">' . $ic . '</div>'
		. '<button type="button" class="bk-serit-ok sag" aria-label="Sonraki">' . bk_ikon('sag') . '</button></div>';
}

/* ---------------------------------------------------------------- Yan sütun */

function bk_yildizlar(): string
{
	return '<span class="bk-yildiz" role="img" aria-label="5 üzerinden 5 yıldız">' . str_repeat(bk_ikon('yildiz'), 5) . '</span>';
}

/** Hizmet sayfası üstündeki güven kartı ve içerik ortasındaki randevu kartı. */
function bk_guven_karti(): string
{
	$h = '<div class="bk-guven">';
	$h .= '<div class="bk-guven-ust">' . bk_gorsel('2026/11/WhatsApp-Image-2026-10-28-at-10.13.56.jpeg', 'thumbnail', ['alt' => bk_ayar('hekim'), 'class' => 'bk-guven-foto', 'width' => '68', 'height' => '68']);
	$h .= '<div><p class="bk-guven-ad">' . esc_html(bk_ayar('hekim')) . '</p><p class="bk-guven-unvan">Kadın Hastalıkları ve Doğum Uzmanı · Maidan İş Merkezi C Blok, Çankaya</p>';
	$h .= '<p class="bk-guven-puan">' . bk_yildizlar() . ' <span>' . esc_html(bk_yorum_sayisi()) . ' Google yorumu</span></p></div></div>';
	$h .= bk_ara_wa_butonlari();
	return $h . '</div>';
}

function bk_ara_wa_butonlari(): string
{
	return '<div class="bk-ikili"><a class="bk-btn siyah" href="' . esc_url(bk_ayar('telefon_link')) . '">' . bk_ikon('tel') . ' Hemen Ara</a>'
		. '<a class="bk-btn yesil" href="' . esc_url(bk_ayar('whatsapp_link')) . '" target="_blank" rel="noopener">' . bk_ikon('wa') . ' WhatsApp</a></div>';
}

function bk_orta_kart(): string
{
	return '<aside class="bk-guven bk-orta-kart" aria-label="Randevu"><p class="bk-orta-baslik">Muayene için randevu alın</p>'
		. '<p class="bk-orta-metin">' . esc_html(bk_ayar('hekim')) . ' ile Maidan İş Merkezi C Blok, Çankaya\'da. Pazartesi–Cumartesi 08:00–20:00, randevu ile.</p>'
		. bk_ara_wa_butonlari() . '</aside>';
}

/** Hekim kartı (yan sütun). $tur: hizmet (kemerli fotoğraf) | soru (yatay fotoğraf, randevu düğmesi). */
function bk_hekim_karti(string $tur = 'hizmet'): void
{
	echo '<div class="bk-kutu bk-hekim bk-hekim-' . esc_attr($tur) . '">';
	if ($tur === 'soru') {
		echo bk_gorsel('2026/09/prof-dr-burcu-kisa-1.png', 'medium_large', ['alt' => bk_ayar('hekim'), 'class' => 'bk-hekim-foto']);
	} else {
		echo bk_gorsel('2026/11/WhatsApp-Image-2026-10-28-at-10.14.09.jpeg', 'medium_large', ['alt' => bk_ayar('hekim'), 'class' => 'bk-hekim-foto']);
	}
	echo '<p class="bk-hekim-ad">Prof.Dr. Burcu Kısa</p><p class="bk-hekim-unvan">KADIN HASTALIKLARI VE<br>DOĞUM UZMANI</p><div class="bk-hekim-btn">';
	if ($tur === 'soru') {
		echo '<a class="bk-btn cizgi" href="' . esc_url(bk_url('/randevu-olustur/')) . '">Randevu Oluştur</a>';
	} else {
		echo '<a class="bk-btn altin" href="' . esc_url(bk_url('/hakkimda/')) . '">Hakkımda</a>';
		echo '<a class="bk-btn yesil" href="' . esc_url(bk_ayar('whatsapp_link')) . '" target="_blank" rel="noopener">' . bk_ikon('wa') . ' WhatsApp</a>';
	}
	echo '</div></div>';
}

/** İletişim bilgileri kutusu (yan sütun). */
function bk_iletisim_kutusu(string $baslik = 'Prof. Dr. Burcu Kısa - Kadın Hastalıkları Ve Doğum Uzmanı Ankara', bool $galeri = true, bool $saat = true): void
{
	echo '<div class="bk-kutu bk-iletisim"><p class="bk-kutu-baslik">İletişim Bilgileri</p>';
	if ($galeri) {
		echo '<div class="bk-mini-galeri">';
		foreach (['IMG_5331', 'IMG_5330', 'IMG_5325', 'IMG_5329'] as $g) {
			echo bk_gorsel('2026/09/' . $g . '-scaled.jpg', 'medium', ['alt' => 'Prof. Dr. Burcu Kısa kliniği, Maidan İş Merkezi']);
		}
		echo '</div><p class="bk-iletisim-puan">' . bk_yildizlar() . ' <span>' . esc_html(bk_yorum_sayisi()) . ' Yorum</span></p>';
	}
	echo '<p class="bk-iletisim-baslik">' . esc_html($baslik) . '</p><p class="bk-iletisim-satir">';
	echo '<b>Adres :</b> <a href="' . esc_url(bk_ayar('harita')) . '" target="_blank" rel="noopener">' . esc_html(bk_ayar('adres_kisa')) . '</a><br>';
	echo '<b>Telefon :</b> <a href="' . esc_url(bk_ayar('telefon_link')) . '">' . esc_html(bk_ayar('telefon')) . '</a><br>';
	echo '<b>WhatsApp:</b> <a href="' . esc_url(bk_ayar('whatsapp_link')) . '" target="_blank" rel="noopener">' . esc_html(bk_ayar('whatsapp')) . '</a>';
	if ($saat) {
		echo '<br><b>Çalışma Saatleri:</b> <a href="' . esc_url(bk_url('/randevu-olustur/')) . '">' . esc_html(bk_ayar('saatler')) . '</a>';
	}
	echo '</p></div>';
}

/** Sayfa başı bandı (Hakkımda, İletişim, Blog, Hizmetlerim, Sorular). */
function bk_sayfa_bandi(string $baslik, string $gorsel = '2026/09/Gebelik-Takibine-Ne-Zaman-Baslanmali.jpeg', string $sinif = '', bool $cizgi = false): void
{
	$url = bk_gorsel_url($gorsel, 'full');
	echo '<header class="bk-bant ' . esc_attr($sinif) . '" style="background-image:url(' . esc_url($url) . ')"><div class="kap">';
	bk_kirinti();
	echo '<h1>' . esc_html($baslik) . '</h1>';
	if ($cizgi) {
		echo '<span class="bk-bant-cizgi" aria-hidden="true"></span>';
	}
	echo '</div></header>';
}

/* ---------------------------------------------------------------- Alt bilgi */

/** Alt bilgideki hizmet listesi: eski sitedeki dört sütun düzeni. */
function bk_alt_hizmetler(): void
{
	$kat = [];
	foreach (bk_hizmet_kategorileri() as $k) {
		$kat[$k->post_name] = $k;
	}
	$sutunlar = [
		['gebelik-ve-dogum', 'menopoz-ve-ileri-yas-kadin-sagligi'],
		['genital-estetik-uygulamalari', 'urojinekoloji-ve-pelvik-taban'],
		['jinekolojik-cerrahi', 'kisirlik-ve-tup-bebek-tedavileri'],
		['kadin-hastaliklari-ve-cinsel-saglik'],
	];
	$kullanilan = array_merge(...$sutunlar);
	foreach (array_keys($kat) as $slug) {
		if (! in_array($slug, $kullanilan, true)) {
			$sutunlar[3][] = $slug;
		}
	}
	echo '<div class="bk-alt-hizmet-izgara">';
	foreach ($sutunlar as $sutun) {
		echo '<div class="bk-alt-sutun">';
		foreach ($sutun as $slug) {
			if (empty($kat[$slug])) {
				continue;
			}
			$k = $kat[$slug];
			echo '<div class="bk-alt-kat"><h3><a href="' . esc_url(get_permalink($k)) . '">' . esc_html(get_the_title($k)) . '</a></h3><ul>';
			foreach (bk_cocuklar($k->ID) as $c) {
				echo '<li><a href="' . esc_url(bk_sayfa_link($c)) . '">' . esc_html(get_the_title($c)) . '</a></li>';
			}
			echo '</ul></div>';
		}
		echo '</div>';
	}
	echo '</div>';
}
