<?php
/**
 * Yapılandırılmış veri: işletme (her sayfada), rehber yazıları için BlogPosting + sayfa yolu.
 * Perde ve bölge sayfalarının Service / SSS şemaları yazının kendi kaydında durur.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function np_opening_hours() {
	$t = (string) np_opt( 'calisma_saatleri' );
	if ( ! preg_match( '/(\d{1,2})[:.](\d{2})\s*[–\-]\s*(\d{1,2})[:.](\d{2})/u', $t, $m ) ) {
		return null;
	}
	$days = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
	$low  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $t ) : strtolower( $t );
	if ( false !== strpos( $low, 'her gün' ) || preg_match( '/[–\-]\s*pazar(\s|$)/u', $low ) ) {
		$days[] = 'Sunday';
	} elseif ( preg_match( '/[–\-]\s*cuma(\s|$)/u', $low ) ) {
		array_pop( $days );
	}
	return array( '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $days, 'opens' => sprintf( '%02d:%s', $m[1], $m[2] ), 'closes' => sprintf( '%02d:%s', $m[3], $m[4] ) );
}

function np_business_schema() {
	$home = home_url( '/' );
	$b    = array(
		'@context'    => 'https://schema.org',
		'@type'       => 'HomeAndConstructionBusiness',
		'@id'         => $home . '#isletme',
		'name'        => np_opt( 'marka_adi' ),
		'url'         => $home,
		'description' => np_opt( 'marka_tanitim' ),
		'telephone'   => '+' . np_digits( np_opt( 'telefon' ) ),
		'priceRange'  => '₺₺',
	);
	$img = get_the_post_thumbnail_url( (int) get_option( 'page_on_front' ), 'large' );
	if ( ! $img ) {
		$img = wp_get_attachment_image_url( np_media_id( 'izmir-tul-perde-rayli-salon' ), 'large' );
	}
	if ( $img ) {
		$b['image'] = $img;
	}
	$logo = np_opt( 'logo' );
	if ( $logo ) {
		$b['logo'] = wp_get_attachment_image_url( is_array( $logo ) ? $logo['ID'] : (int) $logo, 'full' );
	}
	if ( np_opt( 'eposta' ) ) {
		$b['email'] = np_opt( 'eposta' );
	}
	if ( np_opt( 'adres' ) ) {
		$b['address'] = array( '@type' => 'PostalAddress', 'streetAddress' => np_opt( 'adres' ), 'addressCountry' => 'TR' );
	}
	if ( np_opt( 'harita_link' ) ) {
		$b['hasMap'] = np_opt( 'harita_link' );
	}
	$h = np_opening_hours();
	if ( $h ) {
		$b['openingHoursSpecification'] = $h;
	}
	$area = array( array( '@type' => 'City', 'name' => 'İzmir' ) );
	foreach ( np_bolge_ids() as $id ) {
		$area[] = array( '@type' => 'AdministrativeArea', 'name' => get_the_title( $id ) . ', İzmir' );
	}
	$b['areaServed'] = $area;
	$same            = array_values( array_filter( array( np_opt( 'instagram' ), np_opt( 'facebook' ) ) ) );
	if ( $same ) {
		$b['sameAs'] = $same;
	}
	return $b;
}

add_action( 'wp_head', function () {
	$out = array( np_business_schema() );
	if ( is_singular( 'post' ) ) {
		$id    = get_queried_object_id();
		$url   = get_permalink( $id );
		$out[] = array(
			'@context' => 'https://schema.org',
			'@graph'   => array(
				array(
					'@type'            => 'BlogPosting',
					'@id'              => $url . '#yazi',
					'headline'         => get_the_title( $id ),
					'description'      => wp_strip_all_tags( get_the_excerpt( $id ) ),
					'image'            => get_the_post_thumbnail_url( $id, 'full' ),
					'datePublished'    => get_post_time( 'c', true, $id ),
					'dateModified'     => get_post_modified_time( 'c', true, $id ),
					'mainEntityOfPage' => $url,
					'author'           => array( '@id' => home_url( '/' ) . '#isletme' ),
					'publisher'        => array( '@id' => home_url( '/' ) . '#isletme' ),
				),
				array(
					'@type'           => 'BreadcrumbList',
					'itemListElement' => array(
						array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Ana sayfa', 'item' => home_url( '/' ) ),
						array( '@type' => 'ListItem', 'position' => 2, 'name' => 'Perde rehberi', 'item' => home_url( '/rehber/' ) ),
						array( '@type' => 'ListItem', 'position' => 3, 'name' => get_the_title( $id ), 'item' => $url ),
					),
				),
			),
		);
		$faq = json_decode( (string) get_post_meta( $id, '_np_faq', true ), true );
		if ( is_array( $faq ) && $faq ) {
			$q = array();
			foreach ( $faq as $f ) {
				$q[] = array( '@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $f[1] ) );
			}
			$out[] = array( '@context' => 'https://schema.org', '@type' => 'FAQPage', '@id' => $url . '#sss', 'mainEntity' => $q );
		}
	}
	foreach ( $out as $s ) {
		echo '<script type="application/ld+json">' . wp_json_encode( $s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
	}
}, 20 );
