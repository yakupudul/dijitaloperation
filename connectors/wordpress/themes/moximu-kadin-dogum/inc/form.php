<?php
/**
 * İletişim / randevu formu: Elementor Pro formunun karşılığı.
 * Gelen talep "Randevu talepleri" listesine kaydedilir ve kliniğin e-posta adresine gider (WP Mail SMTP ile).
 */
if (! defined('ABSPATH')) {
	exit;
}

add_action('init', function () {
	register_post_type('bk_talep', [
		'labels' => [
			'name' => 'Randevu talepleri',
			'singular_name' => 'Randevu talebi',
			'menu_name' => 'Randevu talepleri',
			'all_items' => 'Tüm talepler',
			'edit_item' => 'Talebi görüntüle',
		],
		'public' => false,
		'show_ui' => true,
		'show_in_menu' => true,
		'menu_icon' => 'dashicons-calendar-alt',
		'menu_position' => 22,
		'supports' => ['title', 'editor'],
		'capability_type' => 'post',
		'capabilities' => ['create_posts' => 'do_not_allow'],
		'map_meta_cap' => true,
	]);
});

function bk_form(string $kimlik = 'iletisim'): void
{
	$id = 'bk-form-' . sanitize_key($kimlik);
	?>
	<form class="bk-form" id="<?php echo esc_attr($id); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-rest="<?php echo esc_url(rest_url('bk/v1/form')); ?>" novalidate>
		<input type="hidden" name="action" value="bk_form">
		<input type="hidden" name="kaynak" value="<?php echo esc_attr($kimlik); ?>">
		<input type="hidden" name="sayfa" value="<?php echo esc_attr((string) get_queried_object_id()); ?>">
		<input type="hidden" name="t" value="<?php echo esc_attr((string) time()); ?>">
		<div class="bk-form-gizli" aria-hidden="true"><label>Web sitesi <input type="text" name="web" tabindex="-1" autocomplete="off"></label></div>
		<div class="bk-alan yarim">
			<label for="<?php echo esc_attr($id); ?>-ad">Ad &amp; Soyad <span aria-hidden="true">*</span></label>
			<input id="<?php echo esc_attr($id); ?>-ad" type="text" name="ad" placeholder="Adınız &amp; Soyadınız" autocomplete="name" required>
		</div>
		<div class="bk-alan yarim">
			<label for="<?php echo esc_attr($id); ?>-tel">Telefon <span aria-hidden="true">*</span></label>
			<input id="<?php echo esc_attr($id); ?>-tel" type="tel" name="telefon" placeholder="Telefon Numaranız" autocomplete="tel" inputmode="tel" required>
		</div>
		<div class="bk-alan">
			<label for="<?php echo esc_attr($id); ?>-eposta">E-posta <span aria-hidden="true">*</span></label>
			<input id="<?php echo esc_attr($id); ?>-eposta" type="email" name="eposta" placeholder="E-posta Adresiniz" autocomplete="email" required>
		</div>
		<div class="bk-alan">
			<label for="<?php echo esc_attr($id); ?>-mesaj">Mesaj</label>
			<textarea id="<?php echo esc_attr($id); ?>-mesaj" name="mesaj" rows="4" placeholder="Mesajınız"></textarea>
		</div>
		<div class="bk-alan onay">
			<span class="bk-onay-baslik">KVKK Onay <span aria-hidden="true">*</span></span>
			<label><input type="checkbox" name="kvkk" value="1" required> KVKK Aydınlatma Metni'ni okudum ve kabul ediyorum.</label>
		</div>
		<div class="bk-alan">
			<button type="submit" class="bk-form-gonder">Gönder</button>
		</div>
		<p class="bk-form-sonuc" role="status" aria-live="polite"><?php
		if (isset($_GET['talep'])) {
			echo $_GET['talep'] === 'tamam' ? 'Talebiniz alındı. En kısa sürede sizinle iletişime geçeceğiz.' : 'Gönderilemedi. Lütfen alanları kontrol edin ya da bizi arayın.';
		}
		?></p>
	</form>
	<?php
}

/** Ortak işleyici. Dönüş: [başarılı mı, mesaj]. */
function bk_form_isle(array $v): array
{
	$ad = sanitize_text_field($v['ad'] ?? '');
	$tel = sanitize_text_field($v['telefon'] ?? '');
	$eposta = sanitize_email($v['eposta'] ?? '');
	$mesaj = sanitize_textarea_field($v['mesaj'] ?? '');
	$kvkk = ! empty($v['kvkk']);
	$kaynak = sanitize_key($v['kaynak'] ?? 'iletisim');
	$sayfa = (int) ($v['sayfa'] ?? 0);

	// İstenmeyen gönderim süzgeci: gizli alan dolu ya da form 3 saniyeden kısa sürede gönderilmiş.
	if (! empty($v['web']) || (time() - (int) ($v['t'] ?? 0)) < 3) {
		return [true, 'Talebiniz alındı.'];
	}
	if ($ad === '' || $tel === '' || ! is_email($eposta) || ! $kvkk) {
		return [false, 'Lütfen zorunlu alanları doldurun ve KVKK onayını işaretleyin.'];
	}
	$ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
	$anahtar = 'bk_form_' . md5($ip);
	$adet = (int) get_transient($anahtar);
	if ($adet >= 5) {
		return [false, 'Çok sayıda deneme yapıldı. Lütfen bizi telefonla arayın.'];
	}
	set_transient($anahtar, $adet + 1, HOUR_IN_SECONDS);

	$sayfa_adi = $sayfa ? get_the_title($sayfa) : '';
	$govde = "Ad Soyad: $ad\nTelefon: $tel\nE-posta: $eposta\n\nMesaj:\n$mesaj\n\nForm: " . ($kaynak === 'randevu' ? 'Randevu Talebi Oluştur' : 'İletişim') . ($sayfa_adi ? "\nSayfa: $sayfa_adi" : '') . "\nKVKK onayı: verildi\nTarih: " . wp_date('d.m.Y H:i');

	wp_insert_post([
		'post_type' => 'bk_talep',
		'post_status' => 'private',
		'post_title' => $ad . ' – ' . $tel,
		'post_content' => $govde,
	]);

	$alici = bk_ayar('eposta');
	$basliklar = ['Reply-To: ' . $ad . ' <' . $eposta . '>'];
	wp_mail($alici, 'Yeni ' . ($kaynak === 'randevu' ? 'randevu talebi' : 'iletişim formu') . ': ' . $ad, $govde, $basliklar);

	return [true, 'Talebiniz alındı. En kısa sürede sizinle iletişime geçeceğiz.'];
}

add_action('rest_api_init', function () {
	register_rest_route('bk/v1', '/form', [
		'methods' => 'POST',
		'permission_callback' => '__return_true',
		'callback' => function (WP_REST_Request $r) {
			[$tamam, $mesaj] = bk_form_isle($r->get_params());
			return new WP_REST_Response(['tamam' => $tamam, 'mesaj' => $mesaj], $tamam ? 200 : 422);
		},
	]);
});

/** JavaScript kapalıysa düz gönderim. */
$bk_form_duz = function () {
	[$tamam] = bk_form_isle(wp_unslash($_POST));
	$geri = wp_get_referer() ?: home_url('/');
	wp_safe_redirect(add_query_arg('talep', $tamam ? 'tamam' : 'hata', $geri) . '#icerik');
	exit;
};
add_action('admin_post_nopriv_bk_form', $bk_form_duz);
add_action('admin_post_bk_form', $bk_form_duz);
