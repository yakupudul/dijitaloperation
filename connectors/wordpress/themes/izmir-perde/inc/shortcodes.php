<?php
/**
 * Sayfa içeriklerinde kullanılan kısa kodlar. Sayfa gövdesi düz HTML'dir; tekrar eden parçalar buradan gelir.
 * Bağlantılarda {site} yazılırsa sitenin adresine çevrilir (alan adı değişince bağlantılar bozulmaz).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'the_content', function ( $c ) {
	return str_replace( '{site}', untrailingslashit( home_url() ), $c );
}, 5 );

// [np ad="telefon"]: Firma Bilgileri alanı.
add_shortcode( 'np', function ( $a ) {
	$a = shortcode_atts( array( 'ad' => '' ), $a );
	return esc_html( (string) np_opt( $a['ad'] ) );
} );
add_shortcode( 'np_tel_url', function () {
	return esc_url( np_tel_link() );
} );
add_shortcode( 'np_wa_url', function () {
	return esc_url( np_wa_link() );
} );
add_shortcode( 'np_wa', function ( $a ) {
	$a = shortcode_atts( array( 'metin' => "WhatsApp'tan yazın", 'tur' => 'wa', 'sinif' => '', 'mesaj' => '' ), $a );
	return np_wa( $a['metin'], $a['tur'], $a['sinif'], $a['mesaj'] );
} );
add_shortcode( 'np_ara', function ( $a ) {
	$a = shortcode_atts( array( 'tur' => 'line', 'sinif' => '', 'metin' => '' ), $a );
	return np_call( $a['tur'], $a['sinif'], $a['metin'] );
} );
add_shortcode( 'np_dugmeler', function ( $a ) {
	$a = shortcode_atts( array( 'wa' => "WhatsApp'tan fiyat sorun", 'ara' => 'line', 'tur' => 'wa' ), $a );
	return np_btns( $a['wa'], $a['ara'], $a['tur'] );
} );
// [np_foto ad="dosya-adi" h="460" ht="400" hm="280"]: ortam kitaplığındaki görsel, sabit yükseklikte.
add_shortcode( 'np_foto', function ( $a ) {
	$a  = shortcode_atts( array( 'ad' => '', 'id' => 0, 'h' => 0, 'ht' => 0, 'hm' => 0, 'boyut' => 'large', 'oncelik' => '', 'sinif' => 'ph' ), $a );
	$id = (int) $a['id'] ? (int) $a['id'] : np_media_id( $a['ad'] );
	if ( ! $id ) {
		return '';
	}
	$attr = array( 'class' => $a['sinif'], 'sizes' => (int) $a['h'] ? '(max-width:1024px) 100vw, 60vw' : '100vw' );
	if ( (int) $a['h'] ) {
		$attr['style'] = '--h:' . (int) $a['h'] . 'px' . ( (int) $a['ht'] ? ';--ht:' . (int) $a['ht'] . 'px' : '' ) . ( (int) $a['hm'] ? ';--hm:' . (int) $a['hm'] . 'px' : '' );
	}
	if ( $a['oncelik'] ) {
		$attr['loading']       = 'eager';
		$attr['fetchpriority'] = 'high';
	}
	return np_img( $id, $a['boyut'], $attr );
} );
add_shortcode( 'np_perdeler', function ( $a ) {
	$a = shortcode_atts( array( 'adet' => 20, 'sutun' => 3, 'sabit' => 0, 'duvar' => 0, 'filtre' => 0 ), $a );
	return np_perde_grid( array( 'n' => (int) $a['adet'], 'cols' => (int) $a['sutun'], 'fixed' => (bool) $a['sabit'], 'masonry' => (bool) $a['duvar'], 'filter' => (bool) $a['filtre'] ) );
} );
add_shortcode( 'np_bolge_linkleri', function () {
	return np_bolge_links();
} );
add_shortcode( 'np_bolge_kartlari', function () {
	return np_bolge_cards();
} );
add_shortcode( 'np_rehber', function ( $a ) {
	$a = shortcode_atts( array( 'adet' => 3, 'sayfalama' => 0 ), $a );
	return np_rehber_grid( (int) $a['adet'], 0, (bool) $a['sayfalama'] );
} );
add_shortcode( 'np_olcu_bandi', function ( $a ) {
	$a = shortcode_atts( array( 'baslik' => '', 'alt' => '' ), $a );
	return np_measure_band( $a['baslik'], $a['alt'] );
} );
add_shortcode( 'np_son_cta', function ( $a ) {
	$a = shortcode_atts( array( 'baslik' => '', 'alt' => '' ), $a );
	return np_final_cta( $a['baslik'], $a['alt'] );
} );
add_shortcode( 'np_adimlar', function ( $a ) {
	$a = shortcode_atts( array( 'koyu' => 0 ), $a );
	return np_steps( (bool) $a['koyu'] );
} );
add_shortcode( 'np_oda_rehberi', function () {
	return np_room_guide();
} );
add_shortcode( 'np_bilgi_seridi', function () {
	return np_facts();
} );
