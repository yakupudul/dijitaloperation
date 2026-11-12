<?php
/**
 * Ortak parçalar: simgeler, düğmeler, kartlar, ızgaralar ve sayfalarda tekrar eden bölümler.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function np_icon( $name ) {
	$i = array(
		'wa'    => '<svg aria-hidden="true" viewBox="0 0 448 512"><path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>',
		'tel'   => '<svg aria-hidden="true" viewBox="0 0 512 512"><path d="M497.39 361.8l-112-48a24 24 0 0 0-28 6.9l-49.6 60.6A370.66 370.66 0 0 1 130.6 204.11l60.6-49.6a23.94 23.94 0 0 0 6.9-28l-48-112A24.16 24.16 0 0 0 122.6.61l-104 24A24 24 0 0 0 0 48c0 256.5 207.9 464 464 464a24 24 0 0 0 23.4-18.6l24-104a24.29 24.29 0 0 0-14.01-27.6z"/></svg>',
		'down'  => '<svg aria-hidden="true" viewBox="0 0 448 512"><path d="M207.029 381.476L12.686 187.132c-9.373-9.373-9.373-24.569 0-33.941l22.667-22.667c9.357-9.357 24.522-9.375 33.901-.04L224 284.505l154.745-154.021c9.379-9.335 24.544-9.317 33.901.04l22.667 22.667c9.373 9.373 9.373 24.569 0 33.941L240.971 381.476c-9.373 9.372-24.569 9.372-33.942 0z"/></svg>',
		'menu'  => '<svg class="m" aria-hidden="true" viewBox="0 0 1000 1000"><path d="M104 333H896C929 333 958 304 958 271S929 208 896 208H104C71 208 42 237 42 271S71 333 104 333ZM104 583H896C929 583 958 554 958 521S929 458 896 458H104C71 458 42 487 42 521S71 583 104 583ZM104 833H896C929 833 958 804 958 771S929 708 896 708H104C71 708 42 737 42 771S71 833 104 833Z"/></svg><svg class="x" aria-hidden="true" viewBox="0 0 1000 1000"><path d="M742 167L500 408 258 167C246 154 233 150 217 150 196 150 179 158 167 167 154 179 150 196 150 212 150 229 154 242 171 254L408 500 167 742C138 771 138 800 167 829 196 858 225 858 254 829L496 587 738 829C750 842 767 846 783 846 800 846 817 842 829 829 842 817 846 804 846 783 846 767 842 750 829 737L588 500 833 258C863 229 863 200 833 171 804 137 775 137 742 167Z"/></svg>',
	);
	return $i[ $name ] ?? '';
}

/** WhatsApp düğmesi. $kind: wa | white, $msg: hazır mesaj (boşsa Firma Bilgileri'ndeki). */
function np_wa( $text = "WhatsApp'tan yazın", $kind = 'wa', $cls = '', $msg = '' ) {
	$label = '' === $text ? ' aria-label="WhatsApp"' : '';
	return '<a class="btn btn-' . esc_attr( $kind ) . ( $cls ? ' ' . esc_attr( $cls ) : '' ) . '" href="' . esc_url( np_wa_link( $msg ) ) . '" target="_blank" rel="noopener"' . $label . '>' . np_icon( 'wa' ) . ( '' !== $text ? '<span>' . esc_html( $text ) . '</span>' : '' ) . '</a>';
}
/** Arama düğmesi: metin boşsa telefon numarası yazar. */
function np_call( $kind = 'line', $cls = '', $text = '' ) {
	return '<a class="btn btn-' . esc_attr( $kind ) . ( $cls ? ' ' . esc_attr( $cls ) : '' ) . '" href="' . esc_url( np_tel_link() ) . '">' . np_icon( 'tel' ) . '<span>' . esc_html( $text ? $text : np_opt( 'telefon' ) ) . '</span></a>';
}
function np_btns( $wa = "WhatsApp'tan fiyat sorun", $call = 'line', $wa_kind = 'wa' ) {
	return '<div class="btns">' . np_wa( $wa, $wa_kind ) . np_call( $call ) . '</div>';
}

