<?php
/**
 * Site bilgileri: önce Kurumsal Detaylar (ACF seçenekleri), boşsa buradaki varsayılan.
 */
if (! defined('ABSPATH')) {
	exit;
}

function bk_varsayilan(): array
{
	return [
		'hekim' => 'Prof. Dr. Burcu Kısa',
		'unvan' => 'Kadın Hastalıkları ve Doğum Uzmanı',
		'telefon' => '+90 533 687 78 53',
		'telefon_link' => 'tel:+905336877853',
		'whatsapp' => '+90 533 687 78 53',
		'whatsapp_link' => 'https://wa.me/905336877853',
		'eposta' => 'info@burcukisa.com',
		'instagram' => 'https://www.instagram.com/prof.dr.burcu_kisa/',
		'adres_kisa' => 'Maidan C Blok, 10. Kat, No:115, Mustafa Kemal, 2118. Cd. No:4, 06510 Çankaya/Ankara',
		'adres_satir1' => 'Mustafa Kemal Mahallesi, Maidan C Blok',
		'adres_satir2' => '10. Kat, No:115 · Çankaya / Ankara',
		'sokak' => '2118. Cadde No:4, Maidan İş Merkezi C Blok, Kat 10, No:115, Mustafa Kemal Mahallesi',
		'ilce' => 'Çankaya',
		'il' => 'Ankara',
		'posta_kodu' => '06510',
		'enlem' => '39.9097427',
		'boylam' => '32.7650654',
		'saatler' => 'Pazartesi – Cumartesi 08:00 – 20:00',
		'saat_gun' => 'Pazartesi – Cumartesi',
		'saat_aralik' => '08:00 – 20:00',
		'harita' => 'https://www.google.com/maps/place//data=!4m2!3m1!1s0x14d337eb181f3a31:0x69f626d0a8928c4c?sa=X&ved=1t:8290&ictx=111',
		'yol_tarifi' => 'https://www.google.com/maps/dir/?api=1&destination=39.9097427%2C32.7650654',
		'gtm' => 'GTM-WWSCKTDV',
	];
}

/** Tek bilgi. ACF alan adları eski siteyle aynıdır (Kurumsal Detaylar). */
function bk_ayar(string $anahtar): string
{
	static $cache = [];
	if (isset($cache[$anahtar])) {
		return $cache[$anahtar];
	}
	$acf = [
		'telefon' => 'telefon_no',
		'telefon_link' => 'telefon_tikla_ara',
		'whatsapp' => 'whatsapp_no',
		'whatsapp_link' => 'whatsapp_tikla_yaz',
		'eposta' => 'e-posta_adresi',
		'instagram' => 'instagram_adresi',
	];
	$deger = '';
	if (isset($acf[$anahtar])) {
		$deger = (string) get_option('options_' . $acf[$anahtar], '');
	}
	if ($deger === '' || $deger === '#') {
		$deger = bk_varsayilan()[$anahtar] ?? '';
	}
	return $cache[$anahtar] = $deger;
}

/** Medya adresinden (uploads/… yolu) görsel etiketi: srcset ve tembel yükleme WordPress'ten. */
function bk_gorsel(string $yol, string $boyut = 'large', array $ozellik = []): string
{
	static $idler = null;
	if ($idler === null) {
		$idler = get_option('bk_medya_idleri', []);
		if (! is_array($idler)) {
			$idler = [];
		}
	}
	$id = $idler[$yol] ?? null;
	if ($id === null) {
		$up = wp_get_upload_dir();
		$id = (int) attachment_url_to_postid(trailingslashit($up['baseurl']) . $yol);
		$idler[$yol] = $id;
		update_option('bk_medya_idleri', $idler, false);
	}
	if ($id) {
		// Genişlik/yükseklik WordPress'ten gelir; ikinci kez yazılmasın.
		unset($ozellik['width'], $ozellik['height']);
		return wp_get_attachment_image($id, $boyut, false, $ozellik);
	}
	$up = wp_get_upload_dir();
	$attr = '';
	foreach ($ozellik + ['loading' => 'lazy', 'decoding' => 'async'] as $k => $v) {
		$attr .= ' ' . $k . '="' . esc_attr($v) . '"';
	}
	return '<img src="' . esc_url(trailingslashit($up['baseurl']) . $yol) . '"' . $attr . '>';
}

function bk_gorsel_url(string $yol, string $boyut = 'large'): string
{
	$up = wp_get_upload_dir();
	$tam = trailingslashit($up['baseurl']) . $yol;
	$id = (int) attachment_url_to_postid($tam);
	if ($id) {
		$src = wp_get_attachment_image_src($id, $boyut);
		if ($src) {
			return $src[0];
		}
	}
	return $tam;
}

