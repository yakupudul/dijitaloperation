<?php
/**
 * Site bilgileri: telefon, WhatsApp, adres, sosyal hesaplar, sayaçlar, eğitimler, köşe videosu.
 * Yönetim: wp-admin > Site bilgileri. Temadaki {{TEL}} gibi işaretler bu ayarlardan doldurulur.
 */
if (! defined('ABSPATH')) {
	exit;
}

/** Varsayılanlar (tema.py üretir). */
function mxp_varsayilan(): array
{
	static $v = null;
	if ($v === null) {
		$v = require MXP_DIR . '/inc/varsayilan.php';
	}
	return $v;
}

/** Ayarlar, boş alanlar varsayılanla dolu. */
function mxp_ayar(?string $key = null)
{
	static $a = null;
	if ($a === null) {
		$a = mxp_varsayilan()['ayar'];
		$saved = get_option('mxp_ayar', []);
		if (is_array($saved)) {
			foreach ($saved as $k => $v) {
				if (array_key_exists($k, $a) && (is_int($v) || trim((string) $v) !== '')) {
					$a[$k] = $v;
				}
			}
		}
	}
	return $key === null ? $a : ($a[$key] ?? '');
}

/** "0332 265 35 43" → "+903322653543". */
function mxp_e164(string $tel): string
{
	$d = preg_replace('/\D+/', '', $tel);
	if ($d === '') {
		return '';
	}
	if (str_starts_with($d, '00')) {
		$d = substr($d, 2);
	} elseif (str_starts_with($d, '0')) {
		$d = '90' . substr($d, 1);
	} elseif (strlen($d) === 10) {
		$d = '90' . $d;
	}
	return '+' . $d;
}

/** Düz metin işaretleri: {{TEL}}, {{WA}} ... (ham değer; kaçış mxp_fill'de). */
function mxp_tokenler(): array
{
	static $t = null;
	if ($t !== null) {
		/* WhatsApp bağlantısı sayfaya göre değişir, her seferinde kurulur. */
		$t['WA'] = mxp_wa_url(mxp_wa_mesaj());
		return $t;
	}
	$a = mxp_ayar();
	$tele = mxp_e164((string) $a['tel']);
	$wa = ltrim(mxp_e164((string) $a['wa']), '+');
	$t = [
		'TEL' => (string) $a['tel'],
		'TELE' => $tele,
		'TELL' => 'tel:' . $tele,
		'WA_NO' => $wa,
		'WA' => '',
		'SOKAK' => (string) $a['sokak'],
		'ILCE' => (string) $a['ilce'],
		'IL' => (string) $a['il'],
		'MAPS' => (string) $a['maps'],
		'MAIL' => (string) $a['mail'],
		'IG' => untrailingslashit((string) $a['ig']),
		'IG2' => untrailingslashit((string) $a['ig2']),
		'YT' => untrailingslashit((string) $a['yt']),
		'TT' => untrailingslashit((string) $a['tt']),
		'FOOT' => (string) $a['alt_metin'],
		'YIL' => wp_date('Y'),
	];
	$t['WA'] = mxp_wa_url(mxp_wa_mesaj());
	return $t;
}

/* ------------------------------------------------------------------ sayfaya özel WhatsApp mesajı */

const MXP_WA_GENEL = 'Merhaba, görüşme hakkında bilgi almak istiyorum.';

function mxp_wa_url(string $mesaj = ''): string
{
	$no = ltrim(mxp_e164((string) mxp_ayar('wa')), '+');
	return 'https://wa.me/' . $no . ($mesaj !== '' ? '?text=' . rawurlencode($mesaj) : '');
}

/** Bulunulan sayfanın hazır mesajı: sayfada yazılan, yoksa temadaki varsayılan. */
function mxp_wa_mesaj(): string
{
	if (is_admin() || ! did_action('wp')) {
		return MXP_WA_GENEL;
	}
	if (is_singular('video')) {
		return sprintf('Merhaba, sitenizde "%s" videosunu izledim. Görüşme hakkında bilgi almak istiyorum.', html_entity_decode(get_the_title(get_queried_object_id()), ENT_QUOTES));
	}
	if (is_singular('post')) {
		return sprintf('Merhaba, sitenizde "%s" yazısını okudum. Görüşme hakkında bilgi almak istiyorum.', html_entity_decode(get_the_title(get_queried_object_id()), ENT_QUOTES));
	}
	$key = mxp_sayfa_anahtar();
	if ($key === null) {
		return MXP_WA_GENEL;
	}
	$id = mxp_sayfa_id();
	$m = $id ? trim((string) get_post_meta($id, '_mxp_wa', true)) : '';
	if ($m !== '') {
		return $m;
	}
	$def = (string) (mxp_pages()[$key]['wa'] ?? '');
	return $def !== '' ? $def : MXP_WA_GENEL;
}

