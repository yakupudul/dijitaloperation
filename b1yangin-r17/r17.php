<?php
/**
 * B1: category page layout, round 26 Nov 2026.
 * Product category pages: hero (own title, subtitle, correct buttons) + specification table(s) + photo slider, then the product grid.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function b1r17_is_tr() {
	return function_exists( 'pll_current_language' ) && 'tr' === pll_current_language();
}

function b1r17_specs() {
	$s = array(
		18  => array( 'sub' => 'Special purpose firefighting vehicles for industrial sites, refineries and high-risk facilities.' ),
		46  => array( 'sub' => 'Endüstriyel tesisler, rafineriler ve yüksek riskli alanlar için özel amaçlı itfaiye araçları.' ),
		129 => array(
			'sub'   => 'High-capacity water tankers for urban, industrial and large-scale firefighting operations.',
			'extra' => array( 390, 146, 147, 148, 149 ),
		),
		131 => array(
			'sub'   => 'Şehir içi, endüstriyel ve büyük ölçekli yangın müdahaleleri için yüksek kapasiteli su tankerleri.',
			'extra' => array( 390, 146, 147, 148, 149 ),
		),
	);
	foreach ( b1r17_term_products() as $tid => $ids ) {
		if ( ! isset( $s[ $tid ] ) ) {
			$s[ $tid ] = array();
		}
		$s[ $tid ]['products'] = $ids;
	}
	return $s;
}

/* Vehicles of each category (EN and TR), in the order they appear in the tables. */
function b1r17_term_products() {
	return array(
		17  => array( 413, 414 ),
		56  => array( 451, 452 ),
		19  => array( 160 ),
		37  => array( 438 ),
		20  => array( 164 ),
		51  => array( 444 ),
		18  => array( 418, 417, 415, 416, 162 ),
		46  => array( 456, 455, 453, 454, 442 ),
		121 => array( 418 ),
		123 => array( 456 ),
		125 => array( 417, 415, 416 ),
		127 => array( 455, 453, 454 ),
		129 => array( 162 ),
		131 => array( 442 ),
		7   => array( 166, 168, 169 ),
		61  => array( 446, 448, 449 ),
	);
}

/* Old vehicle page paths → the category that now carries their data. */
function b1r17_moved() {
	return array(
		'/products/light-duty-rapid-intervention-trucks'      => 17,
		'/products/pickup-rapid-intervention-trucks'          => 17,
		'/products/municipal-fire-trucks'                     => 19,
		'/products/aerial-ladders'                            => 20,
		'/products/hazmat-response-vehicle'                   => 121,
		'/products/industrial-water-tender-4x2'               => 125,
		'/products/industrial-water-tender-6x4'               => 125,
		'/products/industrial-water-tender-8x4'               => 125,
		'/products/tanker-type-firefighting-vehicle'          => 129,
		'/products/stratus'                                   => 7,
		'/products/stratus-pb'                                => 7,
		'/products/hose-reel-systems'                         => 7,
		'/tr/products/hafif-sinif-hizli-mudahale-araclari'    => 56,
		'/tr/products/pickup-tipi-hizli-mudahale-araclari'    => 56,
		'/tr/products/belediye-itfaiye-araclari'              => 37,
		'/tr/products/hidrolik-merdivenler'                   => 51,
		'/tr/products/tehlikeli-madde-mudahale-araci-hazmat'  => 123,
		'/tr/products/endustriyel-su-ikmal-araci-4x2'         => 127,
		'/tr/products/endustriyel-su-ikmal-araci-6x4'         => 127,
		'/tr/products/endustriyel-su-ikmal-araci-8x4'         => 127,
		'/tr/products/su-tankeri-tipi-itfaiye-araci'          => 131,
		'/tr/products/stratus-tr'                             => 61,
		'/tr/products/stratus-pb-tr'                          => 61,
		'/tr/products/cikrik-sistemleri'                      => 61,
		'/product-category/fire-trucks/industrial-vehicles/tanker-vehicles' => 129,
	);
}

function b1r17_txt( $h ) {
	$h = preg_replace( '#<br\s*/?>#i', ' ', $h );
	return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $h ), ENT_QUOTES, 'UTF-8' ) ) );
}