function np_url( $path ) {
	return home_url( $path );
}

/** Bölüm başlığı: üst etiket + başlık, sağda kısa metin. */
function np_head( $eyebrow, $title, $sub = '', $dark = false ) {
	$l = '<div class="l"><div class="t-eyebrow ' . ( $dark ? 'c-pink' : 'c-plum' ) . '">' . esc_html( $eyebrow ) . '</div><h2 class="t-h2 ' . ( $dark ? 'c-white' : 'c-ink' ) . '">' . esc_html( $title ) . '</h2></div>';
	if ( ! $sub ) {
		return '<div class="head1">' . $l . '</div>';
	}
	return '<div class="head">' . $l . '<div class="r"><p class="' . ( $dark ? 'c-nt' : 'c-text' ) . '">' . esc_html( $sub ) . '</p></div></div>';
}

function np_rows( $pairs, $left = 34 ) {
	$h = '<div class="rows" style="--l:' . (int) $left . '%">';
	foreach ( $pairs as $p ) {
		if ( '' === trim( (string) $p[0] ) && '' === trim( (string) $p[1] ) ) {
			continue;
		}
		$h .= '<div><div>' . esc_html( $p[0] ) . '</div><div>' . esc_html( $p[1] ) . '</div></div>';
	}
	return $h . '</div>';
}

/** Soru cevap listesi. $items = [[soru, cevap], ...]; ilk soru açık gelir. */
function np_acc( $items ) {
	$h = '<div class="acc">';
	$n = 'q' . wp_rand( 1000, 9999 );
	foreach ( array_values( $items ) as $i => $it ) {
		$h .= '<details name="' . $n . '"' . ( 0 === $i ? ' open' : '' ) . '><summary><h3>' . esc_html( $it[0] ) . '</h3><i></i></summary><div class="a">' . wpautop( wp_kses_post( $it[1] ) ) . '</div></details>';
	}
	return $h . '</div>';
}

function np_steps_data() {
	return array(
		array( 'Fotoğraf gönderin', "Pencerenin fotoğrafını ve yaklaşık ölçüsünü WhatsApp'tan iletin, size ön fiyat aralığı verelim." ),
		array( 'Ölçü randevusu', 'Size uyan gün ve saatte gelip ölçüyü yerinde alıyoruz.' ),
		array( 'Kumaş ve sistem seçimi', 'Kumaşları evinizin ışığında görüyorsunuz; fiyat yerinde netleşiyor.' ),
		array( 'Hazırlık ve montaj', 'Perdeniz ölçünüze göre hazırlanıyor, ekibimiz takıp ayarını yapıyor.' ),
	);
}
function np_steps( $dark = true ) {
	$h = '<div class="steps' . ( $dark ? ' dk' : '' ) . '">';
	foreach ( np_steps_data() as $i => $s ) {
		$h .= '<div><b>' . ( $i + 1 ) . '</b><div><h3 class="t-h4 c-ink">' . esc_html( $s[0] ) . '</h3><p>' . esc_html( $s[1] ) . '</p></div></div>';
	}
	return $h . '</div>';
}

function np_img( $id, $size = 'large', $attr = array() ) {
	return wp_get_attachment_image( (int) $id, $size, false, $attr );
}
/** Ölçü fotoğrafı: "Ölçüye göre dikilmiş fon ve tül perde" görseli (ortam kitaplığında adıyla bulunur). */
function np_media_id( $slug ) {
	static $c = array();
	if ( isset( $c[ $slug ] ) ) {
		return $c[ $slug ];
	}
	$q = get_posts( array( 'post_type' => 'attachment', 'name' => $slug, 'posts_per_page' => 1, 'post_status' => 'inherit', 'fields' => 'ids' ) );
	if ( ! $q ) {
		// Ek adı farklıysa dosya adından bul (…/dosya-adi.webp).
		$q = get_posts( array( 'post_type' => 'attachment', 'posts_per_page' => 1, 'post_status' => 'inherit', 'fields' => 'ids',
			'meta_query' => array( array( 'key' => '_wp_attached_file', 'value' => '/' . $slug . '.', 'compare' => 'LIKE' ) ) ) ); // phpcs:ignore
	}
	$c[ $slug ] = $q ? (int) $q[0] : 0;
	return $c[ $slug ];
}

