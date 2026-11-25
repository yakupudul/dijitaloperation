<?php
/**
 * Menüler: Görünüm > Menüler'den yönetilir. Üst menüde alt öğesi olan madde açılır listeye dönüşür.
 * İlk açılışta mevcut yapı menü olarak bir kez kurulur; menü boşsa temadaki hazır liste görünür.
 */
if (! defined('ABSPATH')) {
	exit;
}

const MXP_MENULER = [
	'ana' => 'Üst menü',
	'alt_alanlar' => 'Alt bilgi: Çalışma alanları',
	'alt_yontemler' => 'Alt bilgi: Yöntemler',
	'alt_kurumsal' => 'Alt bilgi: Kurumsal',
];

add_action('after_setup_theme', function () {
	register_nav_menus(MXP_MENULER);
});

/** Menü öğeleri, üst öğeye göre gruplu. */
function mxp_menu_ogeleri(string $loc): ?array
{
	$locs = get_nav_menu_locations();
	if (empty($locs[$loc])) {
		return null;
	}
	$items = wp_get_nav_menu_items($locs[$loc], ['update_post_term_cache' => false]);
	if (! $items) {
		return null;
	}
	_wp_menu_item_classes_by_context($items);
	$tree = [];
	foreach ($items as $it) {
		$tree[(int) $it->menu_item_parent][] = $it;
	}
	return $tree;
}

function mxp_menu_a(WP_Post $it, string $inner, string $cls = ''): string
{
	$cur = ! empty($it->current) ? ' aria-current="page"' : '';
	return '<a' . ($cls ? ' class="' . esc_attr($cls) . '"' : '') . ' href="' . esc_url($it->url) . '"' . $cur . ($it->target ? ' target="' . esc_attr($it->target) . '" rel="noopener"' : '') . '>' . $inner . '</a>';
}

/** Üst menü <li> öğeleri; menü yoksa null. */
function mxp_slot_nav(): ?string
{
	$tree = mxp_menu_ogeleri('ana');
	if ($tree === null) {
		return null;
	}
	$h = '';
	foreach ($tree[0] ?? [] as $it) {
		$kids = $tree[(int) $it->ID] ?? [];
		$title = esc_html($it->title);
		if (! $kids) {
			$h .= '<li>' . mxp_menu_a($it, $title) . '</li>';
			continue;
		}
		$tum = mxp_menu_a($it, esc_html('Tüm ' . mb_strtolower(mb_substr($it->title, 0, 1)) . mb_substr($it->title, 1)), 'all');
		$mega = false;
		foreach ($kids as $k) {
			if (! empty($tree[(int) $k->ID])) {
				$mega = true;
			}
		}
		$sub = '';
		if ($mega) {
			foreach ($kids as $k) {
				$sub .= '<div class="mg-col">' . mxp_menu_a($k, esc_html($k->title), 'mg-h');
				foreach ($tree[(int) $k->ID] ?? [] as $g) {
					$sub .= mxp_menu_a($g, esc_html($g->title));
				}
				$sub .= '</div>';
			}
			$h .= '<li class="dd mega">' . mxp_menu_a($it, $title . '<svg class="ico car"><use href="#i-down"/></svg>') . '<div class="dd-menu"><div class="mg">' . $sub . '</div>' . $tum . '</div></li>';
			continue;
		}
		foreach ($kids as $k) {
			$sub .= mxp_menu_a($k, esc_html($k->title));
		}
		$sub .= $tum;
		$h .= '<li class="dd">' . mxp_menu_a($it, $title . '<svg class="ico car"><use href="#i-down"/></svg>') . '<div class="dd-menu">' . $sub . '</div></li>';
	}
	return $h;
}

/** Alt bilgi sütunu <li> öğeleri; menü yoksa null. */
function mxp_slot_fm(string $which): ?string
{
	$tree = mxp_menu_ogeleri('alt_' . $which);
	if ($tree === null) {
		return null;
	}
	$h = '';
	foreach ($tree[0] ?? [] as $it) {
		$h .= '<li>' . mxp_menu_a($it, esc_html($it->title)) . '</li>';
	}
	return $h;
}

/* ------------------------------------------------------------------ ilk kurulum (bir kez) */

