<?php
/**
 * İzmir Perde teması: sayfa oluşturucusuz, tek CSS dosyalı özel tema.
 * Marka adı, telefon, adres gibi bilgiler Firma Bilgileri ekranından (ACF) okunur; temada sabit marka yazısı yoktur.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NP_VER', '1.0.0' );
require get_template_directory() . '/inc/parts.php';
require get_template_directory() . '/inc/shortcodes.php';
require get_template_directory() . '/inc/schema.php';
require get_template_directory() . '/inc/seed.php';

/* ---------- kurulum ---------- */
add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
	register_nav_menus( array( 'menu-1' => 'Üstbilgi', 'menu-2' => 'Altbilgi' ) );
} );

/* ---------- gereksiz çıktıları kapat ---------- */
add_action( 'init', function () {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'wp_shortlink_wp_head' );
	remove_action( 'wp_head', 'rest_output_link_wp_head' );
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	remove_action( 'wp_head', 'feed_links_extra', 3 );
} );
add_action( 'wp_enqueue_scripts', function () {
	wp_dequeue_style( 'wp-block-library' );
	wp_dequeue_style( 'wp-block-library-theme' );
	wp_dequeue_style( 'global-styles' );
	wp_dequeue_style( 'classic-theme-styles' );
	wp_deregister_script( 'wp-embed' );
	wp_enqueue_style( 'np', get_stylesheet_uri(), array(), (string) filemtime( get_stylesheet_directory() . '/style.css' ) );
}, 100 );
remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
remove_action( 'wp_body_open', 'wp_global_styles_render_svg_filters' );

/* ---------- yazı tipi: Jost, sitenin kendi sunucusundan ---------- */
function np_font_url( $file, $remote ) {
	$up  = wp_upload_dir();
	$dir = trailingslashit( $up['basedir'] ) . 'np-fonts';
	if ( file_exists( $dir . '/' . $file ) ) {
		return trailingslashit( $up['baseurl'] ) . 'np-fonts/' . $file;
	}
	if ( ! get_transient( 'np_font_try' ) ) {
		set_transient( 'np_font_try', 1, DAY_IN_SECONDS );
		$res = wp_remote_get( $remote, array( 'timeout' => 10 ) );
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) && strlen( wp_remote_retrieve_body( $res ) ) > 5000 ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/' . $file, wp_remote_retrieve_body( $res ) ); // phpcs:ignore
			delete_transient( 'np_font_try' );
			if ( file_exists( $dir . '/' . $file ) ) {
				return trailingslashit( $up['baseurl'] ) . 'np-fonts/' . $file;
			}
		}
	}
	return $remote;
}
add_action( 'wp_head', function () {
	$lat = np_font_url( 'jost-latin.woff2', 'https://fonts.gstatic.com/s/jost/v20/92zatBhPNqw73oTd4g.woff2' );
	$ext = np_font_url( 'jost-latin-ext.woff2', 'https://fonts.gstatic.com/s/jost/v20/92zatBhPNqw73ord4iYl.woff2' );
	if ( false !== strpos( $lat . $ext, 'gstatic' ) ) {
		echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
	}
	echo '<link rel="preload" as="font" type="font/woff2" crossorigin href="' . esc_url( $lat ) . '">' . "\n";
	echo '<link rel="preload" as="font" type="font/woff2" crossorigin href="' . esc_url( $ext ) . '">' . "\n";
	echo '<style>@font-face{font-family:Jost;font-style:normal;font-weight:300 500;font-display:swap;src:url(' . esc_url( $ext ) . ') format("woff2");unicode-range:U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C4,U+2113,U+2C60-2C7F,U+A720-A7FF}'
		. '@font-face{font-family:Jost;font-style:normal;font-weight:300 500;font-display:swap;src:url(' . esc_url( $lat ) . ') format("woff2");unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}</style>' . "\n";
}, 2 );

/* ---------- Firma Bilgileri ekranı ---------- */
add_action( 'acf/init', function () {
	if ( ! function_exists( 'acf_add_options_page' ) ) {
		return;
	}
	if ( function_exists( 'acf_get_options_page' ) && acf_get_options_page( 'kurumsalbilgiler' ) ) {
		return;
	}
	acf_add_options_page( array(
		'page_title'      => 'Firma Bilgileri',
		'menu_title'      => 'Firma Bilgileri',
		'menu_slug'       => 'kurumsalbilgiler',
		'capability'      => 'edit_posts',
		'position'        => 2,
		'icon_url'        => 'dashicons-store',
		'redirect'        => false,
		'update_button'   => 'Kaydet',
		'updated_message' => 'Firma bilgileri kaydedildi. Sitenin tamamı yeni bilgilerle güncellendi.',
	) );
}, 20 );
add_action( 'acf/save_post', function ( $post_id ) {
	if ( 'options' === $post_id && function_exists( 'do_action' ) ) {
		do_action( 'litespeed_purge_all' );
	}
}, 20 );

