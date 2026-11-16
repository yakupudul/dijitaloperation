<?php
/**
 * B1: revision round 16 Nov 2026 (Meral Ünür e-mail of 15 Nov).
 * Persistent front-end helpers + a one-off data run (b1r16_run), started with ?b1r16=<token>.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------- 1. Footer mailto: Elementor encodes [b1_eposta] in links as %5Bb1_eposta%5D ---------- */
add_action( 'template_redirect', function () {
	if ( is_admin() || wp_doing_ajax() ) {
		return;
	}
	ob_start( function ( $html ) {
		return str_replace( array( '%5Bb1_eposta%5D', '%5Bb1_tesis_alani%5D' ), array( '[b1_eposta]', '[b1_tesis_alani]' ), $html );
	} );
}, 1 );

/* ---------- 2. Product category pages: own hero photo + grouped sub-categories ---------- */
function b1r16_terms() {
	$t = get_option( 'b1_r16_terms' );
	return is_array( $t ) ? $t : array();
}

add_action( 'wp_head', function () {
	$css = '.elementor-social-icon-x-twitter{background-color:#000!important}'
		. '.elementor-field-group-licenses .elementor-field-subgroup{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px 18px}'
		. '.elementor-field-group-licenses .elementor-field-subgroup .elementor-field-option{margin:0!important;width:auto!important}'
		. '.b1-grp{grid-column:1/-1;display:flex;align-items:baseline;justify-content:space-between;gap:16px;padding:28px 0 6px;border-top:1px solid #d9dce1;margin-top:8px}'
		. '.b1-grp:first-of-type{border-top:0;padding-top:0;margin-top:0}'
		. '.b1-grp h2{margin:0;font-size:26px;line-height:1.2;font-weight:600;color:#1c1c1c;letter-spacing:-.01em}'
		. '.b1-grp a{font-size:15px;font-weight:500;color:#ed1c24;text-decoration:none;white-space:nowrap}'
		. '.b1-grp a:hover{text-decoration:underline}'
		. '.b1-scroll-nav{position:absolute;top:50%;transform:translateY(-50%);z-index:5;width:52px;height:52px;border-radius:50%;border:0;background:#fff;color:#1c1c1c;box-shadow:0 6px 20px rgba(0,0,0,.28);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:opacity .2s ease,transform .2s ease}'
		. '.b1-scroll-nav:hover{transform:translateY(-50%) scale(1.06)}'
		. '.b1-scroll-nav[disabled]{opacity:0;pointer-events:none}'
		. '.b1-scroll-nav.prev{left:12px}.b1-scroll-nav.next{right:12px}'
		. '@media(max-width:767px){.b1-scroll-nav{width:42px;height:42px}.b1-grp h2{font-size:21px}}';
	if ( is_tax( 'product_category' ) ) {
		$term = get_queried_object();
		$map  = get_option( 'b1_r16_hero' );
		if ( $term && is_array( $map ) && ! empty( $map[ $term->term_id ] ) ) {
			$url = wp_get_attachment_image_url( (int) $map[ $term->term_id ], 'full' );
			if ( $url ) {
				$sel  = '.elementor-element-c81cf70:not(.elementor-motion-effects-element-type-background),.elementor-element-c81cf70>.elementor-motion-effects-container>.elementor-motion-effects-layer';
				$css .= $sel . '{background-image:url("' . esc_url( $url ) . '")!important}';
			}
		}
		$kids = $term ? get_terms( array( 'taxonomy' => 'product_category', 'parent' => $term->term_id, 'hide_empty' => true ) ) : array();
		if ( is_array( $kids ) && count( $kids ) > 1 ) {
			usort( $kids, 'b1r16_term_cmp' );
			foreach ( $kids as $i => $k ) {
				$css .= '.elementor-loop-container>.product_category-' . sanitize_html_class( $k->slug ) . '{order:' . ( ( $i + 1 ) * 100 + 1 ) . '}';
			}
		}
	}
	echo '<style id="b1-r16">' . $css . '</style>' . "\n";
}, 99 );

function b1r16_term_cmp( $a, $b ) {
	$oa = (int) get_term_meta( $a->term_id, 'b1_order', true );
	$ob = (int) get_term_meta( $b->term_id, 'b1_order', true );
	return $oa === $ob ? strcmp( $a->name, $b->name ) : $oa - $ob;
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_tax( 'product_category' ) ) {
		return;
	}
	$term = get_queried_object();
	$kids = $term ? get_terms( array( 'taxonomy' => 'product_category', 'parent' => $term->term_id, 'hide_empty' => true ) ) : array();
	if ( ! is_array( $kids ) || count( $kids ) < 2 ) {
		return;
	}
	usort( $kids, 'b1r16_term_cmp' );
	$tr    = function_exists( 'pll_current_language' ) && 'tr' === pll_current_language();
	$heads = '';
	foreach ( $kids as $i => $k ) {
		$heads .= '<div class="b1-grp" role="presentation" style="order:' . ( ( $i + 1 ) * 100 ) . '"><h2>' . esc_html( $k->name ) . '</h2><a href="' . esc_url( get_term_link( $k ) ) . '">' . ( $tr ? 'Tümünü gör' : 'View all' ) . ' &rarr;</a></div>';
	}
	ob_start( function ( $html ) use ( $heads ) {
		$needle = '<div class="elementor-loop-container elementor-grid" role="list">';
		$pos    = strpos( $html, $needle );
		if ( false === $pos ) {
			return $html;
		}
		return substr_replace( $html, $needle . $heads, $pos, strlen( $needle ) );
	} );
}, 2 );