function np_measure_band( $title = '', $sub = '' ) {
	$title = $title ? $title : 'Ölçüyü biz alalım, perdeyi biz takalım';
	$sub   = $sub ? $sub : "Yanlış ölçü en sık yaşanan sorundur. Bu yüzden İzmir'in her ilçesinde ölçüye geliyor, montajı da kendi ekibimizle yapıyoruz. Ölçü ve montaj ücretsiz.";
	$img   = np_img( np_media_id( 'olcuye-ozel-fon-tul-perde' ), 'large', array( 'sizes' => '(max-width:1024px) 100vw, 50vw' ) );
	return '<section class="mb"><div class="pic">' . $img . '</div><div class="txt"><div class="t-eyebrow c-pink">Ücretsiz ölçü ve montaj</div><h2 class="t-h2 c-white">' . esc_html( $title ) . '</h2><p class="t-lead c-nt">' . esc_html( $sub ) . '</p>'
		. np_steps( true ) . '<div class="btns">' . np_wa( 'Ölçü randevusu alın' ) . np_call( 'line-w' ) . '</div></div></section>';
}

function np_final_cta( $title = '', $sub = '' ) {
	$title = $title ? $title : 'Perde fiyatı için pencerenizin fotoğrafını gönderin';
	$sub   = $sub ? $sub : "Fotoğraf ve yaklaşık ölçüyle ön fiyat aralığını WhatsApp'tan iletiyoruz. Ölçü ve montaj ücretsiz.";
	return '<section class="sec bg-plum fc" style="--pt:80px;--pb:80px"><div class="wrap"><div class="row"><div class="l"><h2 class="t-h2 c-white">' . esc_html( $title ) . '</h2><p class="t-lead c-pinkbg">' . esc_html( $sub ) . '</p></div>'
		. '<div class="btns">' . np_wa( "WhatsApp'tan yazın", 'white' ) . np_call( 'line-w' ) . '</div></div></div></section>';
}

function np_facts() {
	$c = function ( $b, $r ) {
		return '<div><div class="t-h4 c-ink">' . esc_html( $b ) . '</div><div class="t-small c-text">' . esc_html( $r ) . '</div></div>';
	};
	return '<section class="facts"><div>' . $c( 'Ölçü ve montaj ücretsiz', "İzmir'in her ilçesinde" ) . $c( np_opt( 'deneyim' ), 'perde ve aksesuar deneyimi' ) . $c( 'Kumaşı evinizde seçin', 'kendi ışığınızda, yerinde' ) . '</div></section>';
}