/** Firma Bilgileri alanı: kayıtlı değer, yoksa alanın varsayılanı. */
function np_opt( $name ) {
	static $keys = array(
		'marka_adi' => 'field_np_marka_adi', 'marka_etiketi' => 'field_np_marka_etiket', 'marka_tanitim' => 'field_np_marka_tanitim',
		'logo' => 'field_np_logo', 'ust_bant' => 'field_np_ust_bant', 'deneyim' => 'field_np_deneyim', 'telefon' => 'field_np_telefon',
		'whatsapp' => 'field_np_whatsapp', 'whatsapp_mesaji' => 'field_np_wa_mesaj', 'calisma_saatleri' => 'field_np_calisma',
		'eposta' => 'field_np_eposta', 'adres' => 'field_np_adres', 'harita_link' => 'field_np_harita', 'instagram' => 'field_np_instagram',
		'facebook' => 'field_np_facebook', 'ga4_id' => 'field_np_ga4', 'ads_id' => 'field_np_ads', 'ads_wa_etiket' => 'field_np_ads_wa',
		'ads_tel_etiket' => 'field_np_ads_tel', 'gsc_dogrulama' => 'field_np_gsc',
	);
	static $cache = array();
	if ( array_key_exists( $name, $cache ) ) {
		return $cache[ $name ];
	}
	$v = '';
	if ( function_exists( 'get_field' ) ) {
		$v = get_field( $keys[ $name ] ?? $name, 'option' );
		if ( ( null === $v || '' === $v || false === $v ) && isset( $keys[ $name ] ) && function_exists( 'acf_get_field' ) ) {
			$f = acf_get_field( $keys[ $name ] );
			$v = is_array( $f ) ? ( $f['default_value'] ?? '' ) : '';
		}
	}
	if ( is_string( $v ) ) {
		$v = trim( $v );
	}
	$cache[ $name ] = $v;
	return $v;
}

/** 0535 516 07 19 → 905355160719 */
function np_digits( $phone ) {
	$d = preg_replace( '/\D+/', '', (string) $phone );
	if ( '' === $d ) {
		return '';
	}
	if ( 0 === strpos( $d, '00' ) ) {
		$d = substr( $d, 2 );
	}
	if ( '0' === $d[0] ) {
		$d = '9' . $d;
	} elseif ( 10 === strlen( $d ) ) {
		$d = '90' . $d;
	}
	return $d;
}
function np_tel_link() {
	return 'tel:+' . np_digits( np_opt( 'telefon' ) );
}
function np_wa_link( $msg = '' ) {
	$msg = $msg ? $msg : np_opt( 'whatsapp_mesaji' );
	$num = np_digits( np_opt( 'whatsapp' ) ? np_opt( 'whatsapp' ) : np_opt( 'telefon' ) );
	return 'https://wa.me/' . $num . ( $msg ? '?text=' . rawurlencode( $msg ) : '' );
}

/* ---------- içerik ---------- */
// Sayfa gövdeleri hazır HTML bölümlerdir; WordPress'in otomatik paragraf eklemesi düzeni bozmasın.
add_filter( 'the_content', function ( $c ) {
	if ( is_page() || is_singular( array( 'perde', 'bolge' ) ) ) {
		remove_filter( 'the_content', 'wpautop' );
		remove_filter( 'the_content', 'wptexturize' );
	}
	return $c;
}, 1 );
add_filter( 'use_block_editor_for_post_type', function ( $use, $type ) {
	return in_array( $type, array( 'page', 'perde', 'bolge' ), true ) ? false : $use;
}, 10, 2 );
add_filter( 'user_can_richedit', function ( $can ) {
	global $post;
	return ( $post && 'page' === $post->post_type ) ? false : $can;
} );
add_filter( 'excerpt_length', function () {
	return 28;
} );
add_filter( 'wp_get_attachment_image_attributes', function ( $attr ) {
	if ( empty( $attr['decoding'] ) ) {
		$attr['decoding'] = 'async';
	}
	return $attr;
} );