/** {{WAM:kurumsal}}: başka bir sayfanın hazır mesajıyla WhatsApp bağlantısı. */
function mxp_slot_wam(string $slug): string
{
	$def = (string) (mxp_pages()[$slug]['wa'] ?? '');
	return esc_url(mxp_wa_url($def !== '' ? $def : MXP_WA_GENEL));
}

/* ------------------------------------------------------------------ çalışma saatleri */

/** "Pazartesi-Cuma | 09:00-19:00" satırları. */
function mxp_saatler(): array
{
	return array_values(array_filter(mxp_satirlar((string) mxp_ayar('saatler')), static fn ($r) => $r[0] !== ''));
}

function mxp_saat_metin(array $r): string
{
	return $r[1] !== '' ? $r[0] . ': ' . $r[1] : $r[0];
}

/** İletişim sayfasındaki satır. */
function mxp_slot_saat_c(): string
{
	$rows = mxp_saatler();
	if (! $rows) {
		return '';
	}
	return '<li>' . mxp_ico('i-calendar') . '<span><b>Çalışma saatleri</b>' . implode('<br>', array_map(static fn ($r) => esc_html(mxp_saat_metin($r)), $rows)) . '</span></li>';
}

/** Alt bilgideki satır. */
function mxp_slot_saat_f(): string
{
	return implode('', array_map(static fn ($r) => '<li>' . esc_html(mxp_saat_metin($r)) . '</li>', mxp_saatler()));
}

/** openingHoursSpecification (yalnız saat yazılı satırlar; "Kapalı" satırları atlanır). */
function mxp_saat_ld(): array
{
	$gun = ['pazartesi' => 'Monday', 'salı' => 'Tuesday', 'çarşamba' => 'Wednesday', 'perşembe' => 'Thursday', 'cuma' => 'Friday', 'cumartesi' => 'Saturday', 'pazar' => 'Sunday'];
	$sira = array_keys($gun);
	$out = [];
	foreach (mxp_saatler() as [$d, $h]) {
		if (! preg_match('/(\d{1,2})[:.](\d{2})\s*[-–]\s*(\d{1,2})[:.](\d{2})/u', $h, $m)) {
			continue;
		}
		$days = [];
		foreach (preg_split('/\s*,\s*/u', mb_strtolower(trim($d))) as $part) {
			$ab = preg_split('/\s*[-–]\s*/u', trim($part));
			$a = array_search(trim($ab[0]), $sira, true);
			$b = isset($ab[1]) ? array_search(trim($ab[1]), $sira, true) : $a;
			if ($a === false || $b === false) {
				continue;
			}
			for ($i = $a; $i <= $b; $i++) {
				$days[] = $gun[$sira[$i]];
			}
		}
		if ($days) {
			$out[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => array_values(array_unique($days)),
				'opens' => sprintf('%02d:%s', $m[1], $m[2]), 'closes' => sprintf('%02d:%s', $m[3], $m[4])];
		}
	}
	return $out;
}

/** Varsayılan metinlerdeki {{TEL}} gibi işaretleri ham değerle doldurur. */
function mxp_tok_text(string $s): string
{
	if (! str_contains($s, '{{')) {
		return $s;
	}
	$map = [];
	foreach (mxp_tokenler() as $k => $v) {
		$map['{{' . $k . '}}'] = $v;
	}
	return strtr($s, $map);
}

/** "değer | açıklama" satırları. */
function mxp_satirlar(string $text): array
{
	$out = [];
	foreach (preg_split('/\r?\n/', $text) as $line) {
		$line = trim($line);
		if ($line === '') {
			continue;
		}
		$parts = array_map('trim', explode('|', $line, 2));
		$out[] = [$parts[0], $parts[1] ?? ''];
	}
	return $out;
}