function np_room_guide() {
	$rooms = array(
		array( 'Salon', 'Gündüz tül, akşam fon. Geniş camda zebra ya da motorlu stor da sık tercih edilir.', 'Tül ve fon', 'tul-perde' ),
		array( 'Yatak odası', 'Sabah ışığını kesen karartma kumaş; fon perdenin arkasına ya da stor olarak.', 'Karartma perde', 'karartma-perde' ),
		array( 'Mutfak', 'Buhar ve yağdan etkilenmeyen, silinebilen yüzey; tezgâh üstünde kısa boy.', 'Jaluzi ve stor', 'aluminyum-jaluzi-perde' ),
		array( 'Çocuk odası', 'Gün içi uykusu için karartma, ipi açıkta kalmayan güvenli mekanizma.', 'Karartma stor', 'stor-perde' ),
		array( 'Cam balkon', 'Katlanan camların hareketine engel olmayan, camın üstüne oturan perde.', 'Plise perde', 'plise-perde' ),
		array( 'Balkon ve teras', 'Güneş, rüzgâr ve sinek için yan kanallı sistem; açık balkonda da kullanılır.', 'Zip perde', 'zip-perde' ),
		array( 'Ofis ve iş yeri', 'Ekrana vuran güneşi kesen, dışarıyı göstermeye devam eden screen kumaş.', 'Stor ve jaluzi', 'stor-perde' ),
	);
	$rooms = apply_filters( 'np_rooms', $rooms );
	$list  = '';
	foreach ( $rooms as $r ) {
		$list .= '<div><h3 class="t-h3 c-ink">' . esc_html( $r[0] ) . '</h3><p>' . esc_html( $r[1] ) . '</p><a href="' . esc_url( np_url( '/perde/' . $r[3] . '/' ) ) . '">' . esc_html( $r[2] ) . ' →</a></div>';
	}
	return '<section class="sec bg-mist rg"><div class="wrap"><div class="row"><div class="col intro" style="--w:38%;--g:20px"><div class="t-eyebrow c-plum">Hangi oda için hangi perde?</div><h2 class="t-h2 c-ink">Odanın ihtiyacı perdeyi belirler</h2>'
		. '<p class="t-lead c-text">Güneşin geldiği yön, odanın kullanımı ve pencerenin açılış şekli seçimi değiştirir. Ölçüye geldiğimizde bunlara bakarak iki üç seçenek öneriyoruz.</p>'
		. '<a class="linku" href="' . esc_url( np_url( '/hangi-odaya-hangi-perde/' ) ) . '">Oda oda perde seçimi rehberi</a></div><div class="col list" style="--w:56%;--g:0px">' . $list . '</div></div></div></section>';
}