/* ---------- 3. Home: arrows on the sideways product slider ---------- */
add_action( 'wp_footer', function () {
	if ( ! is_front_page() && ! is_page( array( 258, 431 ) ) ) {
		return;
	}
	?>
<script id="b1-r16-scroll">
(function(){
var s=document.querySelector('.elementor-element-7f1a435');if(!s)return;
var w=s.parentElement;if(getComputedStyle(w).position==='static'){w.style.position='relative';}
var tr=document.documentElement.lang.indexOf('tr')===0;
function mk(c,l,d){var b=document.createElement('button');b.type='button';b.className='b1-scroll-nav '+c;b.setAttribute('aria-label',l);b.innerHTML='<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="'+d+'"/></svg>';w.appendChild(b);return b;}
var p=mk('prev',tr?'Önceki':'Previous','M15 18l-6-6 6-6'),n=mk('next',tr?'Sonraki':'Next','M9 18l6-6-6-6');
function step(){var c=s.querySelector(':scope > .e-con, :scope > .e-con-inner > .e-con');return c?c.getBoundingClientRect().width+16:s.clientWidth*0.8;}
p.onclick=function(){s.scrollBy({left:-step(),behavior:'smooth'});};
n.onclick=function(){s.scrollBy({left:step(),behavior:'smooth'});};
function upd(){p.disabled=s.scrollLeft<8;n.disabled=s.scrollLeft+s.clientWidth>=s.scrollWidth-8;}
s.addEventListener('scroll',upd,{passive:true});window.addEventListener('resize',upd);upd();
})();
</script>
	<?php
}, 99 );

/* ---------- 4. One-off data run ---------- */
add_action( 'init', function () {
	if ( empty( $_GET['b1r16'] ) || 'k7Qm2xR9' !== $_GET['b1r16'] ) {
		return;
	}
	if ( get_option( 'b1_r16_done' ) && empty( $_GET['show'] ) ) {
		wp_send_json( array( 'already' => get_option( 'b1_r16_report' ) ) );
	}
	if ( ! empty( $_GET['show'] ) ) {
		wp_send_json( get_option( 'b1_r16_report' ) );
	}
	if ( ! add_option( 'b1_r16_lock', time(), '', false ) ) {
		if ( (int) get_option( 'b1_r16_lock' ) > time() - 600 ) {
			wp_send_json( array( 'locked' => true ) );
		}
		update_option( 'b1_r16_lock', time(), false );
	}
	@set_time_limit( 900 );
	ignore_user_abort( true );
	$rep = array();
	try {
		$rep = b1r16_run();
		update_option( 'b1_r16_done', time(), false );
	} catch ( \Throwable $e ) {
		$rep['fatal'] = $e->getMessage() . ' @' . $e->getLine();
	}
	update_option( 'b1_r16_report', $rep, false );
	delete_option( 'b1_r16_lock' );
	wp_send_json( $rep );
}, 120 );

function b1r16_load( $pid ) {
	$raw = get_post_meta( $pid, '_elementor_data', true );
	$els = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
	if ( ! is_array( $els ) ) {
		return null;
	}
	add_option( 'b1_r16_bak_' . $pid, $raw, '', false );
	return $els;
}

function b1r16_save( $pid, $els ) {
	b1r16_strip_cache( $els );
	update_post_meta( $pid, '_elementor_data', wp_slash( wp_json_encode( $els ) ) );
	delete_post_meta( $pid, '_elementor_css' );
	delete_post_meta( $pid, '_elementor_element_cache' );
}

function b1r16_strip_cache( &$els ) {
	foreach ( $els as &$el ) {
		if ( is_array( $el ) ) {
			unset( $el['htmlCache'] );
			if ( ! empty( $el['elements'] ) ) {
				b1r16_strip_cache( $el['elements'] );
			}
		}
	}
	unset( $el );
}

/** Calls $cb( &$el ) for every element; $cb returns 'remove' to drop it. Returns hit count. */
function b1r16_walk( &$els, $cb ) {
	$n = 0;
	foreach ( $els as $k => &$el ) {
		$r = $cb( $el );
		if ( 'remove' === $r ) {
			unset( $els[ $k ] );
			$n++;
			continue;
		}
		if ( $r ) {
			$n++;
		}
		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			$n += b1r16_walk( $el['elements'], $cb );
		}
	}
	unset( $el );
	$els = array_values( $els );
	return $n;
}

function b1r16_swap_media( &$v, $from, $to_id, $to_url ) {
	$n = 0;
	if ( ! is_array( $v ) ) {
		return 0;
	}
	if ( isset( $v['id'], $v['url'] ) && (int) $v['id'] === (int) $from ) {
		$v['id']  = (int) $to_id;
		$v['url'] = $to_url;
		return 1;
	}
	foreach ( $v as &$x ) {
		$n += b1r16_swap_media( $x, $from, $to_id, $to_url );
	}
	unset( $x );
	return $n;
}

function b1r16_text( &$v, $rules ) {
	$n = 0;
	if ( is_string( $v ) ) {
		foreach ( $rules as $r ) {
			$nv = preg_replace( $r[0], $r[1], $v, -1, $c );
			if ( $c && null !== $nv ) {
				$v  = $nv;
				$n += $c;
			}
		}
		return $n;
	}
	if ( is_array( $v ) ) {
		foreach ( $v as &$x ) {
			$n += b1r16_text( $x, $rules );
		}
		unset( $x );
	}
	return $n;
}