function mxp_ico(string $name): string
{
	return '<svg class="ico"><use href="#' . esc_attr($name) . '"/></svg>';
}

/** Sayaçlar. */
function mxp_slot_nums(): string
{
	$h = '';
	foreach (mxp_satirlar((string) mxp_ayar('sayac')) as [$v, $l]) {
		$h .= '<div><b>' . esc_html($v) . '</b><span>' . esc_html($l) . '</span></div>';
	}
	return $h === '' ? '' : '<div class="nums">' . $h . '</div>';
}

/** Eğitim listesi, "## Grup" başlıklarıyla. */
function mxp_slot_edu(): string
{
	$groups = [];
	$cur = -1;
	foreach (preg_split('/\r?\n/', (string) mxp_ayar('egitim')) as $line) {
		$line = trim($line);
		if ($line === '') {
			continue;
		}
		if (str_starts_with($line, '#')) {
			$groups[] = [trim(ltrim($line, '#')), []];
			$cur++;
			continue;
		}
		if ($cur < 0) {
			$groups[] = ['', []];
			$cur = 0;
		}
		$groups[$cur][1][] = $line;
	}
	$h = '';
	foreach ($groups as [$t, $items]) {
		$h .= '<div class="edu-g">' . ($t !== '' ? '<h3>' . esc_html($t) . '</h3>' : '') . '<ul>';
		foreach ($items as $i) {
			$h .= '<li>' . mxp_ico('i-check') . esc_html($i) . '</li>';
		}
		$h .= '</ul></div>';
	}
	return $h;
}

function mxp_slot_edu_kisa(): string
{
	$h = '';
	foreach (preg_split('/\r?\n/', (string) mxp_ayar('egitim_kisa')) as $line) {
		$line = trim($line);
		if ($line !== '') {
			$h .= '<li>' . mxp_ico('i-check') . esc_html($line) . '</li>';
		}
	}
	return $h;
}

function mxp_slot_prn(): string
{
	$h = '';
	$n = 0;
	foreach (mxp_satirlar((string) mxp_ayar('ilkeler')) as [$t, $d]) {
		$n++;
		$h .= sprintf('<div class="prn"><b>%02d</b><h3>%s</h3><p>%s</p></div>', $n, esc_html($t), esc_html($d));
	}
	return $h;
}

/** Ana sayfadaki son yazılar. */
function mxp_slot_blog(): string
{
	$h = '';
	foreach (get_posts(['post_type' => 'post', 'posts_per_page' => 6, 'no_found_rows' => true]) as $p) {
		$slug = $p->post_name;
		if (is_file(MXP_DIR . '/assets/img/sq_' . $slug . '.webp')) {
			$img = MXP_URI . '/assets/img/sq_' . $slug . '.webp';
		} else {
			$img = get_the_post_thumbnail_url($p, 'thumbnail') ?: MXP_URI . '/assets/img/ali-avatar.webp';
		}
		$h .= sprintf(
			'<a class="pt" href="%s"><img src="%s" alt="" loading="lazy" width="64" height="64"><span>%s</span>%s</a>',
			esc_url(get_permalink($p)),
			esc_url($img),
			esc_html(get_the_title($p)),
			mxp_ico('i-arrow')
		);
	}
	return $h;
}

/* ------------------------------------------------------------------ yönetim ekranı */