// Menüde alt menüsü olan başlığa ok.
add_filter( 'walker_nav_menu_start_el', function ( $out, $item, $depth, $args ) {
	if ( 0 === $depth && isset( $args->theme_location ) && 'menu-1' === $args->theme_location && in_array( 'menu-item-has-children', (array) $item->classes, true ) ) {
		$out = str_replace( '</a>', np_icon( 'down' ) . '</a>', $out );
	}
	return $out;
}, 10, 4 );

/* ---------- ölçüm (GA4, Google Ads, Search Console) ---------- */
add_action( 'wp_head', function () {
	$gsc = np_opt( 'gsc_dogrulama' );
	if ( $gsc ) {
		if ( preg_match( '/content=["\']([^"\']+)/', $gsc, $m ) ) {
			$gsc = $m[1];
		}
		echo '<meta name="google-site-verification" content="' . esc_attr( $gsc ) . '">' . "\n";
	}
}, 3 );
add_action( 'wp_footer', function () {
	$ga  = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) np_opt( 'ga4_id' ) );
	$ads = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) np_opt( 'ads_id' ) );
	$cfg = array(
		'ga'  => $ga,
		'ads' => $ads,
		'wa'  => preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) np_opt( 'ads_wa_etiket' ) ),
		'tel' => preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) np_opt( 'ads_tel_etiket' ) ),
	);
	?>
<script>
(function(){var d=document,h=d.querySelector('.hdr'),b=d.querySelector('.burger');
if(b)b.addEventListener('click',function(){var o=h.classList.toggle('open');b.setAttribute('aria-expanded',o?'true':'false')});
function mas(){d.querySelectorAll('.mas').forEach(function(g){var it=[].filter.call(g.children,function(e){return !e.hidden});it.forEach(function(e){e.style.marginTop=''});var cs=getComputedStyle(g),c=cs.gridTemplateColumns.split(' ').length,gap=parseFloat(cs.rowGap)||0,bot=[];if(c<2)return;it.forEach(function(e,i){var k=i%c;if(i>=c){var df=e.getBoundingClientRect().top-(bot[k]+gap);if(df>1)e.style.marginTop=-df+'px'}bot[k]=e.getBoundingClientRect().bottom})})}
if(d.querySelector('.mas')){mas();addEventListener('load',mas);d.addEventListener('load',function(e){if(e.target.tagName==='IMG'&&e.target.closest('.mas'))mas()},true);var t;addEventListener('resize',function(){clearTimeout(t);t=setTimeout(mas,120)});if(d.fonts&&d.fonts.ready)d.fonts.ready.then(mas)}
d.querySelectorAll('.filter').forEach(function(f){f.addEventListener('click',function(e){var x=e.target.closest('button');if(!x)return;f.querySelectorAll('button').forEach(function(y){y.classList.toggle('on',y===x)});var g=d.getElementById(f.dataset.grid),v=x.dataset.g;[].forEach.call(g.children,function(i){i.hidden=!!v&&i.dataset.g!==v});mas()})});
d.addEventListener('click',function(e){var a=e.target.closest('.sp-gal a');if(a){e.preventDefault();var l=d.createElement('div');l.className='lb';l.innerHTML='<img alt="" src="'+a.href+'">';l.onclick=function(){l.remove()};d.body.appendChild(l)}});
d.addEventListener('keydown',function(e){if(e.key==='Escape'){var l=d.querySelector('.lb');if(l)l.remove();if(h)h.classList.remove('open')}});
var C=<?php echo wp_json_encode( $cfg ); ?>;if(!C.ga&&!C.ads)return;
window.dataLayer=window.dataLayer||[];window.gtag=function(){dataLayer.push(arguments)};gtag('js',new Date());if(C.ga)gtag('config',C.ga);if(C.ads)gtag('config',C.ads);
var ld=0;function load(){if(ld)return;ld=1;var s=d.createElement('script');s.async=1;s.src='https://www.googletagmanager.com/gtag/js?id='+(C.ga||C.ads);d.head.appendChild(s)}
['scroll','pointerdown','keydown','touchstart'].forEach(function(ev){addEventListener(ev,load,{once:true,passive:true})});setTimeout(load,3500);
d.addEventListener('click',function(e){var a=e.target.closest('a[href]');if(!a)return;var k=a.href.indexOf('wa.me')>-1?'wa':(a.href.indexOf('tel:')===0?'tel':'');if(!k)return;load();
gtag('event',k==='wa'?'whatsapp_tikla':'telefon_tikla',{sayfa:location.pathname});if(C.ads&&C[k])gtag('event','conversion',{send_to:C.ads+'/'+C[k]})});
})();
</script>
	<?php
}, 30 );