function mxp_menu_kur(): void
{
	if (get_option('mxp_menu_kuruldu')) {
		return;
	}
	update_option('mxp_menu_kuruldu', 1, false);
	$tanim = (mxp_varsayilan()['menuler'] ?? []);
	$locs = get_nav_menu_locations();
	foreach ($tanim as $loc => [$name, $items]) {
		if (! empty($locs[$loc])) {
			continue;
		}
		$obj = wp_get_nav_menu_object($name);
		$menu_id = $obj ? (int) $obj->term_id : (int) wp_create_nav_menu($name);
		if (! $menu_id || is_wp_error($menu_id)) {
			continue;
		}
		if (! $obj) {
			$pos = 0;
			foreach ($items as $item) {
				mxp_menu_oge_ekle($menu_id, $item, 0, $pos);
			}
		}
		$locs[$loc] = $menu_id;
	}
	set_theme_mod('nav_menu_locations', $locs);
}

/** $item = [başlık, hedef, alt öğeler]; hedef: sayfa kısa adı, '' = ana sayfa, '@video' = videolar arşivi, 'http…' = adres. */
function mxp_menu_oge_ekle(int $menu_id, array $item, int $parent, int &$pos): void
{
	[$title, $target] = $item;
	$pos++;
	$args = ['menu-item-title' => $title, 'menu-item-status' => 'publish', 'menu-item-parent-id' => $parent, 'menu-item-position' => $pos];
	if ($target === '@video') {
		$args += ['menu-item-type' => 'post_type_archive', 'menu-item-object' => 'video'];
	} elseif ($target === '' || str_starts_with($target, 'http')) {
		$args += ['menu-item-type' => 'custom', 'menu-item-url' => $target === '' ? home_url('/') : $target];
	} else {
		$page = get_page_by_path($target);
		if (! $page) {
			return;
		}
		$args += ['menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $page->ID];
	}
	$id = wp_update_nav_menu_item($menu_id, 0, $args);
	if (! is_wp_error($id) && ! empty($item[2])) {
		foreach ($item[2] as $child) {
			mxp_menu_oge_ekle($menu_id, $child, (int) $id, $pos);
		}
	}
}

/* ------------------------------------------------------------------ v1.3: yeni sayfaları mevcut menülere ekle (bir kez) */

/**
 * varsayilan.php 'menu_ek': konum => [[başlık, sayfa kısa adı, üst öğenin sayfası ('' = en üst), hangi sayfadan sonra], ...].
 * Elle düzenlenmiş menülere dokunmaz; sayfa zaten menüdeyse geçer.
 */
function mxp_menu_v2(): void
{
	if (get_option('mxp_menu_v2') || ! get_option('mxp_menu_kuruldu') || ! get_page_by_path('konya-aile-danismanligi')) {
		return;
	}
	update_option('mxp_menu_v2', 1, false);
	$locs = get_nav_menu_locations();
	foreach ((mxp_varsayilan()['menu_ek'] ?? []) as $loc => $ekler) {
		if (empty($locs[$loc])) {
			continue;
		}
		$menu_id = (int) $locs[$loc];
		foreach ($ekler as [$title, $slug, $ust, $sonra]) {
			$page = get_page_by_path($slug);
			if (! $page) {
				continue;
			}
			$items = (array) wp_get_nav_menu_items($menu_id, ['post_status' => 'any']);
			$by_obj = [];
			foreach ($items as $it) {
				if ($it->object === 'page') {
					$by_obj[(int) $it->object_id][] = $it;
				}
			}
			$parent = 0;
			if ($ust !== '') {
				$up = get_page_by_path($ust);
				$parent = ($up && ! empty($by_obj[(int) $up->ID])) ? (int) $by_obj[(int) $up->ID][0]->ID : 0;
				if (! $parent) {
					continue;
				}
			}
			$zaten = false;
			foreach ($by_obj[(int) $page->ID] ?? [] as $it) {
				if ((int) $it->menu_item_parent === $parent) {
					$zaten = true;
				}
			}
			if ($zaten) {
				continue;
			}
			$new = wp_update_nav_menu_item($menu_id, 0, [
				'menu-item-title' => $title, 'menu-item-status' => 'publish', 'menu-item-parent-id' => $parent,
				'menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $page->ID,
			]);
			if (is_wp_error($new)) {
				continue;
			}
			/* sırayı ayarla: "sonra" sayfasının hemen arkasına */
			$after = get_page_by_path($sonra);
			$after_item = 0;
			foreach ($after ? ($by_obj[(int) $after->ID] ?? []) : [] as $it) {
				if ((int) $it->menu_item_parent === $parent) {
					$after_item = (int) $it->ID;
				}
			}
			if (! $after_item) {
				continue;
			}
			$order = [];
			foreach ($items as $it) {
				if ((int) $it->ID === (int) $new) {
					continue;
				}
				$order[] = (int) $it->ID;
				if ((int) $it->ID === $after_item) {
					$order[] = (int) $new;
				}
			}
			foreach ($order as $n => $item_id) {
				wp_update_post(['ID' => $item_id, 'menu_order' => $n + 1]);
			}
		}
	}
}

/* ------------------------------------------------------------------ v1.5: çalışma alanları üç grupta, yeni sayfaların videoları, eğitim satırları (bir kez) */

function mxp_v15(): void
{
	if (get_option('mxp_v15') || ! get_option('mxp_menu_kuruldu')) {
		return;
	}
	$okb = get_page_by_path('konya-okb');
	if (! $okb || $okb->post_status !== 'publish') {
		return;
	}
	update_option('mxp_v15', 1, false);
	$v = mxp_varsayilan();

	/* üst menü: "Çalışma alanları" altını gruplu yapıyla yeniden kur */
	$locs = get_nav_menu_locations();
	$liste = get_page_by_path('calisma-alanlari');
	if (! empty($locs['ana']) && $liste) {
		$menu_id = (int) $locs['ana'];
		$items = (array) wp_get_nav_menu_items($menu_id, ['post_status' => 'any']);
		$ust = 0;
		foreach ($items as $it) {
			if ((int) $it->menu_item_parent === 0 && $it->object === 'page' && (int) $it->object_id === (int) $liste->ID) {
				$ust = (int) $it->ID;
			}
		}
		if ($ust) {
			$kids = [];
			foreach ($items as $it) {
				$kids[(int) $it->menu_item_parent][] = (int) $it->ID;
			}
			$sil = [];
			$yigin = $kids[$ust] ?? [];
			while ($yigin) {
				$x = array_pop($yigin);
				$sil[] = $x;
				foreach ($kids[$x] ?? [] as $y) {
					$yigin[] = $y;
				}
			}
			foreach ($sil as $x) {
				wp_delete_post($x, true);
			}
			$pos = 0;
			foreach (($v['alan_menu'] ?? []) as $grup) {
				mxp_menu_oge_ekle($menu_id, $grup, $ust, $pos);
			}
			/* sıra: yeni öğeler "Çalışma alanları"nın hemen arkasına */
			$yeni = [];
			$eski = [];
			foreach ((array) wp_get_nav_menu_items($menu_id, ['post_status' => 'any']) as $it) {
				if (in_array((int) $it->ID, $sil, true)) {
					continue;
				}
				$ata = (int) $it->menu_item_parent;
				$alt = false;
				while ($ata) {
					if ($ata === $ust) {
						$alt = true;
						break;
					}
					$ata = (int) get_post_meta($ata, '_menu_item_menu_item_parent', true);
				}
				if ($alt) {
					$yeni[] = (int) $it->ID;
				} else {
					$eski[] = (int) $it->ID;
				}
			}
			sort($yeni);
			$sira = [];
			foreach ($eski as $id) {
				$sira[] = $id;
				if ($id === $ust) {
					array_push($sira, ...$yeni);
				}
			}
			foreach ($sira as $n => $id) {
				wp_update_post(['ID' => $id, 'menu_order' => $n + 1]);
			}
		}
	}

	/* videoları yeni sayfalara bağla */
	$bag = $v['ek_bag2'] ?? [];
	foreach (get_posts(['post_type' => 'video', 'post_status' => 'any', 'posts_per_page' => -1]) as $p) {
		$k = mxv_anahtar((int) $p->ID);
		if ($k === '' || empty($bag[$k])) {
			continue;
		}
		$var = array_map('intval', (array) get_post_meta($p->ID, '_mxv_sayfa'));
		foreach ($bag[$k] as $slug) {
			$pg = get_page_by_path($slug);
			if ($pg && ! in_array((int) $pg->ID, $var, true)) {
				add_post_meta($p->ID, '_mxv_sayfa', (int) $pg->ID);
				$var[] = (int) $pg->ID;
			}
		}
	}

	/* Site bilgileri kaydedilmişse eğitim listesini güncelle */
	$kayit = get_option('mxp_ayar');
	if (is_array($kayit) && isset($kayit['egitim'])) {
		$norm = static fn (string $t): string => trim(str_replace("\r", '', $t));
		if ($norm((string) $kayit['egitim']) === $norm((string) ($v['egitim_eski'] ?? ''))) {
			$kayit['egitim'] = (string) $v['egitim_yeni'];
		} else {
			$kayit['egitim'] = strtr((string) $kayit['egitim'], (array) ($v['egitim_satir'] ?? []));
		}
		update_option('mxp_ayar', $kayit);
	}
}