function b1r17_alias( $label ) {
	$k = mb_strtolower( $label, 'UTF-8' );
	$m = array(
		'drive'                => 'Drive Train',
		'drive train'          => 'Drive Train',
		'water tank'           => 'Water Tank Capacity',
		'tank capacity'        => 'Water Tank Capacity',
		'water tank capacity'  => 'Water Tank Capacity',
		'foam tank'            => 'Foam Tank Capacity',
		'foam tank capacity'   => 'Foam Tank Capacity',
		'material / finish'    => 'Material',
		'tahrik'               => 'Tahrik Sistemi',
		'tahrik sistemi'       => 'Tahrik Sistemi',
		'su tankı'             => 'Su Tankı Kapasitesi',
		'tank kapasitesi'      => 'Su Tankı Kapasitesi',
		'su tankı kapasitesi'  => 'Su Tankı Kapasitesi',
		'köpük tankı'          => 'Köpük Tankı Kapasitesi',
		'köpük tankı kapasitesi' => 'Köpük Tankı Kapasitesi',
		'maks. debi'           => 'Azami Debi',
		'maks. basınç'         => 'Azami Basınç',
		'malzeme / yüzey'      => 'Malzeme',
		'köpük dozajlama sistemi' => 'Köpük Dozajlama',
	);
	return $m[ $k ] ?? $label;
}

/* Reads a vehicle page: intro text, specification rows, option rows, lists. */
function b1r17_parse( $pid, $tr ) {
	$c   = (string) get_post_field( 'post_content', $pid );
	$out = array( 'spec' => array(), 'opt' => array() );
	$parts = preg_split( '#<h2[^>]*>(.*?)</h2>#is', $c, -1, PREG_SPLIT_DELIM_CAPTURE );
	$intro = isset( $parts[0] ) ? b1r17_txt( $parts[0] ) : '';
	if ( '' !== $intro ) {
		$out['spec'][ $tr ? 'Genel Bakış' : 'Overview' ] = $intro;
	}
	for ( $i = 1; $i < count( $parts ); $i += 2 ) {
		$head = b1r17_txt( $parts[ $i ] );
		$body = $parts[ $i + 1 ] ?? '';
		$key  = preg_match( '/option|opsiyon|seçenek/iu', $head ) ? 'opt' : 'spec';
		if ( preg_match_all( '#<tr[^>]*>\s*<th[^>]*>(.*?)</th>\s*<td[^>]*>(.*?)</td>#is', $body, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $r ) {
				$l = b1r17_alias( b1r17_txt( $r[1] ) );
				if ( ! isset( $out[ $key ][ $l ] ) ) {
					$out[ $key ][ $l ] = b1r17_txt( $r[2] );
				}
			}
		} elseif ( preg_match_all( '#<li[^>]*>(.*?)</li>#is', $body, $m ) ) {
			$out[ $key ][ $head ] = implode( ' · ', array_map( 'b1r17_txt', $m[1] ) );
		} elseif ( '' !== b1r17_txt( $body ) ) {
			$out[ $key ][ $head ] = b1r17_txt( $body );
		}
	}
	return $out;
}

/* Tables for a category, built from its vehicle pages. */
function b1r17_tables( $term, $ids, $tr ) {
	$data = array();
	foreach ( $ids as $pid ) {
		if ( get_post( $pid ) ) {
			$data[ $pid ] = b1r17_parse( $pid, $tr );
		}
	}
	if ( ! $data ) {
		return array( array(), false );
	}
	if ( 1 === count( $data ) ) {
		$d = reset( $data );
		$t = array();
		foreach ( array( 'spec' => $tr ? 'Teknik özellikler' : 'Technical specifications', 'opt' => $tr ? 'Opsiyonlar' : 'Options' ) as $k => $title ) {
			if ( $d[ $k ] ) {
				$rows = array();
				foreach ( $d[ $k ] as $l => $v ) {
					$rows[] = array( $l, $v );
				}
				$t[] = array( 'cols' => array( esc_html( $title ) ), 'rows' => $rows );
			}
		}
		return array( $t, count( $t ) > 1 );
	}
	$t = array();
	foreach ( array( 'spec' => $tr ? 'Teknik özellikler' : 'Technical specifications', 'opt' => $tr ? 'Opsiyonlar' : 'Options' ) as $k => $title ) {
		$labels = array();
		foreach ( $data as $d ) {
			foreach ( array_keys( $d[ $k ] ) as $l ) {
				$labels[ $l ] = 1;
			}
		}
		if ( ! $labels ) {
			continue;
		}
		$cols = array( esc_html( $title ) );
		foreach ( array_keys( $data ) as $pid ) {
			$cols[] = esc_html( get_the_title( $pid ) );
		}
		$rows = array();
		foreach ( array_keys( $labels ) as $l ) {
			$r = array( $l );
			foreach ( $data as $d ) {
				$r[] = $d[ $k ][ $l ] ?? '–';
			}
			$rows[] = $r;
		}
		$t[] = array( 'cols' => $cols, 'rows' => $rows );
	}
	return array( $t, false );
}