function b1r16_sideload( $url, $name, $title ) {
	$done = get_option( 'b1_r16_media' );
	$done = is_array( $done ) ? $done : array();
	if ( ! empty( $done[ $name ] ) && get_post( $done[ $name ] ) ) {
		return (int) $done[ $name ];
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$tmp = download_url( $url, 120 );
	if ( is_wp_error( $tmp ) ) {
		return $tmp->get_error_message();
	}
	$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), 0, $title );
	if ( is_wp_error( $id ) ) {
		@unlink( $tmp );
		return $id->get_error_message();
	}
	if ( preg_match( '/\.(jpe?g|png|webp)$/i', $name ) ) {
		update_post_meta( $id, '_wp_attachment_image_alt', $title );
	}
	$done[ $name ] = (int) $id;
	update_option( 'b1_r16_media', $done, false );
	return (int) $id;
}

function b1r16_run() {
	$rep  = array();
	$base = 'https://www.kralsoftware.com/newbyangn/';
	$mail = function_exists( 'b1_info' ) ? sanitize_email( b1_info( 'eposta' ) ) : 'info@b1fire.com';
	$maps = 'https://www.google.com/maps/place/B1+Yang%C4%B1n/@38.2300224,27.3020143,17z/data=!4m6!3m5!1s0x14b9601d5c455555:0xe3fd84e985ba9edc!8m2!3d38.2300224!4d27.3020143';
	$raw  = 'https://raw.githubusercontent.com/yakupudul/dijitaloperation/ac33af344c42c7c8e34c3569a3aaf001cded0d73/b1yangin-media/';

	/* --- media --- */
	$m = array(
		'toplu'   => b1r16_sideload( $raw . 'b1yangin-toplu-su-atisi.jpg', 'b1yangin-toplu-su-atisi.jpg', 'B1yangın fire trucks spraying water together' ),
		'misyon'  => b1r16_sideload( $raw . 'b1yangin-misyon-dsc04183.jpg', 'b1yangin-misyon-dsc04183.jpg', 'B1yangın fire truck in front of the B1 Fire factory' ),
	);
	$docs = array(
		'Ziyaretçi Aydınlatma Metni'                          => 'Ziyaretci-Aydinlatma-Metni.pdf',
		'Tedarikçi Çalışanı Aydınlatma Metni'                 => 'Tedarikci-CalisaniAydinlatma-Metni.pdf',
		'Tedarikçi Aydınlatma Metni'                          => 'Tedarikci-Aydinlatma-Metni.pdf',
		'Potansiyel Müşteri Aydınlatma Metni'                 => 'Potansiyel-Musteri-Aydinlatma-Metni.pdf',
		'Müşteri Çalışanı Aydınlatma Metni'                   => 'Musteri-Calisani-Aydinlatma-Metni.pdf',
		'Müşteri Aydınlatma Metni'                            => 'Musteri-Aydinlatma-Metni.pdf',
		'Kişisel Verilerin Korunması Genel Politikası'        => 'Kisisel-Verilerin-Korunmasi-Genel-Politikasi.pdf',
		'İnternet Sitesi Kullanıcıları İçin Aydınlatma Metni' => 'Internet-Sitesi-Kullanicilari-Icin-Aydinlatma-Metni.pdf',
		'İlgili Kişi Başvuru Formu'                           => 'Ilgili-Kisi-Basvuru-Formu.pdf',
		'Hizmet Sağlayıcı Çalışanı Aydınlatma Metni'          => 'Hizmet-Saglayici-Calisani-Aydinlatma-Metni.pdf',
		'Hizmet Sağlayıcı Aydınlatma Metni'                   => 'Hizmet-Saglayici-Aydinlatma-Metni.pdf',
		'Gizlilik Politikası'                                 => 'Gizlilik-Politikasi.pdf',
		'Çerez Politikası'                                    => 'Cerez-Politikasi-1.pdf',
		'Çalışan Aydınlatma Metni'                            => 'Calisan-Aydinlatma-Metni.pdf',
		'Çalışan Adayı Aydınlatma Metni'                      => 'Calisan-Adayi-Aydinlatma-Metni.pdf',
	);
	$doc_url = array();
	foreach ( $docs as $title => $file ) {
		$id = b1r16_sideload( 'https://www.b1fire.com/wp-content/uploads/2024/10/' . $file, 'b1yangin-kvkk-' . strtolower( $file ), $title );
		if ( is_int( $id ) ) {
			$doc_url[ $title ] = wp_get_attachment_url( $id );
		} else {
			$rep['doc_err'][ $title ] = $id;
		}
	}
	$rep['media'] = $m;
	$rep['docs']  = count( $doc_url );

	/* --- terms: hierarchy + industrial groups --- */
	$tax = 'product_category';
	foreach ( array( 17 => 6, 19 => 6, 20 => 6, 18 => 6, 56 => 34, 37 => 34, 51 => 34, 46 => 34 ) as $child => $parent ) {
		$r = wp_update_term( $child, $tax, array( 'parent' => $parent ) );
		$rep['parent'][ $child ] = is_wp_error( $r ) ? $r->get_error_message() : $parent;
	}
	$order = array( 17 => 1, 19 => 2, 20 => 3, 18 => 4, 56 => 1, 37 => 2, 51 => 3, 46 => 4 );
	foreach ( $order as $t => $o ) {
		update_term_meta( $t, 'b1_order', $o );
	}
	$groups = array(
		'hazmat'  => array( 'en' => array( 'Hazmat Vehicles', 'hazmat-vehicles' ), 'tr' => array( 'Tehlikeli Madde (HAZMAT) Araçları', 'hazmat-araclari' ), 'o' => 1, 'p' => array( 418 => 'en', 456 => 'tr' ) ),
		'tender'  => array( 'en' => array( 'Industrial Water Tenders', 'industrial-water-tenders' ), 'tr' => array( 'Endüstriyel Su İkmal Araçları', 'endustriyel-su-ikmal-araclari' ), 'o' => 2, 'p' => array( 416 => 'en', 417 => 'en', 415 => 'en', 454 => 'tr', 455 => 'tr', 453 => 'tr' ) ),
		'tanker'  => array( 'en' => array( 'Tanker Vehicles', 'tanker-vehicles' ), 'tr' => array( 'Tanker Tipi İtfaiye Araçları', 'tanker-tipi-itfaiye-araclari' ), 'o' => 3, 'p' => array( 162 => 'en', 442 => 'tr' ) ),
	);
	$made = array();
	foreach ( $groups as $g => $d ) {
		$ids = array();
		foreach ( array( 'en' => 18, 'tr' => 46 ) as $lang => $parent ) {
			$ex = get_term_by( 'slug', $d[ $lang ][1], $tax );
			if ( $ex ) {
				$tid = (int) $ex->term_id;
			} else {
				$r = wp_insert_term( $d[ $lang ][0], $tax, array( 'slug' => $d[ $lang ][1], 'parent' => $parent ) );
				if ( is_wp_error( $r ) ) {
					$rep['term_err'][ $g . $lang ] = $r->get_error_message();
					continue;
				}
				$tid = (int) $r['term_id'];
			}
			if ( function_exists( 'pll_set_term_language' ) ) {
				pll_set_term_language( $tid, $lang );
			}
			update_term_meta( $tid, 'b1_order', $d['o'] );
			$ids[ $lang ] = $tid;
		}
		if ( 2 === count( $ids ) && function_exists( 'pll_save_term_translations' ) ) {
			pll_save_term_translations( $ids );
		}
		foreach ( $d['p'] as $pid => $lang ) {
			if ( isset( $ids[ $lang ] ) ) {
				wp_set_object_terms( $pid, array( $ids[ $lang ] ), $tax, true );
			}
		}
		$made[ $g ] = $ids;
	}
	update_option( 'b1_r16_terms', $made, false );
	$rep['groups'] = $made;
	$hero = array( 6 => 118, 34 => 118, 17 => 395, 56 => 395, 19 => 383, 37 => 383, 20 => 151, 51 => 151, 18 => 388, 46 => 388, 7 => 400, 61 => 400 );
	foreach ( array( 'hazmat' => 411, 'tender' => 409, 'tanker' => 390 ) as $g => $mid ) {
		foreach ( (array) ( isset( $made[ $g ] ) ? $made[ $g ] : array() ) as $tid ) {
			$hero[ $tid ] = $mid;
		}
	}
	update_option( 'b1_r16_hero', $hero, false );

	/* --- products: RIV merged into the pickup page, galleries --- */
	foreach ( array( 165, 445 ) as $pid ) {
		$r = wp_update_post( array( 'ID' => $pid, 'post_status' => 'draft' ), true );
		$rep['draft'][ $pid ] = is_wp_error( $r ) ? $r->get_error_message() : 'ok';
	}
	$gal = array(
		414 => array( 'set' => array( 654, 655, 395, 396 ) ),
		452 => array( 'set' => array( 654, 655, 395, 396 ) ),
		417 => array( 'drop' => array( 383 ) ),
		455 => array( 'drop' => array( 383 ) ),
		415 => array( 'drop' => array( 389 ) ),
		453 => array( 'drop' => array( 389 ) ),
		166 => array( 'drop' => array( 400 ) ),
		446 => array( 'drop' => array( 400 ) ),
		168 => array( 'set' => array( 142, 143, 144, 145, 141 ), 'thumb' => 142 ),
		448 => array( 'set' => array( 142, 143, 144, 145, 141 ), 'thumb' => 142 ),
	);
	foreach ( $gal as $pid => $g ) {
		$cur = function_exists( 'get_field' ) ? get_field( 'gallery', $pid, false ) : get_post_meta( $pid, 'gallery', true );
		$cur = array_map( 'intval', is_array( $cur ) ? $cur : array() );
		$new = isset( $g['set'] ) ? $g['set'] : array_values( array_diff( $cur, $g['drop'] ) );
		if ( function_exists( 'update_field' ) ) {
			update_field( 'gallery', $new, $pid );
		} else {
			update_post_meta( $pid, 'gallery', $new );
		}
		if ( ! empty( $g['thumb'] ) ) {
			set_post_thumbnail( $pid, $g['thumb'] );
		}
		$rep['gallery'][ $pid ] = array( 'from' => $cur, 'to' => $new );
	}

	/* --- news: only Intersec and Interschutz --- */
	$news = array(
		181 => array( 'Intersec', 'intersec', 129, '<p>B1yangın takes part in Intersec, the international trade fair for security, safety and fire protection in Dubai, where it presents its fire trucks and firefighting components to visitors from around the world.</p>' ),
		182 => array( 'Interschutz', 'interschutz', 131, '<p>B1yangın takes part in Interschutz, the international trade fair for fire and rescue services, civil protection and safety in Hannover, where it presents its fire trucks and firefighting components.</p>' ),
		470 => array( 'Intersec', 'intersec-fuari', 129, '<p>B1yangın, Dubai’de düzenlenen güvenlik, emniyet ve yangından korunma fuarı Intersec’te itfaiye araçlarını ve yangınla mücadele ekipmanlarını dünyanın dört bir yanından gelen ziyaretçilere tanıtıyor.</p>' ),
		471 => array( 'Interschutz', 'interschutz-fuari', 131, '<p>B1yangın, Hannover’de düzenlenen itfaiye, kurtarma, sivil savunma ve güvenlik fuarı Interschutz’ta itfaiye araçlarını ve yangınla mücadele ekipmanlarını sergiliyor.</p>' ),
	);
	foreach ( $news as $pid => $n ) {
		add_option( 'b1_r16_post_bak_' . $pid, get_post( $pid, ARRAY_A ), '', false );
		$r = wp_update_post( array( 'ID' => $pid, 'post_title' => $n[0], 'post_name' => $n[1], 'post_content' => $n[3], 'post_excerpt' => wp_strip_all_tags( $n[3] ) ), true );
		set_post_thumbnail( $pid, $n[2] );
		$rep['news'][ $pid ] = is_wp_error( $r ) ? $r->get_error_message() : 'ok';
	}
	foreach ( array( 183, 472 ) as $pid ) {
		wp_update_post( array( 'ID' => $pid, 'post_status' => 'draft' ) );
		$rep['news'][ $pid ] = 'draft';
	}

	/* --- Elementor: language parity (TR media follows EN) --- */
	$keys = array( 'background_image', 'image', 'background_video_link', 'background_video_fallback' );
	foreach ( array( 258 => 431, 172 => 459, 176 => 462, 210 => 577 ) as $en => $tr ) {
		$src = get_post_meta( $en, '_elementor_data', true );
		$src = is_string( $src ) ? json_decode( $src, true ) : $src;
		$els = b1r16_load( $tr );
		if ( ! is_array( $src ) || ! is_array( $els ) ) {
			continue;
		}
		$by = array();
		b1r16_walk( $src, function ( &$el ) use ( &$by, $keys ) {
			foreach ( $keys as $k ) {
				if ( isset( $el['settings'][ $k ] ) && ! empty( $el['id'] ) ) {
					$by[ $el['id'] ][ $k ] = $el['settings'][ $k ];
				}
			}
			return false;
		} );
		$hits = b1r16_walk( $els, function ( &$el ) use ( $by ) {
			$id = isset( $el['id'] ) ? $el['id'] : '';
			$c  = 0;
			if ( isset( $by[ $id ] ) ) {
				foreach ( $by[ $id ] as $k => $v ) {
					if ( ! isset( $el['settings'][ $k ] ) || $el['settings'][ $k ] != $v ) {
						$el['settings'][ $k ] = $v;
						$c++;
					}
				}
			}
			return $c > 0;
		} );
		b1r16_save( $tr, $els );
		$rep['sync'][ $tr ] = $hits;
	}

	/* --- Elementor: element edits --- */
	$u = function ( $p ) use ( $base ) {
		return array( 'url' => $base . $p, 'is_external' => '', 'nofollow' => '' );
	};
	$item = function ( $id, $text, $link ) {
		return array( '_id' => $id, 'text' => $text, 'selected_icon' => array( 'value' => '', 'library' => '' ), 'link' => $link );
	};
	$x_icon = array( 'social_icon' => array( 'value' => 'fab fa-x-twitter', 'library' => 'fa-brands' ), '_id' => 'b1xtw01', 'link' => array( 'url' => 'https://x.com/b1yangin', 'is_external' => 'true', 'nofollow' => '', 'custom_attributes' => '' ) );
	$addr     = 'Yazıbaşı Mah. Gümaksan Sk. No:1, Torbalı / İzmir / Türkiye';
	$contact  = function ( $lang ) use ( $item, $maps ) {
		return array(
			$item( 'd5a179e', 'Yazıbaşı Mah. Gümaksan Sk. No:1, Torbalı / İzmir / Türkiye', array( 'url' => $maps, 'is_external' => 'on', 'nofollow' => '' ) ),
			$item( 'da0a142', '+90 232 281 12 32', array( 'url' => 'tel:+902322811232', 'is_external' => '', 'nofollow' => '' ) ),
			$item( '87171f4', '[b1_eposta]', array( 'url' => 'mailto:[b1_eposta]', 'is_external' => '', 'nofollow' => '' ) ),
		);
	};
	$cat_en = $base . 'wp-content/uploads/2026/11/B1-Fire-Catalog.pdf';
	$cat_tr = $base . 'wp-content/uploads/2026/11/B1_Fire_KatalogK_TR.pdf';
	$ext    = array( 'is_external' => 'on', 'nofollow' => '' );

	$edit = array(
		203 => array(
			'1b06c25' => array( 'icon_list' => array(
				$item( '802015b', 'About us', $u( 'company/about-us/' ) ),
				$item( 'ab9cc87', 'Mission &amp; Vision', $u( 'company/mission-vision/' ) ),
				$item( 'b1car01', 'Careers', $u( 'careers/' ) ),
				$item( '9713177', 'News', $u( 'news/' ) ),
				$item( 'd707bac', 'Contact us', $u( 'contact-us/' ) ),
			) ),
			'5e06eac' => array( 'icon_list' => $contact( 'en' ) ),
			'9293103' => array( '__append_social' => $x_icon ),
		),
		571 => array(
			'200e0bf' => array( 'icon_list' => array(
				$item( 'c100000', 'Hızlı Müdahale Araçları', $u( 'tr/product-category/hizli-mudahale-araclari-tr/' ) ),
				$item( 'c100001', 'Şehir İçi İtfaiye Araçları', $u( 'tr/product-category/sehir-ici-itfaiye-araclari-tr/' ) ),
				$item( 'c100002', 'Merdivenli İtfaiye Araçları', $u( 'tr/product-category/merdivenli-itfaiye-araclari-tr/' ) ),
				$item( 'c100003', 'Endüstriyel İtfaiye Araçları', $u( 'tr/product-category/endustriyel-itfaiye-araclari-tr/' ) ),
				$item( 'c100004', 'Yangınla Mücadele Ekipmanları', $u( 'tr/product-category/yanginla-mucadele-ekipmanlari-tr/' ) ),
				$item( 'c100005', 'Tüm ürünler', $u( 'tr/products/' ) ),
				$item( 'c100007', 'Kataloğu indir', array_merge( array( 'url' => $cat_tr ), $ext ) ),
			) ),
			'1b06c25' => array( 'icon_list' => array(
				$item( '802015b', 'Hakkımızda', $u( 'tr/hakkimizda/' ) ),
				$item( 'ab9cc87', 'Misyon ve Vizyon', $u( 'tr/misyon-ve-vizyon/' ) ),
				$item( 'b1car01', 'Kariyer', $u( 'tr/kariyer/' ) ),
				$item( '9713177', 'Haberler', $u( 'tr/haberler/' ) ),
				$item( 'd707bac', 'İletişim', $u( 'tr/iletisim/' ) ),
			) ),
			'5e06eac' => array( 'icon_list' => $contact( 'tr' ) ),
			'9293103' => array( '__append_social' => $x_icon ),
			'6e748ff' => array( 'link' => $u( 'tr/kvkk-tr/' ) ),
			'8a83d67' => array( 'title' => '© B1yangın. Tüm hakları saklıdır. Hazırlayan: <a href="https://directo.com.tr" >Directo</a>' ),
		),
		211 => array( '5cc3e11' => array( '__remove' => 1 ), '0614df1' => array( 'width' => array( 'unit' => '%', 'size' => 100, 'sizes' => array() ), '_flex_size' => 'grow' ), '1efa110' => array( 'columns' => 3, 'posts_per_page' => 3 ) ),
		578 => array( '5cc3e11' => array( '__remove' => 1 ), '0614df1' => array( 'width' => array( 'unit' => '%', 'size' => 100, 'sizes' => array() ), '_flex_size' => 'grow' ), '1efa110' => array( 'columns' => 3, 'posts_per_page' => 3 ) ),
		176 => array( 'b6faec5' => array( 'address' => 'B1 Yangın Güvenlik Ekipmanları Torbalı', 'zoom' => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ) ) ),
		462 => array( 'b6faec5' => array( 'address' => 'B1 Yangın Güvenlik Ekipmanları Torbalı', 'zoom' => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ) ) ),
	);
	$swaps = array(
		258 => array( 'da968f4' => array( 119, 393 ) ),
		431 => array( 'da968f4' => array( 119, 393 ) ),
		173 => array( '80ef23b' => array( 102, 'toplu' ) ),
		458 => array( '80ef23b' => array( 102, 'toplu' ) ),
		174 => array( 'b92a80a' => array( 103, 'misyon' ) ),
		461 => array( 'b92a80a' => array( 103, 'misyon' ) ),
		177 => array( 'e52ee54' => array( 106, 148 ) ),
		463 => array( 'e52ee54' => array( 106, 148 ) ),
	);
	foreach ( array_unique( array_merge( array_keys( $edit ), array_keys( $swaps ) ) ) as $pid ) {
		$els = b1r16_load( $pid );
		if ( ! is_array( $els ) ) {
			$rep['edit'][ $pid ] = 'no data';
			continue;
		}
		$ops = isset( $edit[ $pid ] ) ? $edit[ $pid ] : array();
		$sw  = isset( $swaps[ $pid ] ) ? $swaps[ $pid ] : array();
		$log = array();
		b1r16_walk( $els, function ( &$el ) use ( $ops, $sw, $m, &$log ) {
			$id = isset( $el['id'] ) ? $el['id'] : '';
			if ( isset( $ops[ $id ] ) ) {
				if ( ! empty( $ops[ $id ]['__remove'] ) ) {
					$log[ $id ] = 'removed';
					return 'remove';
				}
				foreach ( $ops[ $id ] as $k => $v ) {
					if ( '__append_social' === $k ) {
						$list = isset( $el['settings']['social_icon_list'] ) ? $el['settings']['social_icon_list'] : array();
						$has  = false;
						foreach ( $list as $it ) {
							if ( isset( $it['_id'] ) && $it['_id'] === $v['_id'] ) {
								$has = true;
							}
						}
						if ( ! $has ) {
							$list[] = $v;
						}
						$el['settings']['social_icon_list'] = $list;
					} else {
						$el['settings'][ $k ] = $v;
					}
				}
				$log[ $id ] = 'set';
			}
			if ( isset( $sw[ $id ] ) ) {
				list( $from, $to ) = $sw[ $id ];
				$to_id = is_string( $to ) ? ( isset( $m[ $to ] ) ? $m[ $to ] : 0 ) : $to;
				if ( is_int( $to_id ) && $to_id > 0 ) {
					$log[ $id ] = 'swap ' . b1r16_swap_media( $el, $from, $to_id, wp_get_attachment_url( $to_id ) );
				} else {
					$log[ $id ] = 'swap skipped';
				}
			}
			return false;
		} );
		b1r16_save( $pid, $els );
		$rep['edit'][ $pid ] = $log;
	}

	/* --- careers forms --- */
	$opt = function ( $f ) {
		return implode( "\n", $f );
	};
	$forms = array(
		610 => array(
			'pos' => array( 'Position Applied For', array( 'General Application', 'R&D', 'Procurement', 'Accounting' ) ),
			'lang' => array( 'Foreign Language', array( 'Select|', 'English', 'German', 'French', 'Spanish', 'Arabic', 'Russian', 'Other' ) ),
			'lang2' => 'Second Foreign Language (optional)',
			'lvl' => array( 'Level', array( 'Select|', 'Basic', 'Intermediate', 'Advanced', 'Native' ) ),
		),
		611 => array(
			'pos' => array( 'Başvurulan Pozisyon', array( 'Genel Başvuru', 'Ar-Ge', 'Satın Alma', 'Muhasebe' ) ),
			'lang' => array( 'Yabancı Dil', array( 'Seçiniz|', 'İngilizce', 'Almanca', 'Fransızca', 'İspanyolca', 'Arapça', 'Rusça', 'Diğer' ) ),
			'lang2' => 'İkinci Yabancı Dil (isteğe bağlı)',
			'lvl' => array( 'Seviye', array( 'Seçiniz|', 'Başlangıç', 'Orta', 'İleri', 'Ana dil' ) ),
		),
	);
	foreach ( $forms as $pid => $f ) {
		$els = b1r16_load( $pid );
		if ( ! is_array( $els ) ) {
			continue;
		}
		$log = '';
		b1r16_walk( $els, function ( &$el ) use ( $f, $opt, &$log ) {
			if ( ! isset( $el['id'] ) || 'careerf' !== $el['id'] || empty( $el['settings']['form_fields'] ) ) {
				return false;
			}
			$old = array();
			foreach ( $el['settings']['form_fields'] as $ff ) {
				$old[ $ff['custom_id'] ] = $ff;
			}
			$out  = array();
			$pick = function ( $cid, $patch = array() ) use ( $old ) {
				return isset( $old[ $cid ] ) ? array_merge( $old[ $cid ], $patch ) : null;
			};
			$out[] = $pick( 'position', array( 'field_label' => $f['pos'][0], 'field_options' => $opt( $f['pos'][1] ), 'required' => 'true' ) );
			$out[] = $pick( 'name', array( 'required' => 'true', 'width' => '50' ) );
			$out[] = $pick( 'email', array( 'required' => 'true', 'width' => '50' ) );
			$out[] = $pick( 'phone', array( 'required' => 'true', 'width' => '50' ) );
			$out[] = $pick( 'residence', array( 'width' => '50' ) );
			$out[] = $pick( 'education', array( 'width' => '50' ) );
			$out[] = $pick( 'school', array( 'width' => '50' ) );
			$out[] = array( 'custom_id' => 'language', 'field_type' => 'select', 'field_label' => $f['lang'][0], 'width' => '50', 'field_options' => $opt( $f['lang'][1] ) );
			$out[] = array( 'custom_id' => 'language_level', 'field_type' => 'select', 'field_label' => $f['lvl'][0], 'width' => '50', 'field_options' => $opt( $f['lvl'][1] ) );
			$out[] = array( 'custom_id' => 'language_2', 'field_type' => 'select', 'field_label' => $f['lang2'], 'width' => '50', 'field_options' => $opt( $f['lang'][1] ) );
			$out[] = array( 'custom_id' => 'language_2_level', 'field_type' => 'select', 'field_label' => $f['lvl'][0], 'width' => '50', 'field_options' => $opt( $f['lvl'][1] ) );
			$out[] = $pick( 'licenses', array( 'width' => '50' ) );
			$out[] = $pick( 'heavy_vehicle', array( 'width' => '50' ) );
			$out[] = $pick( 'software', array( 'width' => '100' ) );
			$out[] = $pick( 'certificates', array( 'width' => '100' ) );
			$out[] = $pick( 'cv', array( 'required' => 'true' ) );
			$cl    = $pick( 'cover_letter' );
			if ( $cl ) {
				unset( $cl['required'] );
				$out[] = $cl;
			}
			$out[] = $pick( 'kvkk', array( 'required' => 'true' ) );
			$out   = array_values( array_filter( $out ) );
			foreach ( $out as $i => &$ff ) {
				if ( empty( $ff['_id'] ) ) {
					$ff['_id'] = substr( md5( 'b1f' . $ff['custom_id'] ), 0, 7 );
				}
			}
			unset( $ff );
			$el['settings']['form_fields'] = $out;
			$log                           = count( $out ) . ' fields';
			return true;
		} );
		b1r16_save( $pid, $els );
		$rep['careers'][ $pid ] = $log;
	}

	/* --- text rules over every Elementor post (no revisions) --- */
	global $wpdb;
	$all = $wpdb->get_col( "SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_elementor_data' AND p.post_type <> 'revision'" );
	$q   = preg_quote( $base, '~' );
	$tr_rules = array(
		array( '~' . $q . 'wp-content/uploads/2026/1[01]/B1-Fire-Catalog(?:-EN-mobile)?\.pdf~', $cat_tr ),
		array( '~' . $q . 'wp-content/uploads/2026/10/B1-Yangin-Katalog-TR-mobile\.pdf~', $cat_tr ),
		array( '~' . $q . 'tr/ana-sayfa/tr/product-category/yanginla-mucadele-ekipmanlari/~', $base . 'tr/product-category/yanginla-mucadele-ekipmanlari-tr/' ),
		array( '~' . $q . 'tr/ana-sayfa/product-category/fire-trucks/~', $base . 'tr/product-category/itfaiye-araclari-tr/' ),
		array( '~' . $q . 'tr/ana-sayfa/tr/services/~', $base . 'tr/products/' ),
		array( '~' . $q . 'tr/ana-sayfa/tr/~', $base . 'tr/' ),
		array( '~' . $q . 'company/about-us/~', $base . 'tr/hakkimizda/' ),
		array( '~' . $q . 'company/mission-vision/~', $base . 'tr/misyon-ve-vizyon/' ),
		array( '~' . $q . 'kvkk/~', $base . 'tr/kvkk-tr/' ),
		array( '~' . $q . 'contact-us/~', $base . 'tr/iletisim/' ),
		array( '~' . $q . 'careers/~', $base . 'tr/kariyer/' ),
		array( '~' . $q . 'news/~', $base . 'tr/haberler/' ),
		array( '~' . $q . 'product-category/rapid-intervention-vehicles/~', $base . 'tr/product-category/hizli-mudahale-araclari-tr/' ),
		array( '~' . $q . 'product-category/urban-vehicles/~', $base . 'tr/product-category/sehir-ici-itfaiye-araclari-tr/' ),
		array( '~' . $q . 'product-category/aerial-ladders/~', $base . 'tr/product-category/merdivenli-itfaiye-araclari-tr/' ),
		array( '~' . $q . 'product-category/industrial-vehicles/~', $base . 'tr/product-category/endustriyel-itfaiye-araclari-tr/' ),
		array( '~' . $q . 'product-category/components/~', $base . 'tr/product-category/yanginla-mucadele-ekipmanlari-tr/' ),
		array( '~' . $q . 'product-category/fire-trucks/~', $base . 'tr/product-category/itfaiye-araclari-tr/' ),
		array( '~I have read the (<a [^>]*>)KVKK privacy notice</a> and consent to the processing of my personal data\.~', '$1KVKK aydınlatma metnini</a> okudum ve kişisel verilerimin işlenmesine onay veriyorum.' ),
	);
	$en_rules = array(
		array( '~' . $q . 'wp-content/uploads/2026/10/B1-Fire-Catalog-EN-mobile\.pdf~', $cat_en ),
		array( '~\s*Demo page: document links to be added\.~', '' ),
	);
	$mail_fix = 0;
	foreach ( $all as $pid ) {
		$pid  = (int) $pid;
		$lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $pid ) : '';
		$rules = ( 'tr' === $lang ) ? $tr_rules : $en_rules;
		$raw  = get_post_meta( $pid, '_elementor_data', true );
		$els  = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $els ) ) {
			continue;
		}
		$n = 0;
		b1r16_walk( $els, function ( &$el ) use ( $rules, &$n, $mail, &$mail_fix ) {
			if ( ! empty( $el['settings'] ) ) {
				$n += b1r16_text( $el['settings'], $rules );
				if ( isset( $el['widgetType'] ) && 'form' === $el['widgetType'] ) {
					foreach ( array( 'email_to', 'email_from', 'email_to_2', 'email_from_2', 'email_to_cc', 'email_to_bcc' ) as $k ) {
						if ( isset( $el['settings'][ $k ] ) && false !== strpos( $el['settings'][ $k ], '[b1_eposta]' ) ) {
							$el['settings'][ $k ] = str_replace( '[b1_eposta]', $mail, $el['settings'][ $k ] );
							$n++;
							$mail_fix++;
						}
					}
				}
			}
			return false;
		} );
		if ( $n ) {
			add_option( 'b1_r16_bak_' . $pid, $raw, '', false );
			b1r16_save( $pid, $els );
			$rep['text'][ $pid ] = $n;
		}
	}
	$rep['form_mail_fix'] = $mail_fix;

	/* --- KVKK document links --- */
	foreach ( array( 177, 463 ) as $pid ) {
		$els = b1r16_load( $pid );
		if ( ! is_array( $els ) ) {
			continue;
		}
		$c = b1r16_walk( $els, function ( &$el ) use ( $doc_url ) {
			if ( empty( $el['elType'] ) || 'container' !== $el['elType'] || empty( $el['elements'] ) ) {
				return false;
			}
			foreach ( $el['elements'] as $ch ) {
				$t = isset( $ch['settings']['title'] ) ? trim( wp_strip_all_tags( $ch['settings']['title'] ) ) : '';
				if ( isset( $ch['widgetType'] ) && 'heading' === $ch['widgetType'] && isset( $doc_url[ $t ] ) ) {
					$el['settings']['link']     = array( 'url' => $doc_url[ $t ], 'is_external' => 'on', 'nofollow' => '' );
					$el['settings']['html_tag'] = 'a';
					return true;
				}
			}
			return false;
		} );
		b1r16_save( $pid, $els );
		$rep['kvkk'][ $pid ] = $c;
	}

	/* --- catalog links stored outside Elementor (ACF / post meta / options) --- */
	$meta = $wpdb->get_results( "SELECT meta_id, post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_key <> '_elementor_data' AND meta_key NOT LIKE '\\_elementor%' AND (meta_value LIKE '%Catalog-EN-mobile%' OR meta_value LIKE '%Katalog-TR-mobile%')" );
	$rep['meta_left'] = $meta;

	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
	do_action( 'litespeed_purge_all' );
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
	return $rep;
}