/** Simgeler (SVG, satır içi). */
function bk_ikon(string $ad): string
{
	$i = [
		'wa' => '<svg viewBox="0 0 448 512" aria-hidden="true"><path fill="currentColor" d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>',
		'tel' => '<svg viewBox="0 0 512 512" aria-hidden="true"><path fill="currentColor" d="M497.39 361.8l-112-48a24 24 0 0 0-28 6.9l-49.6 60.6A370.66 370.66 0 0 1 130.6 204.11l60.6-49.6a23.94 23.94 0 0 0 6.9-28l-48-112A24.16 24.16 0 0 0 122.6.61l-104 24A24 24 0 0 0 0 48c0 256.5 207.9 464 464 464a24 24 0 0 0 23.4-18.6l24-104a24.29 24.29 0 0 0-14.01-27.6z"/></svg>',
		'ok' => '<svg viewBox="0 0 320 512" aria-hidden="true"><path fill="currentColor" d="M31.3 192h257.3c17.8 0 26.7 21.5 14.1 34.1L174.1 354.8c-7.8 7.8-20.5 7.8-28.3 0L17.2 226.1C4.6 213.5 13.5 192 31.3 192z"/></svg>',
		'yildiz' => '<svg viewBox="0 0 576 512" aria-hidden="true"><path fill="currentColor" d="M259.3 17.8L194 150.2 47.9 171.5c-26.2 3.8-36.7 36.1-17.7 54.6l105.7 103-25 145.5c-4.5 26.3 23.2 46 46.4 33.7L288 439.6l130.7 68.7c23.2 12.2 50.9-7.4 46.4-33.7l-25-145.5 105.7-103c19-18.5 8.5-50.8-17.7-54.6L382 150.2 316.7 17.8c-11.7-23.6-45.6-23.9-57.4 0z"/></svg>',
		'kisi' => '<svg viewBox="0 0 640 512" aria-hidden="true"><path fill="currentColor" d="M224 256A128 128 0 1 0 224 0a128 128 0 1 0 0 256zm-45.7 48C79.8 304 0 383.8 0 482.3 0 498.7 13.3 512 29.7 512h293.1c-3.1-8.8-3.7-18.4-1.4-27.8l15-60.1c2.8-11.3 8.6-21.5 16.8-29.7l40.3-40.3c-32.1-31-75.7-50.1-123.9-50.1h-91.4zm435.5-68.3c-15.6-15.6-40.9-15.6-56.6 0l-29.4 29.4 71 71 29.4-29.4c15.6-15.6 15.6-40.9 0-56.6l-14.4-14.4zM375.9 417c-4.1 4.1-7 9.2-8.4 14.9l-15 60.1c-1.4 5.5.2 11.2 4.2 15.2s9.7 5.6 15.2 4.2l60.1-15c5.6-1.4 10.8-4.3 14.9-8.4l129.2-129.3-71-71L375.9 417z"/></svg>',
		'takvim' => '<svg viewBox="0 0 448 512" aria-hidden="true"><path fill="currentColor" d="M12 192h424c6.6 0 12 5.4 12 12v260c0 26.5-21.5 48-48 48H48c-26.5 0-48-21.5-48-48V204c0-6.6 5.4-12 12-12zm436-44v-36c0-26.5-21.5-48-48-48h-48V12c0-6.6-5.4-12-12-12h-40c-6.6 0-12 5.4-12 12v52H160V12c0-6.6-5.4-12-12-12h-40c-6.6 0-12 5.4-12 12v52H48C21.5 64 0 85.5 0 112v36c0 6.6 5.4 12 12 12h424c6.6 0 12-5.4 12-12z"/></svg>',
		'menu' => '<svg viewBox="0 0 448 512" aria-hidden="true"><path fill="currentColor" d="M16 132h416c8.8 0 16-7.2 16-16V76c0-8.8-7.2-16-16-16H16C7.2 60 0 67.2 0 76v40c0 8.8 7.2 16 16 16zm0 160h416c8.8 0 16-7.2 16-16v-40c0-8.8-7.2-16-16-16H16c-8.8 0-16 7.2-16 16v40c0 8.8 7.2 16 16 16zm0 160h416c8.8 0 16-7.2 16-16v-40c0-8.8-7.2-16-16-16H16c-8.8 0-16 7.2-16 16v40c0 8.8 7.2 16 16 16z"/></svg>',
		'kapat' => '<svg viewBox="0 0 512 512" aria-hidden="true"><path fill="currentColor" d="M256 8C119 8 8 119 8 256s111 248 248 248 248-111 248-248S393 8 256 8zm121.6 313.1c4.7 4.7 4.7 12.3 0 17L338 377.6c-4.7 4.7-12.3 4.7-17 0L256 312l-65.1 65.6c-4.7 4.7-12.3 4.7-17 0L134.4 338c-4.7-4.7-4.7-12.3 0-17l65.6-65-65.6-65.1c-4.7-4.7-4.7-12.3 0-17l39.6-39.6c4.7-4.7 12.3-4.7 17 0l65 65.7 65.1-65.6c4.7-4.7 12.3-4.7 17 0l39.6 39.6c4.7 4.7 4.7 12.3 0 17L312 256l65.6 65.1z"/></svg>',
		'sol' => '<svg viewBox="0 0 320 512" aria-hidden="true"><path fill="currentColor" d="M34.52 239.03L228.87 44.69c9.37-9.37 24.57-9.37 33.94 0l22.67 22.67c9.36 9.36 9.37 24.52.04 33.9L131.49 256l154.02 154.75c9.34 9.38 9.32 24.54-.04 33.9l-22.67 22.67c-9.37 9.37-24.57 9.37-33.94 0L34.52 272.97c-9.37-9.37-9.37-24.57 0-33.94z"/></svg>',
		'sag' => '<svg viewBox="0 0 320 512" aria-hidden="true"><path fill="currentColor" d="M285.476 272.971L91.132 467.314c-9.373 9.373-24.569 9.373-33.941 0l-22.667-22.667c-9.357-9.357-9.375-24.522-.04-33.901L188.505 256 34.484 101.255c-9.335-9.379-9.317-24.544.04-33.901l22.667-22.667c9.373-9.373 24.569-9.373 33.941 0L285.475 239.03c9.373 9.372 9.373 24.568.001 33.941z"/></svg>',
		'arti' => '<svg viewBox="0 0 448 512" aria-hidden="true"><path fill="currentColor" d="M416 208H272V64c0-17.67-14.33-32-32-32h-32c-17.67 0-32 14.33-32 32v144H32c-17.67 0-32 14.33-32 32v32c0 17.670 14.33 32 32 32h144v144c0 17.67 14.33 32 32 32h32c17.67 0 32-14.33 32-32V304h144c17.67 0 32-14.33 32-32v-32c0-17.67-14.33-32-32-32z"/></svg>',
		'kitap' => '<svg viewBox="0 0 448 512" aria-hidden="true"><path fill="currentColor" d="M448 360V24c0-13.3-10.7-24-24-24H96C43 0 0 43 0 96v320c0 53 43 96 96 96h328c13.3 0 24-10.7 24-24v-16c0-7.5-3.5-14.3-8.9-18.7-4.2-15.4-4.2-59.3 0-74.7 5.4-4.3 8.9-11.1 8.9-18.6zM128 134c0-3.3 2.7-6 6-6h212c3.3 0 6 2.7 6 6v20c0 3.3-2.7 6-6 6H134c-3.3 0-6-2.7-6-6v-20zm0 64c0-3.3 2.7-6 6-6h212c3.3 0 6 2.7 6 6v20c0 3.3-2.7 6-6 6H134c-3.3 0-6-2.7-6-6v-20zm253.4 250H96c-17.7 0-32-14.3-32-32 0-17.6 14.4-32 32-32h285.4c-1.9 17.1-1.9 46.9 0 64z"/></svg>',
		'ara' => '<svg viewBox="0 0 512 512" aria-hidden="true"><path fill="currentColor" d="M505 442.7L405.3 343c-4.5-4.5-10.6-7-17-7H372c27.6-35.3 44-79.7 44-128C416 93.1 322.9 0 208 0S0 93.1 0 208s93.1 208 208 208c48.3 0 92.7-16.4 128-44v16.3c0 6.4 2.5 12.5 7 17l99.7 99.7c9.4 9.4 24.6 9.4 33.9 0l28.3-28.3c9.4-9.4 9.4-24.6.1-34zM208 336c-70.7 0-128-57.2-128-128 0-70.7 57.2-128 128-128 70.7 0 128 57.2 128 128 0 70.7-57.2 128-128 128z"/></svg>',
	];
	return '<span class="ik ik-' . esc_attr($ad) . '">' . ($i[$ad] ?? '') . '</span>';
}