/* Photos for the slider: the vehicles of the category, round-robin so each vehicle shows early. */
function b1r17_slides( $ids, $extra = array() ) {
	$lists = array();
	foreach ( $ids as $pid ) {
		if ( ! get_post( $pid ) ) {
			continue;
		}
		$im = array();
		$f  = (int) get_post_thumbnail_id( $pid );
		if ( $f ) {
			$im[] = $f;
		}
		$g = function_exists( 'get_field' ) ? get_field( 'gallery', $pid, false ) : get_post_meta( $pid, 'gallery', true );
		if ( is_string( $g ) && '' !== $g ) {
			$g = maybe_unserialize( $g );
		}
		if ( is_array( $g ) ) {
			foreach ( $g as $x ) {
				$x = is_array( $x ) ? (int) ( $x['ID'] ?? $x['id'] ?? 0 ) : (int) $x;
				if ( $x ) {
					$im[] = $x;
				}
			}
		}
		$lists[] = array( 'title' => get_the_title( $pid ), 'ids' => array_values( array_unique( $im ) ) );
	}
	if ( $extra && $lists ) {
		$lists[0]['ids'] = array_values( array_unique( array_merge( $lists[0]['ids'], array_map( 'intval', $extra ) ) ) );
	}
	$out  = array();
	$seen = array();
	for ( $round = 0; $round < 20 && count( $out ) < 20; $round++ ) {
		foreach ( $lists as $l ) {
			if ( isset( $l['ids'][ $round ] ) && empty( $seen[ $l['ids'][ $round ] ] ) && count( $out ) < 20 ) {
				$id          = $l['ids'][ $round ];
				$seen[ $id ] = 1;
				$big         = wp_get_attachment_image_url( $id, 'large' );
				if ( ! $big ) {
					continue;
				}
				$out[] = array(
					'src'   => $big,
					'full'  => wp_get_attachment_image_url( $id, 'full' ),
					'blur'  => wp_get_attachment_image_url( $id, 'medium' ),
					'title' => $l['title'],
				);
			}
		}
	}
	return $out;
}

function b1r17_table_html( $t, $pair ) {
	$cols = $t['cols'];
	$n    = $pair ? 2 : count( $cols );
	$h    = '<div class="b1x-tw' . ( $pair ? '' : ' b1x-tw-cmp' ) . '"><table class="b1x-t' . ( $n > 2 ? ' b1x-t-wide' : '' ) . '"><thead><tr>';
	if ( $pair ) {
		$h .= '<th colspan="2" scope="colgroup">' . $cols[0] . '</th>';
	} else {
		foreach ( $cols as $i => $c ) {
			$h .= '<th scope="col"' . ( 0 === $i ? ' class="b1x-c0"' : '' ) . '>' . $c . '</th>';
		}
	}
	$h .= '</tr></thead><tbody>';
	foreach ( $t['rows'] as $r ) {
		$h .= '<tr><th scope="row">' . esc_html( $r[0] ) . '</th>';
		if ( 2 === count( $r ) && $n > 2 ) {
			$h .= '<td colspan="' . ( $n - 1 ) . '">' . esc_html( $r[1] ) . '</td>';
		} else {
			for ( $i = 1; $i < count( $r ); $i++ ) {
				$h .= '<td>' . esc_html( $r[ $i ] ) . '</td>';
			}
		}
		$h .= '</tr>';
	}
	return $h . '</tbody></table></div>';
}