/* ---------- kartlar ---------- */
function np_card_perde( $id, $fixed = false ) {
	$grp  = get_the_terms( $id, 'perde_grubu' );
	$g    = ( $grp && ! is_wp_error( $grp ) ) ? $grp[0]->slug : '';
	$kart = function_exists( 'get_field' ) ? (string) get_field( 'kart_metni', $id ) : '';
	$img  = get_the_post_thumbnail( $id, 'large', array( 'sizes' => '(max-width:767px) 100vw, (max-width:1024px) 50vw, 420px' ) );
	return '<a class="np-card c-perde' . ( $fixed ? ' fix' : '' ) . '" href="' . esc_url( get_permalink( $id ) ) . '" data-g="' . esc_attr( $g ) . '"><div class="im">' . $img . '</div><div class="meta"><div><h3 class="t-h3 c-ink">' . esc_html( get_the_title( $id ) ) . '</h3><div class="t-small c-text">' . esc_html( $kart ) . '</div></div><span class="ar">→</span></div></a>';
}
function np_perde_ids( $n = 20, $exclude = 0 ) {
	return get_posts( array( 'post_type' => 'perde', 'posts_per_page' => $n, 'orderby' => 'menu_order', 'order' => 'ASC', 'fields' => 'ids', 'post__not_in' => $exclude ? array( $exclude ) : array() ) );
}
function np_perde_grid( $args = array() ) {
	$a   = wp_parse_args( $args, array( 'n' => 20, 'cols' => 3, 'fixed' => false, 'masonry' => false, 'filter' => false, 'exclude' => 0, 'ids' => null ) );
	$ids = is_array( $a['ids'] ) ? $a['ids'] : np_perde_ids( $a['n'], $a['exclude'] );
	$gid = 'pg' . wp_rand( 100, 999 );
	$h   = '';
	if ( $a['filter'] ) {
		$terms = get_terms( array( 'taxonomy' => 'perde_grubu', 'hide_empty' => true ) );
		$h    .= '<div class="filter" data-grid="' . $gid . '"><button type="button" class="on" data-g="">Tümü</button>';
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$h .= '<button type="button" data-g="' . esc_attr( $t->slug ) . '">' . esc_html( $t->name ) . '</button>';
			}
		}
		$h .= '</div>';
	}
	$h .= '<div id="' . $gid . '" class="g-perde' . ( $a['masonry'] ? ' mas' : '' ) . '" style="--c:' . (int) $a['cols'] . '">';
	foreach ( $ids as $id ) {
		$h .= np_card_perde( $id, $a['fixed'] );
	}
	return $h . '</div>';
}
function np_bolge_ids( $exclude = 0 ) {
	return get_posts( array( 'post_type' => 'bolge', 'posts_per_page' => 40, 'orderby' => 'menu_order', 'order' => 'ASC', 'fields' => 'ids', 'post__not_in' => $exclude ? array( $exclude ) : array() ) );
}
function np_bolge_links( $exclude = 0 ) {
	$h = '<div class="g-blink">';
	foreach ( np_bolge_ids( $exclude ) as $id ) {
		$h .= '<a class="np-card c-blink" href="' . esc_url( get_permalink( $id ) ) . '"><h3 class="t-h3 c-ink">' . esc_html( get_the_title( $id ) ) . '</h3><span class="ar">→</span></a>';
	}
	return $h . '</div>';
}
function np_bolge_cards() {
	$h = '<div class="g-bolge">';
	foreach ( np_bolge_ids() as $id ) {
		$kisa = function_exists( 'get_field' ) ? (string) get_field( 'kisa_ad', $id ) : get_the_title( $id );
		$kart = function_exists( 'get_field' ) ? (string) get_field( 'kart_metni', $id ) : '';
		$h   .= '<a class="np-card c-bolge" href="' . esc_url( get_permalink( $id ) ) . '"><div class="t-eyebrow c-plum">' . esc_html( $kisa ) . '</div><h3 class="t-h3 c-ink">' . esc_html( get_the_title( $id ) ) . ' perde ve perdeci</h3><div class="t-small c-text">' . esc_html( $kart ) . '</div><div class="go">Bölge sayfası →</div></a>';
	}
	return $h . '</div>';
}
function np_rehber_grid( $n = 3, $exclude = 0, $paged = false ) {
	$page = $paged ? max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) ) : 1;
	$q    = new WP_Query( array( 'post_type' => 'post', 'posts_per_page' => $n, 'paged' => $page, 'ignore_sticky_posts' => true, 'post__not_in' => $exclude ? array( $exclude ) : array() ) );
	$h    = '<div class="g-rehber">';
	foreach ( $q->posts as $p ) {
		$img = get_the_post_thumbnail( $p->ID, 'medium_large', array( 'sizes' => '(max-width:767px) 100vw, (max-width:1024px) 50vw, 420px' ) );
		$h  .= '<a class="np-card c-rehber" href="' . esc_url( get_permalink( $p ) ) . '"><div class="im">' . $img . '</div><div class="t-eyebrow c-plum">Perde rehberi</div><h3 class="t-h3 c-ink">' . esc_html( get_the_title( $p ) ) . '</h3></a>';
	}
	$h .= '</div>';
	if ( $paged && $q->max_num_pages > 1 ) {
		$h .= '<nav class="pager" aria-label="Sayfalar">' . paginate_links( array( 'total' => $q->max_num_pages, 'current' => $page, 'prev_next' => false, 'type' => 'plain' ) ) . '</nav>';
	}
	return $h;
}

function np_crumb( $parent_title, $parent_path, $title ) {
	return '<nav class="crumb" aria-label="Sayfa yolu"><span><a href="' . esc_url( home_url( '/' ) ) . '">Ana sayfa</a> / <a href="' . esc_url( np_url( $parent_path ) ) . '">' . esc_html( $parent_title ) . '</a> /</span><b>' . esc_html( $title ) . '</b></nav>';
}

/** ACF tekrarlayıcı satırlarını [[a, b], ...] dizisine çevirir. */
function np_rep( $field, $a, $b, $id = false ) {
	$rows = function_exists( 'get_field' ) ? get_field( $field, $id ) : array();
	$out  = array();
	if ( is_array( $rows ) ) {
		foreach ( $rows as $r ) {
			if ( ! empty( $r[ $a ] ) ) {
				$out[] = array( $r[ $a ], $r[ $b ] ?? '' );
			}
		}
	}
	return $out;
}