function mxp_ayar_alanlari(): array
{
	return [
		'İletişim' => [
			'tel' => ['Telefon', 'text', 'Sitede yazdığı gibi, ör. 0332 265 35 43. Arama bağlantısı bundan kurulur.'],
			'wa' => ['WhatsApp numarası', 'text', 'Ör. 0532 000 00 00. WhatsApp düğmeleri bu numaraya gider.'],
			'sokak' => ['Adres (mahalle, sokak, no)', 'text', ''],
			'ilce' => ['İlçe', 'text', ''],
			'il' => ['İl', 'text', ''],
			'maps' => ['Google Haritalar bağlantısı', 'url', 'Yol tarifi düğmesi.'],
			'mail' => ['E-posta', 'email', ''],
			'saatler' => ['Çalışma saatleri', 'textarea', 'Her satıra bir gün aralığı: <code>Pazartesi-Cuma | 09:00-19:00</code>, <code>Cumartesi | 10:00-15:00</code>. İletişim sayfasında, alt bilgide ve Google için yapılandırılmış veride görünür. Boş bırakılırsa sitede saat yazmaz.'],
		],
		'Sosyal medya' => [
			'ig' => ['Instagram', 'url', ''],
			'ig2' => ['Instagram (video hesabı)', 'url', 'Ana sayfadaki kısa videolar bölümünün bağlantısı.'],
			'yt' => ['YouTube', 'url', ''],
			'tt' => ['TikTok', 'url', ''],
		],
		'Hakkında' => [
			'sayac' => ['Sayaçlar', 'textarea', 'Her satıra bir sayaç: <code>değer | açıklama</code>. Ana sayfa ve Hakkımda sayfasında görünür.'],
			'egitim_kisa' => ['Ana sayfadaki kısa eğitim listesi', 'textarea', 'Her satıra bir madde.'],
			'egitim' => ['Eğitim ve belgeler (Hakkımda sayfası)', 'textarea', 'Grup başlığı <code>## Başlık</code>, altında her satıra bir madde.'],
			'ilkeler' => ['Çalışma yaklaşımı', 'textarea', 'Her satıra bir ilke: <code>başlık | açıklama</code>.'],
			'alt_metin' => ['Alt bilgideki tanıtım cümlesi', 'text', ''],
		],
		'Köşe videosu' => [
			'kose_acik' => ['Sağ alttaki video', 'checkbox', 'Açık'],
			'kose_video' => ['Hangi video', 'video', 'Videolar bölümünde dosyası yüklenmiş videolardan biri.'],
		],
	];
}

add_action('admin_menu', function () {
	add_menu_page('Site bilgileri', 'Site bilgileri', 'manage_options', 'mxp-site', 'mxp_ayar_ekrani', 'dashicons-id-alt', 3);
});

add_action('admin_init', function () {
	register_setting('mxp_site', 'mxp_ayar', ['type' => 'array', 'sanitize_callback' => 'mxp_ayar_temizle', 'default' => []]);
});

function mxp_ayar_temizle($in): array
{
	$in = is_array($in) ? $in : [];
	$out = [];
	$def = mxp_varsayilan()['ayar'];
	foreach (mxp_ayar_alanlari() as $fields) {
		foreach ($fields as $k => [$label, $type]) {
			$v = $in[$k] ?? '';
			switch ($type) {
				case 'url':
					$v = esc_url_raw(trim((string) $v));
					break;
				case 'email':
					$v = sanitize_email((string) $v);
					break;
				case 'textarea':
					$v = sanitize_textarea_field((string) $v);
					break;
				case 'checkbox':
					$v = empty($v) ? 0 : 1;
					break;
				case 'video':
					$v = absint($v);
					break;
				default:
					$v = sanitize_text_field((string) $v);
			}
			/* Varsayılanla aynıysa saklama: ileride varsayılan değişirse kendiliğinden güncellenir. */
			if ($type !== 'checkbox' && $type !== 'video' && (string) $v === (string) ($def[$k] ?? '')) {
				continue;
			}
			$out[$k] = $v;
		}
	}
	return $out;
}