function b1r17_block( $term, $cfg ) {
	$tr  = b1r17_is_tr();
	$ids = $cfg['products'] ?? array();
	list( $tables, $pair ) = b1r17_tables( $term, $ids, $tr );
	$h = '<div class="b1x"><div class="b1x-in">';
	if ( $tables ) {
		$h .= '<div class="b1x-specs' . ( $pair ? ' b1x-pair' : '' ) . '">';
		foreach ( $tables as $t ) {
			$h .= b1r17_table_html( $t, 1 === count( $t['cols'] ) );
		}
		$h .= '</div>';
	}
	$slides = b1r17_slides( $ids, $cfg['extra'] ?? array() );
	if ( $slides ) {
		$h .= '<div class="b1x-car" data-b1x-car><div class="b1x-track" tabindex="0" aria-label="' . esc_attr( ( $tr ? 'Fotoğraflar: ' : 'Photos: ' ) . $term->name ) . '">';
		foreach ( $slides as $sl ) {
			$h .= '<a class="b1x-slide" href="' . esc_url( $sl['full'] ? $sl['full'] : $sl['src'] ) . '" target="_blank" rel="noopener">'
				. '<span class="b1x-ph" style="background-image:url(\'' . esc_url( $sl['blur'] ? $sl['blur'] : $sl['src'] ) . '\')"></span>'
				. '<img src="' . esc_url( $sl['src'] ) . '" alt="' . esc_attr( $sl['title'] ) . '" loading="lazy" decoding="async"></a>';
		}
		$h .= '</div>'
			. '<button type="button" class="b1x-nav prev" aria-label="' . ( $tr ? 'Önceki' : 'Previous' ) . '"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg></button>'
			. '<button type="button" class="b1x-nav next" aria-label="' . ( $tr ? 'Sonraki' : 'Next' ) . '"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg></button>'
			. '<div class="b1x-dots" aria-hidden="true"></div></div>';
	}
	return $h . '</div></div>';
}

/* Hero: category name as the title, own subtitle, buttons to the right category in each language. */
function b1r17_hero( $hero, $term, $cfg ) {
	$tr   = b1r17_is_tr();
	$hero = preg_replace( '#(<h1 class="elementor-heading-title[^"]*">).*?(</h1>)#s', '${1}' . esc_html( $term->name ) . '${2}', $hero, 1 );
	if ( ! empty( $cfg['sub'] ) ) {
		$hero = preg_replace( '#(elementor-widget-text-editor.*?<p>).*?(</p>)#s', '${1}' . esc_html( $cfg['sub'] ) . '${2}', $hero, 1 );
	}
	$btn = $tr ? array( 56, 37, 51, 46, 61 ) : array( 17, 19, 20, 18, 7 );
	foreach ( $btn as $i => $tid ) {
		$link = get_term_link( $tid, 'product_category' );
		if ( is_wp_error( $link ) ) {
			continue;
		}
		$cur  = ( (int) $term->term_id === $tid );
		$hero = preg_replace_callback(
			'#(elementor-element-abc000' . $i . '\b[^>]*>\s*<a class="elementor-button[^"]*")( href=")[^"]*(")#s',
			function ( $m ) use ( $link, $cur ) {
				return str_replace( 'class="elementor-button', 'class="elementor-button' . ( $cur ? ' b1x-on' : '' ), $m[1] ) . $m[2] . esc_url( $link ) . $m[3] . ( $cur ? ' aria-current="page"' : '' );
			},
			$hero,
			1
		);
	}
	return $hero;
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_tax( 'product_category' ) ) {
		return;
	}
	$term = get_queried_object();
	if ( ! $term || empty( $term->term_id ) ) {
		return;
	}
	$all = b1r17_specs();
	$cfg = $all[ $term->term_id ] ?? array();
	ob_start( function ( $html ) use ( $term, $cfg ) {
		$a = strpos( $html, '<div class="elementor-element elementor-element-c81cf70 ' );
		if ( false === $a ) {
			return $html;
		}
		if ( ! preg_match( '#<div class="elementor-element elementor-element-[0-9a-z]+ [^"]*\be-parent\b#', $html, $m, PREG_OFFSET_CAPTURE, $a + 40 ) ) {
			return $html;
		}
		$b    = $m[0][1];
		$hero = b1r17_hero( substr( $html, $a, $b - $a ), $term, $cfg );
		$add  = ! empty( $cfg['products'] ) ? b1r17_block( $term, $cfg ) : '';
		return substr( $html, 0, $a ) . $hero . $add . substr( $html, $b );
	} );
}, 3 );

