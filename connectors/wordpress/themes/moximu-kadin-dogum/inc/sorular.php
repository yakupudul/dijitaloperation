<?php
/**
 * Sorular ve Cevaplar: BetterDocs kaldırıldığında içerik türü ve kategoriler aynı adreslerle temadan kaydolur.
 * /soru-cevap/<yazı>/, /soru-cevap-kategorisi/<kategori>/, /soru-cevap-etiketi/<etiket>/, arşiv /sorular-cevaplar/
 */
if (! defined('ABSPATH')) {
	exit;
}

function bk_betterdocs_acik(): bool
{
	return defined('BETTERDOCS_VERSION') || class_exists('\WPDeveloper\BetterDocs\Plugin');
}

add_action('init', function () {
	if (bk_betterdocs_acik() || post_type_exists('docs')) {
		return;
	}
	register_post_type('docs', [
		'labels' => [
			'name' => 'Sorular ve Cevaplar',
			'singular_name' => 'Soru ve Cevap',
			'add_new_item' => 'Yeni soru ekle',
			'edit_item' => 'Soruyu düzenle',
			'all_items' => 'Tüm sorular',
			'menu_name' => 'Sorular ve Cevaplar',
		],
		'public' => true,
		'show_in_rest' => true,
		'menu_icon' => 'dashicons-format-chat',
		'menu_position' => 21,
		'supports' => ['title', 'editor', 'excerpt', 'author', 'thumbnail', 'revisions', 'custom-fields', 'comments'],
		'has_archive' => 'sorular-cevaplar',
		'rewrite' => ['slug' => 'soru-cevap', 'with_front' => false],
		'taxonomies' => ['doc_category', 'doc_tag'],
	]);
	register_taxonomy('doc_category', 'docs', [
		'labels' => ['name' => 'Soru kategorileri', 'singular_name' => 'Soru kategorisi'],
		'public' => true,
		'hierarchical' => true,
		'show_in_rest' => true,
		'show_admin_column' => true,
		'rewrite' => ['slug' => 'soru-cevap-kategorisi', 'with_front' => false, 'hierarchical' => true],
	]);
	register_taxonomy('doc_tag', 'docs', [
		'labels' => ['name' => 'Soru etiketleri', 'singular_name' => 'Soru etiketi'],
		'public' => true,
		'hierarchical' => false,
		'show_in_rest' => true,
		'rewrite' => ['slug' => 'soru-cevap-etiketi', 'with_front' => false],
	]);
}, 5);

/** Kayıt sahibi değiştiğinde (BetterDocs ↔ tema) adres kurallarını bir kez yenile. */
add_action('init', function () {
	$durum = bk_betterdocs_acik() ? 'betterdocs' : 'tema';
	if (get_option('bk_sorular_kayit') !== $durum) {
		update_option('bk_sorular_kayit', $durum, true);
		flush_rewrite_rules(false);
	}
}, 99);

/** Kategorinin sorularını BetterDocs'taki sırayla döndürür (yoksa başlığa göre). */
function bk_kategori_sorulari(int $term_id, int $adet = -1): array
{
	$sira = get_term_meta($term_id, '_docs_order', true);
	$q = new WP_Query([
		'post_type' => 'docs',
		'post_status' => 'publish',
		'posts_per_page' => $adet,
		'no_found_rows' => true,
		'tax_query' => [['taxonomy' => 'doc_category', 'field' => 'term_id', 'terms' => $term_id, 'include_children' => false]],
		'orderby' => 'title',
		'order' => 'ASC',
	]);
	$yazilar = $q->posts;
	if ($sira) {
		$idler = array_map('intval', array_filter(explode(',', (string) $sira)));
		$pos = array_flip($idler);
		usort($yazilar, function ($a, $b) use ($pos) {
			$pa = $pos[$a->ID] ?? PHP_INT_MAX;
			$pb = $pos[$b->ID] ?? PHP_INT_MAX;
			return $pa === $pb ? strcmp($a->post_title, $b->post_title) : $pa <=> $pb;
		});
	}
	return $yazilar;
}

function bk_soru_kategorileri(): array
{
	$terms = get_terms(['taxonomy' => 'doc_category', 'hide_empty' => true, 'parent' => 0]);
	if (is_wp_error($terms)) {
		return [];
	}
	usort($terms, function ($a, $b) {
		$oa = (int) get_term_meta($a->term_id, 'doc_category_order', true);
		$ob = (int) get_term_meta($b->term_id, 'doc_category_order', true);
		return $oa === $ob ? $a->term_id <=> $b->term_id : $oa <=> $ob;
	});
	return $terms;
}

/** Okuma süresi (dakika). */
function bk_okuma_suresi(string $html): int
{
	$kelime = str_word_count(wp_strip_all_tags($html), 0, 'çğıöşüÇĞİÖŞÜâîû');
	return max(1, (int) ceil($kelime / 200));
}
