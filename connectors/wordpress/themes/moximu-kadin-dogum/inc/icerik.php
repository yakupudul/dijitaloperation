<?php
/**
 * İçerik işleme: İçindekiler, içerik ortası randevu kartı, eski Elementor kısa kodlarının karşılıkları.
 */
if (! defined('ABSPATH')) {
	exit;
}

/**
 * İçeriği hazırla: H2'lere kimlik ver, İçindekiler listesini çıkar, hizmet sayfalarında 4. H2'den önce randevu kartı koy.
 * Dönüş: ['html' => …, 'toc' => [[id, metin], …]]
 */
function bk_icerik_hazirla(?int $id = null, bool $orta_kart = false): array
{
	$id = $id ?: get_the_ID();
	$html = apply_filters('the_content', get_post_field('post_content', $id));
	$toc = [];
	$kullanilan = [];
	$sayac = 0;
	$html = preg_replace_callback('#<h2(\s[^>]*)?>(.*?)</h2>#is', function ($m) use (&$toc, &$kullanilan, &$sayac, $orta_kart) {
		$attr = $m[1] ?? '';
		$metin = trim(wp_strip_all_tags($m[2]));
		$sayac++;
		$on = '';
		if ($orta_kart && $sayac === 4) {
			$on = bk_orta_kart();
		}
		if ($metin === '') {
			return $on . $m[0];
		}
		if (preg_match('#\sid=["\']([^"\']+)["\']#i', $attr, $idm)) {
			$hid = $idm[1];
		} else {
			$hid = sanitize_title(remove_accents($metin));
			if ($hid === '') {
				$hid = 'baslik-' . $sayac;
			}
			$taban = $hid;
			$n = 2;
			while (isset($kullanilan[$hid])) {
				$hid = $taban . '-' . $n++;
			}
			$attr .= ' id="' . esc_attr($hid) . '"';
		}
		$kullanilan[$hid] = true;
		$toc[] = [$hid, $metin];
		return $on . '<h2' . $attr . '>' . $m[2] . '</h2>';
	}, $html);
	return ['html' => $html, 'toc' => $toc];
}

function bk_icindekiler(array $toc): void
{
	if (count($toc) < 2) {
		return;
	}
	echo '<details class="bk-toc"><summary><span>İçindekiler</span>' . bk_ikon('ok') . '</summary><ol>';
	foreach ($toc as [$id, $metin]) {
		echo '<li><a href="#' . esc_attr($id) . '">' . esc_html($metin) . '</a></li>';
	}
	echo '</ol></details>';
}

/** İçeriğin ortasına konan randevu kartının düğmeleri içerik bağlantısı gibi boyanmaz (beyaz yazı, altı çizgisiz). */
add_action('wp_enqueue_scripts', function () {
	if (is_page()) {
		wp_add_inline_style('bk-site', '.bk-icerik .bk-orta-kart a.bk-btn{color:#fff;text-decoration:none}');
	}
}, 21);

/** Hizmet başlıkları (Hizmetlerim altında ikinci düzey sayfalar), yönlendirilmiş olanlar hariç. */
function bk_hizmet_basliklari(): array
{
	$h = bk_hizmetler_sayfasi();
	if (! $h) {
		return [];
	}
	$out = [];
	foreach (get_pages(['child_of' => $h, 'sort_column' => 'post_title', 'post_status' => 'publish']) as $p) {
		if ((int) $p->post_parent !== $h && ! bk_yonlendirilmis($p)) {
			$out[] = $p;
		}
	}
	return $out;
}

/**
 * [elementor-template id="…"]: kategori sayfalarındaki "Hangi … hizmetine ihtiyacın var?" kart ızgarası.
 * Elementor kaldırıldığında da aynı görünüm bu temadan gelir.
 */
function bk_kategori_izgarasi($atts = []): string
{
	$id = get_the_ID();
	if (! $id) {
		return '';
	}
	$cocuklar = array_filter(bk_cocuklar($id), fn($c) => ! bk_yonlendirilmis($c));
	if (! $cocuklar) {
		return '';
	}
	$h = '<div class="bk-kat-izgara-kap"><h2>Hangi ' . esc_html(get_the_title($id)) . ' hizmetine ihtiyacın var?</h2><div class="bk-kat-izgara">';
	foreach ($cocuklar as $c) {
		$h .= bk_hizmet_karti($c->ID);
	}
	return $h . '</div></div>';
}
add_action('wp', function () {
	remove_shortcode('elementor-template');
	add_shortcode('elementor-template', 'bk_kategori_izgarasi');
}, 99);

/** "Diğer Hizmetlerim": aynı kategoriden başlayarak sabit sırada en çok 10 kart. */
function bk_diger_hizmetler(int $id): array
{
	$tum = bk_hizmet_basliklari();
	$ebeveyn = (int) wp_get_post_parent_id($id);
	$ayni = [];
	$diger = [];
	foreach ($tum as $p) {
		if ((int) $p->ID === $id) {
			continue;
		}
		if ((int) $p->post_parent === $ebeveyn || (int) $p->post_parent === $id) {
			$ayni[] = $p;
		} else {
			$diger[] = $p;
		}
	}
	// Sabit ama sayfaya göre değişen karışım: her sayfada farklı öneriler, önbellekte titreme yok.
	usort($diger, fn($a, $b) => crc32($id . '-' . $a->ID) <=> crc32($id . '-' . $b->ID));
	return array_slice(array_merge($ayni, $diger), 0, 10);
}

/** Blog ve yazı arşivleri: eski sitedeki gibi sayfada 24 yazı. */
add_action('pre_get_posts', function (WP_Query $q) {
	if (! is_admin() && $q->is_main_query() && ($q->is_home() || $q->is_category() || $q->is_tag() || $q->is_author() || $q->is_date())) {
		$q->set('posts_per_page', 24);
	}
});