/* Vehicle pages are closed: old addresses go to their category; links to them across the site point there too. */
function b1r17_path() {
	$p    = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
	$base = (string) wp_parse_url( home_url(), PHP_URL_PATH );
	if ( $base && 0 === strpos( $p, $base ) ) {
		$p = substr( $p, strlen( $base ) );
	}
	return '/' . trim( $p, '/' );
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_preview() ) {
		return;
	}
	$path  = b1r17_path();
	$moved = b1r17_moved();
	if ( isset( $moved[ $path ] ) && ( is_404() || is_singular( 'product' ) || is_tax( 'product_category' ) ) ) {
		$l = get_term_link( $moved[ $path ], 'product_category' );
		if ( ! is_wp_error( $l ) ) {
			wp_safe_redirect( $l, 301 );
			exit;
		}
	}
	if ( is_tax( 'product_category' ) ) {
		$t = get_queried_object();
		if ( $t && in_array( (int) $t->term_id, array( 6, 34 ), true ) ) {
			wp_safe_redirect( home_url( 34 === (int) $t->term_id ? '/tr/products/' : '/products/' ), 301 );
			exit;
		}
	}
}, 0 );

add_action( 'template_redirect', function () {
	if ( is_admin() || wp_doing_ajax() ) {
		return;
	}
	ob_start( function ( $html ) {
		$home = untrailingslashit( home_url() );
		$map  = array();
		foreach ( b1r17_moved() as $path => $tid ) {
			$l = get_term_link( $tid, 'product_category' );
			if ( ! is_wp_error( $l ) ) {
				$map[ 'href="' . $home . $path . '/"' ] = 'href="' . esc_url( $l ) . '"';
			}
		}
		return strtr( $html, $map );
	} );
}, 4 );