function mxp_ayar_ekrani(): void
{
	if (! current_user_can('manage_options')) {
		return;
	}
	$a = mxp_ayar();
	echo '<div class="wrap"><h1>Site bilgileri</h1><p>Telefon, adres, sosyal hesaplar ve sitedeki sabit bilgiler buradan değişir. Sayfa başlıkları ve sık sorulan sorular her sayfanın kendi düzenleme ekranında, videolar <a href="' . esc_url(admin_url('edit.php?post_type=video')) . '">Videolar</a> bölümünde.</p>';
	echo '<form method="post" action="options.php">';
	settings_fields('mxp_site');
	foreach (mxp_ayar_alanlari() as $title => $fields) {
		echo '<h2 class="title">' . esc_html($title) . '</h2><table class="form-table" role="presentation">';
		foreach ($fields as $k => [$label, $type, $help]) {
			$name = 'mxp_ayar[' . $k . ']';
			$id = 'mxp-' . $k;
			echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
			$v = $a[$k] ?? '';
			if ($type === 'textarea') {
				printf('<textarea class="large-text code" rows="%d" id="%s" name="%s">%s</textarea>', $k === 'egitim' ? 22 : ($k === 'saatler' ? 4 : 6), esc_attr($id), esc_attr($name), esc_textarea((string) $v));
			} elseif ($type === 'checkbox') {
				printf('<label><input type="checkbox" id="%s" name="%s" value="1"%s> %s</label>', esc_attr($id), esc_attr($name), checked(! empty($v), true, false), esc_html($help));
				$help = '';
			} elseif ($type === 'video') {
				echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"><option value="0">İlk dosyalı video</option>';
				foreach (mxv_dosyali_videolar() as $p) {
					printf('<option value="%d"%s>%s</option>', $p->ID, selected((int) $v, $p->ID, false), esc_html(get_the_title($p)));
				}
				echo '</select>';
			} else {
				printf('<input class="regular-text" type="%s" id="%s" name="%s" value="%s">', $type === 'email' ? 'email' : ($type === 'url' ? 'url' : 'text'), esc_attr($id), esc_attr($name), esc_attr((string) $v));
			}
			if ($help !== '') {
				echo '<p class="description">' . wp_kses($help, ['code' => []]) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
	}
	submit_button('Kaydet');
	echo '</form></div>';
}

/* ------------------------------------------------------------------ sayfa başlığı, giriş metni ve SSS */

/** Geçerli sayfanın anahtarı ('' = ana sayfa). */
function mxp_sayfa_anahtar(?int $id = null): ?string
{
	if ($id === null) {
		if (is_front_page()) {
			return '';
		}
		if (! is_page()) {
			return null;
		}
		$id = (int) get_queried_object_id();
	}
	if ($id === (int) get_option('page_on_front')) {
		return '';
	}
	return (string) get_post_field('post_name', $id);
}

function mxp_sayfa_id(): int
{
	return is_front_page() ? (int) get_option('page_on_front') : (int) get_queried_object_id();
}

/** h1, lead ya da faq: kayıtlı değer, yoksa varsayılan. */
function mxp_sayfa_alan(string $f, ?int $id = null)
{
	$key = mxp_sayfa_anahtar($id);
	if ($key === null) {
		return $f === 'faq' ? [] : '';
	}
	$id = $id ?? mxp_sayfa_id();
	$row = mxp_pages()[$key] ?? [];
	$meta = $id ? get_post_meta($id, '_mxp_' . $f, true) : '';
	if ($f === 'faq') {
		$list = is_array($meta) ? $meta : ($row['faq'] ?? []);
		return array_map(static fn ($qa) => [mxp_tok_text((string) $qa[0]), mxp_tok_text((string) $qa[1])], $list);
	}
	return (is_string($meta) && trim($meta) !== '') ? $meta : mxp_tok_text((string) ($row[$f] ?? ''));
}

function mxp_slot_faq(): string
{
	$h = '';
	foreach (mxp_sayfa_alan('faq') as $n => [$q, $a]) {
		$h .= '<details' . ($n === 0 ? ' open' : '') . '><summary>' . esc_html($q) . mxp_ico('i-plus') . '</summary><p>' . esc_html($a) . '</p></details>';
	}
	return $h;
}

function mxp_slot_faqsec(): string
{
	$items = mxp_slot_faq();
	return $items === '' ? '' : '<section class="pg-faq"><div class="wrap narrow"><h2>Sık sorulan sorular</h2>' . $items . '</div></section>';
}

add_action('add_meta_boxes_page', function (WP_Post $post) {
	add_meta_box('mxp-sayfa', 'Sayfanın üst alanı ve sık sorulan sorular', 'mxp_sayfa_kutusu', 'page', 'normal', 'high');
});

function mxp_sayfa_kutusu(WP_Post $post): void
{
	wp_nonce_field('mxp_sayfa', 'mxp_sayfa_n');
	$key = mxp_sayfa_anahtar($post->ID);
	$row = mxp_pages()[$key] ?? null;
	if ($row === null) {
		echo '<p>Bu sayfa temanın hazır kalıplarından birini kullanmıyor; başlık ve metin yukarıdaki editörden gelir.</p>';
		return;
	}
	$h1 = mxp_sayfa_alan('h1', $post->ID);
	$lead = mxp_sayfa_alan('lead', $post->ID);
	$faq = mxp_sayfa_alan('faq', $post->ID);
	echo '<p><label for="mxp-h1"><b>Sayfadaki ana başlık (H1)</b></label><br><input class="widefat" id="mxp-h1" name="mxp_h1" value="' . esc_attr($h1) . '"></p>';
	echo '<p><label for="mxp-lead"><b>Başlığın altındaki giriş metni</b></label><br><textarea class="widefat" rows="3" id="mxp-lead" name="mxp_lead">' . esc_textarea($lead) . '</textarea></p>';
	$wa = trim((string) get_post_meta($post->ID, '_mxp_wa', true));
	echo '<p><label for="mxp-wa"><b>WhatsApp düğmelerinin hazır mesajı</b></label><br><input class="widefat" id="mxp-wa" name="mxp_wa" value="' . esc_attr($wa !== '' ? $wa : (string) ($row['wa'] ?? MXP_WA_GENEL)) . '"><span class="description">Ziyaretçi bu sayfadan WhatsApp\'a geçtiğinde mesaj kutusunda hazır yazan cümle.</span></p>';
	echo '<p><b>Sık sorulan sorular</b> <span class="description">Soruyu silerseniz o madde kalkar. Sorular sayfada ve Google için yapılandırılmış veride görünür.</span></p><div id="mxp-sss">';
	$faq[] = ['', ''];
	foreach ($faq as [$q, $a]) {
		echo '<div class="mxp-qa" style="border:1px solid #dcdcde;padding:10px;margin-bottom:8px;background:#fff"><input class="widefat" name="mxp_q[]" placeholder="Soru" value="' . esc_attr($q) . '" style="margin-bottom:6px"><textarea class="widefat" rows="2" name="mxp_a[]" placeholder="Cevap">' . esc_textarea($a) . '</textarea></div>';
	}
	echo '</div><button type="button" class="button" id="mxp-sss-ekle">Soru ekle</button>';
	echo "<script>document.getElementById('mxp-sss-ekle').addEventListener('click',function(){var w=document.getElementById('mxp-sss'),c=w.lastElementChild.cloneNode(true);c.querySelectorAll('input,textarea').forEach(function(e){e.value=''});w.appendChild(c)});</script>";
}

add_action('save_post_page', function (int $id) {
	if (! isset($_POST['mxp_sayfa_n']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mxp_sayfa_n'])), 'mxp_sayfa') || ! current_user_can('edit_page', $id) || wp_is_post_revision($id)) {
		return;
	}
	$key = mxp_sayfa_anahtar($id);
	$row = mxp_pages()[$key] ?? null;
	if ($row === null) {
		return;
	}
	foreach (['h1' => 'mxp_h1', 'lead' => 'mxp_lead'] as $f => $field) {
		$v = sanitize_textarea_field(wp_unslash($_POST[$field] ?? ''));
		if ($v === '' || $v === mxp_tok_text((string) ($row[$f] ?? ''))) {
			delete_post_meta($id, '_mxp_' . $f);
		} else {
			update_post_meta($id, '_mxp_' . $f, $v);
		}
	}
	$wa = sanitize_text_field(wp_unslash($_POST['mxp_wa'] ?? ''));
	if ($wa === '' || $wa === (string) ($row['wa'] ?? MXP_WA_GENEL)) {
		delete_post_meta($id, '_mxp_wa');
	} else {
		update_post_meta($id, '_mxp_wa', $wa);
	}
	$qs = (array) wp_unslash($_POST['mxp_q'] ?? []);
	$as = (array) wp_unslash($_POST['mxp_a'] ?? []);
	$faq = [];
	foreach ($qs as $n => $q) {
		$q = sanitize_text_field((string) $q);
		$a = sanitize_textarea_field((string) ($as[$n] ?? ''));
		if ($q !== '' && $a !== '') {
			$faq[] = [$q, $a];
		}
	}
	$def = array_map(static fn ($qa) => [mxp_tok_text((string) $qa[0]), mxp_tok_text((string) $qa[1])], $row['faq'] ?? []);
	if ($faq === $def) {
		delete_post_meta($id, '_mxp_faq');
	} else {
		update_post_meta($id, '_mxp_faq', $faq);
	}
});