/** Google yorum sayısı ve puanı: MoxDOP her gün siteye gönderir (eski temadaki kısa kodların karşılığı). */
function bk_yorum_veri(): array
{
	$d = get_option('moxdop_showcase_reviews', []);
	return is_array($d) ? $d : [];
}
add_action('init', function () {
	if (! shortcode_exists('moxdop_yorum_sayisi')) {
		add_shortcode('moxdop_yorum_sayisi', function ($atts) {
			$atts = shortcode_atts(['varsayilan' => ''], $atts);
			$n = (int) (bk_yorum_veri()['count'] ?? 0);
			return $n > 0 ? esc_html(number_format_i18n($n)) : esc_html($atts['varsayilan']);
		});
	}
	if (! shortcode_exists('moxdop_google_puan')) {
		add_shortcode('moxdop_google_puan', function ($atts) {
			$atts = shortcode_atts(['varsayilan' => ''], $atts);
			$r = (float) (bk_yorum_veri()['rating'] ?? 0);
			return $r > 0 ? esc_html(number_format_i18n($r, 1)) : esc_html($atts['varsayilan']);
		});
	}
}, 20);

function bk_yorum_sayisi(): string
{
	$n = (int) (bk_yorum_veri()['count'] ?? 0);
	return $n > 0 ? number_format_i18n($n) : '111';
}