add_action( 'wp_head', function () {
	if ( ! is_tax( 'product_category' ) ) {
		return;
	}
	?>
<style id="b1-r17">
.elementor-element-c81cf70 .elementor-button.b1x-on{background:#fff!important;color:#1c1c1c!important;border-color:#fff!important}
.tax-product_category .elementor-element-c81cf70{--min-height:0px;min-height:0!important;padding-top:140px!important;padding-bottom:56px!important}
.b1x{width:100%;padding:48px 48px 80px;box-sizing:border-box;background:#fff}
.b1x-in{max-width:1344px;margin:0 auto;display:flex;flex-direction:column;gap:56px}
.tax-product_category .elementor-element-3b5f245{display:none!important}
.b1x-specs{display:grid;gap:28px;width:100%}
.b1x-pair{grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);align-items:start}
.b1x-tw{width:100%;overflow-x:auto;border:1px solid #dfe2e6;border-radius:12px;background:#fff;box-shadow:0 10px 30px -18px rgba(16,24,40,.25)}
.b1x-t{width:100%;border-collapse:collapse;font-size:15px;line-height:1.45;color:#1c1c1c;margin:0;border:0}
.b1x-t-wide{min-width:860px}
.b1x-tw-cmp tbody th{width:22%}
.b1x-t thead th{background:#24282d;color:#fff;font-weight:600;font-size:16px;line-height:1.3;text-align:left;padding:16px 20px;border:0;border-left:1px solid #3a3f45;vertical-align:middle}
.b1x-t thead th:first-child{border-left:0}
.b1x-tw-cmp thead th{text-align:left}
.b1x-tw-cmp thead th.b1x-c0{text-align:left;font-size:15px;letter-spacing:.02em}
.b1x-t tbody th{background:#f3f4f6;font-weight:600;text-align:left;padding:11px 20px;width:30%;border:0;border-top:1px solid #e5e7eb;vertical-align:top}
.b1x-t tbody td{padding:11px 20px;border:0;border-top:1px solid #e5e7eb;border-left:1px solid #e5e7eb;background:#fff;vertical-align:top}
.b1x-tw-cmp tbody td{text-align:left;min-width:180px}
.b1x-tw-cmp tbody td[colspan]{text-align:left}
.b1x-t tbody tr:hover td{background:#fafafa}
.b1x-car{position:relative;width:100%}
.b1x-track{display:flex;gap:16px;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;scrollbar-width:none;padding:2px 0 4px;outline:0}
.b1x-track::-webkit-scrollbar{display:none}
.b1x-slide{position:relative;flex:0 0 calc((100% - 48px)/4);aspect-ratio:4/3;border-radius:12px;overflow:hidden;scroll-snap-align:start;background:#e9ebee;display:block}
.b1x-ph{position:absolute;inset:-20px;background-size:cover;background-position:center;filter:blur(18px) brightness(.9);transform:scale(1.08)}
.b1x-slide img{position:relative;width:100%;height:100%;object-fit:contain;display:block;transition:transform .5s ease}
.b1x-slide:hover img{transform:scale(1.03)}
.b1x-car .b1x-nav{position:absolute;top:calc(50% - 14px);transform:translateY(-50%);z-index:3;width:48px;height:48px;min-width:0;border-radius:50%!important;border:0!important;background:#fff!important;color:#1c1c1c!important;box-shadow:0 6px 20px rgba(0,0,0,.22);display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0!important;transition:opacity .2s ease,color .2s ease}
.b1x-car .b1x-nav:hover,.b1x-car .b1x-nav:focus{background:#fff!important;color:#ed1c24!important}
.b1x-car .b1x-nav.prev{left:-24px}.b1x-car .b1x-nav.next{right:-24px}
.b1x-car .b1x-nav[disabled]{opacity:0;pointer-events:none}
.b1x-dots{display:flex;justify-content:center;gap:8px;margin-top:18px;min-height:8px}
.b1x-dots span{width:8px;height:8px;border-radius:50%;background:#d1d5db;cursor:pointer;transition:background .2s,transform .2s}
.b1x-dots span.on{background:#ed1c24;transform:scale(1.15)}
@media(max-width:1024px){.b1x{padding:40px 32px 64px}.b1x-pair{grid-template-columns:1fr}.b1x-slide{flex-basis:calc((100% - 32px)/3)}.b1x-car .b1x-nav.prev{left:6px}.b1x-car .b1x-nav.next{right:6px}}
@media(max-width:767px){.tax-product_category .elementor-element-c81cf70{padding-top:110px!important;padding-bottom:40px!important}.b1x{padding:28px 20px 56px}.b1x-in{gap:36px}.b1x-t{font-size:14px}.b1x-t thead th{padding:13px 14px;font-size:14px}.b1x-t tbody th,.b1x-t tbody td{padding:10px 14px}.b1x-t-wide{min-width:700px}.b1x-t-wide tbody th,.b1x-t-wide thead th.b1x-c0{position:sticky;left:0;z-index:1;width:124px;min-width:124px;max-width:124px;box-shadow:1px 0 0 #e5e7eb}.b1x-slide{flex-basis:82%}.b1x-car .b1x-nav{width:40px;height:40px}}
</style>
	<?php
}, 100 );

add_action( 'wp_footer', function () {
	if ( ! is_tax( 'product_category' ) ) {
		return;
	}
	?>
<script id="b1-r17-car">
(function(){document.querySelectorAll('[data-b1x-car]').forEach(function(c){
var t=c.querySelector('.b1x-track'),p=c.querySelector('.prev'),n=c.querySelector('.next'),d=c.querySelector('.b1x-dots');
function pages(){return Math.max(1,Math.ceil((t.scrollWidth-4)/t.clientWidth));}
function draw(){d.innerHTML='';var k=pages();if(k<2)return;for(var i=0;i<k;i++){var s=document.createElement('span');s.dataset.i=i;s.onclick=function(){t.scrollTo({left:this.dataset.i*t.clientWidth,behavior:'smooth'});};d.appendChild(s);}upd();}
function upd(){p.disabled=t.scrollLeft<8;n.disabled=t.scrollLeft+t.clientWidth>=t.scrollWidth-8;var s=d.children,i=n.disabled?s.length-1:Math.min(Math.round(t.scrollLeft/t.clientWidth),s.length-1);for(var j=0;j<s.length;j++){s[j].className=j===i?'on':'';}}
p.onclick=function(){t.scrollBy({left:-t.clientWidth,behavior:'smooth'});};
n.onclick=function(){t.scrollBy({left:t.clientWidth,behavior:'smooth'});};
t.addEventListener('scroll',upd,{passive:true});window.addEventListener('resize',draw);draw();upd();
});})();
</script>
	<?php
}, 99 );
