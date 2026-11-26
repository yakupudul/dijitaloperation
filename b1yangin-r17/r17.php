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
	$d   = '–';
	$s   = array();
	/* Rapid Intervention Vehicles */
	$s[17] = array(
		'tables' => array(
			array(
				'cols' => array( 'Vehicle category', 'Light Duty<br>Rapid Intervention Truck', 'Pickup<br>Rapid Intervention Truck' ),
				'rows' => array(
					array( 'Chassis', '4×2 or 4×4 light-duty chassis', 'Pickup-based (e.g. Isuzu D-MAX, Toyota Hilux)' ),
					array( 'Construction', 'Modular aluminium superstructure', 'Aluminium profile superstructure' ),
					array( 'Water Tank', 'Up to 3,000 L', 'Up to 300 L' ),
					array( 'Foam Tank', 'Optional', 'Up to 40 L' ),
					array( 'Fire Pump', 'High-pressure system', '42 L/min @100 bar (up to 300 L system)' ),
					array( 'Engine Power', '150–205 HP', '150–205 HP' ),
				),
			),
		),
	);
	$s[56] = array(
		'tables' => array(
			array(
				'cols' => array( 'Araç sınıfı', 'Hafif Sınıf<br>Hızlı Müdahale Aracı', 'Pickup Tipi<br>Hızlı Müdahale Aracı' ),
				'rows' => array(
					array( 'Şasi', '4×2 veya 4×4 hafif ticari şasi', 'Pickup tabanlı (ör. Isuzu D-MAX, Toyota Hilux)' ),
					array( 'Üstyapı', 'Modüler alüminyum üstyapı', 'Alüminyum profil üstyapı' ),
					array( 'Su Tankı', '3.000 L’ye kadar', '300 L’ye kadar' ),
					array( 'Köpük Tankı', 'Opsiyonel', '40 L’ye kadar' ),
					array( 'Yangın Pompası', 'Yüksek basınçlı sistem', '42 l/dk @100 bar (300 L’ye kadar sistem)' ),
					array( 'Motor Gücü', '150–205 HP', '150–205 HP' ),
				),
			),
		),
	);
	/* Urban Vehicles */
	$s[19] = array(
		'pair'   => true,
		'tables' => array(
			array(
				'cols' => array( 'Technical specifications' ),
				'rows' => array(
					array( 'GVW', '18 tons based on the requirements' ),
					array( 'Drive Train', '4×2' ),
					array( 'Power', 'From 280 HP up to 330 HP' ),
					array( 'Cabin', 'Double Cabin' ),
					array( 'Material', 'Aluminium frame' ),
					array( 'Water and Foam Tank Material', 'Stainless Steel' ),
					array( 'Water Tank Capacity', '4,500 Litres' ),
					array( 'Foam Tank Capacity', '500 Litres' ),
					array( 'Fire Pump', '3,000 lpm @10 bar & 250 lpm @40 bar' ),
					array( 'Foam Proportioning', '3%–6% manual around-the-pump foam proportioning' ),
					array( 'Discharge Capability', '2,400 lpm @7 bar' ),
					array( 'Rapid Intervention Hose Reel', '60 metres' ),
				),
			),
			array(
				'cols' => array( 'Options' ),
				'rows' => array(
					array( 'Drive Train', '4×4' ),
					array( 'Extendable Light Mast', 'Optional' ),
					array( 'Bumper Winch', 'Hydraulic or Electric' ),
					array( 'CAFS', 'Optional' ),
					array( 'Low Pressure Fire Pump', '2,000 lpm or 3,000 lpm @10 bar' ),
					array( 'High Pressure Fire Pump', '400 lpm @40 bar' ),
					array( 'Turret', 'Up to 3,500 lpm @7 bar, wired remote control or joystick' ),
					array( 'Firefighting Accessories and Rescue Equipment', 'Optional' ),
				),
			),
		),
	);
	$s[37] = array(
		'pair'   => true,
		'tables' => array(
			array(
				'cols' => array( 'Teknik özellikler' ),
				'rows' => array(
					array( 'Azami Yüklü Araç Ağırlığı', 'İhtiyaca göre 18 ton' ),
					array( 'Tahrik Sistemi', '4×2' ),
					array( 'Motor Gücü', '280–330 HP' ),
					array( 'Kabin', 'Çift kabin' ),
					array( 'Üstyapı', 'Alüminyum profil konstrüksiyon' ),
					array( 'Su ve Köpük Tankı Malzemesi', 'Paslanmaz çelik' ),
					array( 'Su Tankı Kapasitesi', '4.500 litre' ),
					array( 'Köpük Tankı Kapasitesi', '500 litre' ),
					array( 'Yangın Pompası', '3.000 l/dk @10 bar ve 250 l/dk @40 bar' ),
					array( 'Köpük Dozajlama Sistemi', '%3–%6 manuel pompa çevresi dozajlama sistemi' ),
					array( 'Deşarj Kapasitesi', '2.400 l/dk @7 bar' ),
					array( 'Hızlı Müdahale Hortum Makarası', '60 metre' ),
				),
			),
			array(
				'cols' => array( 'Opsiyonlar' ),
				'rows' => array(
					array( 'Tahrik Sistemi', '4×4' ),
					array( 'Teleskopik Aydınlatma Direği', 'Opsiyonel' ),
					array( 'Tampon Vinci', 'Hidrolik veya elektrikli' ),
					array( 'Basınçlı Hava Köpük Sistemi (CAFS)', 'Opsiyonel' ),
					array( 'Alçak Basınçlı Yangın Pompası', '2.000 veya 3.000 l/dk @10 bar' ),
					array( 'Yüksek Basınçlı Yangın Pompası', '400 l/dk @40 bar' ),
					array( 'Yangın Monitörü', '3.500 l/dk’ya kadar @7 bar; kablolu uzaktan kumanda veya joystick' ),
					array( 'Yangınla Mücadele ve Kurtarma Ekipmanları', 'Opsiyonel' ),
				),
			),
		),
	);
	/* Aerial Ladders */
	$s[20] = array(
		'pair'   => true,
		'tables' => array(
			array(
				'cols' => array( 'Technical specifications' ),
				'rows' => array(
					array( 'GVW', '18–33 tons based on the requirements' ),
					array( 'Drive Train', '4×2 / 6×2 / 6×4' ),
					array( 'Power', 'From 280 HP up to 360 HP' ),
					array( 'Cabin', 'Single Cabin' ),
					array( 'Rescue Height', 'From 18 metres up to 32 metres' ),
					array( 'Cage Capacity', '300 kg' ),
					array( 'Outriggers', 'H-type outriggers' ),
					array( 'Fire Pump', '3,000 lpm @10 bar' ),
					array( 'Platform Controls', 'From cage and main control panel' ),
					array( 'Water and Foam Tank Material', 'Stainless Steel' ),
					array( 'Pipeline', 'Aluminium telescopic pipeline' ),
					array( 'Water Tank Capacity', 'Up to 2,000 Litres (determined by the ladder capacity)' ),
					array( 'Foam Tank Capacity', 'Up to 200 Litres (determined by the ladder capacity)' ),
					array( 'Cage Monitor', 'Electrical, up to 3,500 lpm @7 bar' ),
				),
			),
			array(
				'cols' => array( 'Options' ),
				'rows' => array(
					array( 'Water and Foam Tank Material', 'GRP' ),
					array( 'Cage Capacity', '400 kg' ),
					array( 'Fire Pump', '4,000 lpm @10 bar' ),
				),
			),
		),
	);
	$s[51] = array(
		'pair'   => true,
		'tables' => array(
			array(
				'cols' => array( 'Teknik özellikler' ),
				'rows' => array(
					array( 'Azami Yüklü Araç Ağırlığı', 'İhtiyaca göre 18–33 ton' ),
					array( 'Tahrik Sistemi', '4×2 / 6×2 / 6×4' ),
					array( 'Motor Gücü', '280–360 HP' ),
					array( 'Kabin', 'Tek kabin' ),
					array( 'Kurtarma Yüksekliği', '18–32 metre' ),
					array( 'Sepet Kapasitesi', '300 kg' ),
					array( 'Denge Ayakları', 'H tipi' ),
					array( 'Yangın Pompası', '3.000 l/dk @10 bar' ),
					array( 'Platform Kumandaları', 'Sepetten ve ana kumanda panelinden' ),
					array( 'Su ve Köpük Tankı Malzemesi', 'Paslanmaz çelik' ),
					array( 'Su Hattı', 'Alüminyum teleskopik boru hattı' ),
					array( 'Su Tankı Kapasitesi', 'Merdiven kapasitesine bağlı olarak 2.000 litreye kadar' ),
					array( 'Köpük Tankı Kapasitesi', 'Merdiven kapasitesine bağlı olarak 200 litreye kadar' ),
					array( 'Sepet Monitörü', 'Elektrikli, 3.500 l/dk’ya kadar @7 bar' ),
				),
			),
			array(
				'cols' => array( 'Opsiyonlar' ),
				'rows' => array(
					array( 'Su ve Köpük Tankı Malzemesi', 'CTP' ),
					array( 'Sepet Kapasitesi', '400 kg' ),
					array( 'Yangın Pompası', '4.000 l/dk @10 bar' ),
				),
			),
		),
	);
	/* Industrial Vehicles */
	$s[18] = array(
		'sub'    => 'Special purpose firefighting vehicles for industrial sites, refineries and high-risk facilities.',
		'tables' => array(
			array(
				'cols' => array( 'Industrial vehicles – overview', 'Light Duty<br>Industrial Vehicles', 'Medium Duty<br>Industrial Vehicles', 'Heavy Duty<br>Industrial Vehicles', 'Hazmat Vehicles' ),
				'rows' => array(
					array( 'GVW', 'Up to 15 tons', '15 – 26 tons', '26 – 33+ tons', '18 – 33+ tons' ),
					array( 'Drive Train', '4×2', '4×2 / 6×2', '6×2 / 6×4 / 8×4', '4×2 / 6×2 / 6×4 / 8×4' ),
					array( 'Water Tank Capacity', 'Up to 3,000 L', '3,000 – 8,000 L', '8,000 – 20,000+ L', '2,000 – 8,000 L' ),
					array( 'Foam Tank Capacity', 'Up to 300 L', '300 – 1,000 L', '1,000 – 2,000 L', '300 – 1,000 L' ),
					array( 'Fire Pump', 'Up to 2,000 lpm @10 bar', '2,000 – 3,000 lpm @10 bar', '3,000 – 6,000 lpm @10 bar', '2,000 – 4,000 lpm @10 bar' ),
					array( 'Application', 'Local industrial & utility sites', 'Industrial plants & warehouses', 'Large industrial & refinery sites', 'Hazardous material response (CBRN / chemical / industrial)' ),
				),
			),
		),
	);
	$s[46] = array(
		'sub'    => 'Endüstriyel tesisler, rafineriler ve yüksek riskli alanlar için özel amaçlı itfaiye araçları.',
		'tables' => array(
			array(
				'cols' => array( 'Endüstriyel araçlar – genel bakış', 'Hafif Sınıf<br>Endüstriyel Araçlar', 'Orta Sınıf<br>Endüstriyel Araçlar', 'Ağır Sınıf<br>Endüstriyel Araçlar', 'Tehlikeli Madde<br>(HAZMAT) Araçları' ),
				'rows' => array(
					array( 'Azami Yüklü Araç Ağırlığı', '15 tona kadar', '15 – 26 ton', '26 – 33+ ton', '18 – 33+ ton' ),
					array( 'Tahrik Sistemi', '4×2', '4×2 / 6×2', '6×2 / 6×4 / 8×4', '4×2 / 6×2 / 6×4 / 8×4' ),
					array( 'Su Tankı Kapasitesi', '3.000 L’ye kadar', '3.000 – 8.000 L', '8.000 – 20.000+ L', '2.000 – 8.000 L' ),
					array( 'Köpük Tankı Kapasitesi', '300 L’ye kadar', '300 – 1.000 L', '1.000 – 2.000 L', '300 – 1.000 L' ),
					array( 'Yangın Pompası', '2.000 l/dk’ya kadar @10 bar', '2.000 – 3.000 l/dk @10 bar', '3.000 – 6.000 l/dk @10 bar', '2.000 – 4.000 l/dk @10 bar' ),
					array( 'Kullanım Alanı', 'Yerel endüstriyel tesisler ve altyapı alanları', 'Endüstriyel tesisler ve depolar', 'Büyük endüstriyel tesisler ve rafineriler', 'Tehlikeli madde müdahalesi (KBRN / kimyasal / endüstriyel)' ),
				),
			),
		),
	);
	/* Fire Tankers */
	$s[129] = array(
		'extra'  => array( 390, 146, 147, 148, 149 ),
		'sub'    => 'High-capacity water tankers for urban, industrial and large-scale firefighting operations.',
		'tables' => array(
			array(
				'cols' => array( 'Fire tankers – technical specifications', '4×2', '6×2', '6×4', '8×4' ),
				'rows' => array(
					array( 'GVW', 'Up to 18 tons', 'Up to 26 tons', 'Up to 32 tons', 'Up to 33+ tons' ),
					array( 'Drive Train', '4×2', '6×2', '6×4', '8×4' ),
					array( 'Power', 'From 280 HP', 'From 280 HP', 'From 300 HP', 'From 330–360 HP' ),
					array( 'Water Tank Capacity', 'Up to 6,000 L', 'Up to 12,000 L', 'Up to 16,000 L', 'Up to 20,000+ L' ),
					array( 'Foam Tank Capacity', 'Up to 500 L', 'Up to 1,000 L', 'Up to 1,500 L', 'Up to 2,000 L' ),
					array( 'Fire Pump', '3,000 lpm @10 bar', '3,000 lpm @10 bar', '3,000 lpm @10 bar', '4,000 lpm @10 bar' ),
					array( 'Foam Proportioning', '3% – 6% (manual or automatic)', '3% – 6% (manual or automatic)', '3% – 6% (manual or automatic)', '3% – 6% (manual or automatic)' ),
					array( 'Discharge Capacity (Monitor)', 'Up to 2,400 lpm @7 bar', 'Up to 3,000 lpm @7 bar', 'Up to 3,000 lpm @7 bar', 'Up to 3,500 lpm @7 bar' ),
					array( 'Construction', 'Aluminium or stainless steel tank, modular superstructure' ),
				),
			),
		),
	);
	$s[131] = array(
		'extra'  => array( 390, 146, 147, 148, 149 ),
		'sub'    => 'Şehir içi, endüstriyel ve büyük ölçekli yangın müdahaleleri için yüksek kapasiteli su tankerleri.',
		'tables' => array(
			array(
				'cols' => array( 'Tanker tipi itfaiye araçları – teknik özellikler', '4×2', '6×2', '6×4', '8×4' ),
				'rows' => array(
					array( 'Azami Yüklü Araç Ağırlığı', '18 tona kadar', '26 tona kadar', '32 tona kadar', '33+ tona kadar' ),
					array( 'Tahrik Sistemi', '4×2', '6×2', '6×4', '8×4' ),
					array( 'Motor Gücü', '280 HP’den başlayan', '280 HP’den başlayan', '300 HP’den başlayan', '330–360 HP' ),
					array( 'Su Tankı Kapasitesi', '6.000 L’ye kadar', '12.000 L’ye kadar', '16.000 L’ye kadar', '20.000+ L’ye kadar' ),
					array( 'Köpük Tankı Kapasitesi', '500 L’ye kadar', '1.000 L’ye kadar', '1.500 L’ye kadar', '2.000 L’ye kadar' ),
					array( 'Yangın Pompası', '3.000 l/dk @10 bar', '3.000 l/dk @10 bar', '3.000 l/dk @10 bar', '4.000 l/dk @10 bar' ),
					array( 'Köpük Dozajlama', '%3 – %6 (manuel veya otomatik)', '%3 – %6 (manuel veya otomatik)', '%3 – %6 (manuel veya otomatik)', '%3 – %6 (manuel veya otomatik)' ),
					array( 'Deşarj Kapasitesi (Monitör)', '2.400 l/dk’ya kadar @7 bar', '3.000 l/dk’ya kadar @7 bar', '3.000 l/dk’ya kadar @7 bar', '3.500 l/dk’ya kadar @7 bar' ),
					array( 'Üstyapı', 'Alüminyum veya paslanmaz çelik tank, modüler üstyapı' ),
				),
			),
		),
	);
	/* Components */
	$s[7] = array(
		'tables' => array(
			array(
				'cols' => array( 'Components – overview', 'Stratus', 'Stratus PB', 'Hose Reel Systems' ),
				'rows' => array(
					array( 'Type', 'Vehicle roof fire monitor', 'Portable fire monitor', 'Hose reel for firefighting vehicles' ),
					array( 'Max. Flow Rate', '3,500 lpm @7 bar', '3,000 L/min @10 bar', $d ),
					array( 'Max. Pressure', '16 bar', '16 bar', '40 bar' ),
					array( 'Drive', 'Electric motors 12 V / 24 V', 'Electric motor 24 V, remote control', 'Manual or electric' ),
					array( 'Inlet', '2½" NPT, 2½" BSPT, DN 80 flange', 'DN80 / 2.5" connections', '3/4", 1", 1 1/4" (NPT / BSP)' ),
					array( 'Material', 'Aluminium, hard anodized', 'Aluminium, hard anodized', 'Galvanized steel, epoxy coated' ),
				),
			),
		),
	);
	$s[61] = array(
		'tables' => array(
			array(
				'cols' => array( 'Ekipmanlar – genel bakış', 'Stratus', 'Stratus PB', 'Çıkrık Sistemleri' ),
				'rows' => array(
					array( 'Tip', 'Araç üstü yangın monitörü', 'Portatif yangın monitörü', 'İtfaiye araçları için çıkrık' ),
					array( 'Azami Debi', '3.500 l/dk @7 bar', '3.000 l/dk @10 bar', $d ),
					array( 'Azami Basınç', '16 bar', '16 bar', '40 bar' ),
					array( 'Tahrik', '12 V / 24 V elektrik motorları', '24 V elektrik motoru, uzaktan kumanda', 'Manuel veya elektrikli' ),
					array( 'Giriş', '2½" NPT, 2½" BSPT, DN 80 flanş', 'DN80 / 2.5" bağlantılar', '3/4", 1", 1 1/4" (NPT / BSP)' ),
					array( 'Malzeme', 'Alüminyum, sert anodize', 'Alüminyum, sert anodize', 'Galvanizli çelik, epoksi kaplama' ),
				),
			),
		),
	);
	return $s;
}

/* Photos for the slider: every product in the category (sub-groups too), round-robin so each vehicle shows early. */
function b1r17_slides( $term, $extra = array() ) {
	$posts = get_posts(
		array(
			'post_type'        => 'product',
			'post_status'      => 'publish',
			'posts_per_page'   => 30,
			'orderby'          => array( 'menu_order' => 'ASC', 'date' => 'DESC' ),
			'lang'             => '',
			'suppress_filters' => false,
			'tax_query'        => array(
				array(
					'taxonomy'         => 'product_category',
					'field'            => 'term_id',
					'terms'            => array( (int) $term->term_id ),
					'include_children' => true,
				),
			),
		)
	);
	$lists = array();
	foreach ( $posts as $p ) {
		$ids = array();
		$f   = (int) get_post_thumbnail_id( $p->ID );
		if ( $f ) {
			$ids[] = $f;
		}
		$g = function_exists( 'get_field' ) ? get_field( 'gallery', $p->ID, false ) : get_post_meta( $p->ID, 'gallery', true );
		if ( is_string( $g ) && '' !== $g ) {
			$g = maybe_unserialize( $g );
		}
		if ( is_array( $g ) ) {
			foreach ( $g as $x ) {
				$x = is_array( $x ) ? (int) ( $x['ID'] ?? $x['id'] ?? 0 ) : (int) $x;
				if ( $x ) {
					$ids[] = $x;
				}
			}
		}
		$lists[] = array( 'p' => $p, 'ids' => array_values( array_unique( $ids ) ) );
	}
	if ( $extra && $lists ) {
		$lists[0]['ids'] = array_values( array_unique( array_merge( $lists[0]['ids'], array_map( 'intval', $extra ) ) ) );
		$lists[0]['solo'] = true;
	}
	$out  = array();
	$seen = array();
	for ( $round = 0; $round < 16 && count( $out ) < 16; $round++ ) {
		foreach ( $lists as $l ) {
			if ( isset( $l['ids'][ $round ] ) && empty( $seen[ $l['ids'][ $round ] ] ) && count( $out ) < 16 ) {
				$id          = $l['ids'][ $round ];
				$seen[ $id ] = 1;
				$big         = wp_get_attachment_image_url( $id, 'large' );
				if ( ! $big ) {
					continue;
				}
				$out[] = array(
					'src'   => $big,
					'blur'  => wp_get_attachment_image_url( $id, 'medium' ),
					'title' => get_the_title( $l['p'] ),
					'url'   => get_permalink( $l['p'] ),
				);
			}
		}
	}
	return $out;
}

function b1r17_table_html( $t, $pair ) {
	$cols = $t['cols'];
	$n    = $pair ? 2 : count( $cols );
	$h    = '<div class="b1x-tw' . ( $pair ? '' : ' b1x-tw-cmp' ) . '"><table class="b1x-t' . ( $n > 3 ? ' b1x-t-wide' : '' ) . '"><thead><tr>';
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
	$tr   = b1r17_is_tr();
	$pair = ! empty( $cfg['pair'] );
	$h    = '<div class="b1x"><div class="b1x-in">';
	if ( ! empty( $cfg['tables'] ) ) {
		$h .= '<div class="b1x-specs' . ( $pair ? ' b1x-pair' : '' ) . '">';
		foreach ( $cfg['tables'] as $t ) {
			$h .= b1r17_table_html( $t, $pair );
		}
		$h .= '</div>';
	}
	$slides = b1r17_slides( $term, $cfg['extra'] ?? array() );
	if ( $slides ) {
		$h .= '<div class="b1x-car" data-b1x-car><div class="b1x-track" tabindex="0" aria-label="' . esc_attr( ( $tr ? 'Fotoğraflar: ' : 'Photos: ' ) . $term->name ) . '">';
		foreach ( $slides as $sl ) {
			$h .= '<a class="b1x-slide" href="' . esc_url( $sl['url'] ) . '" title="' . esc_attr( $sl['title'] ) . '">'
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
		$add  = $cfg ? b1r17_block( $term, $cfg ) : '';
		return substr( $html, 0, $a ) . $hero . $add . substr( $html, $b );
	} );
}, 3 );

add_action( 'wp_head', function () {
	if ( ! is_tax( 'product_category' ) ) {
		return;
	}
	?>
<style id="b1-r17">
.elementor-element-c81cf70 .elementor-button.b1x-on{background:#fff!important;color:#1c1c1c!important;border-color:#fff!important}
.tax-product_category .elementor-element-c81cf70{--min-height:0px;min-height:0!important;padding-top:140px!important;padding-bottom:56px!important}
.b1x{width:100%;padding:48px 48px 0;box-sizing:border-box;background:#fff}
.b1x-in{max-width:1344px;margin:0 auto;display:flex;flex-direction:column;gap:56px}
.tax-product_category .elementor-element-3b5f245>.e-con-inner{padding-top:64px}
.b1x-specs{display:grid;gap:28px;width:100%}
.b1x-pair{grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);align-items:start}
.b1x-tw{width:100%;overflow-x:auto;border:1px solid #dfe2e6;border-radius:12px;background:#fff;box-shadow:0 10px 30px -18px rgba(16,24,40,.25)}
.b1x-t{width:100%;border-collapse:collapse;font-size:15px;line-height:1.45;color:#1c1c1c;margin:0;border:0}
.b1x-t-wide{min-width:860px}
.b1x-tw-cmp tbody th{width:22%}
.b1x-t thead th{background:#24282d;color:#fff;font-weight:600;font-size:16px;line-height:1.3;text-align:left;padding:16px 20px;border:0;border-left:1px solid #3a3f45;vertical-align:middle}
.b1x-t thead th:first-child{border-left:0}
.b1x-tw-cmp thead th{text-align:center}
.b1x-tw-cmp thead th.b1x-c0{text-align:left;font-size:15px;letter-spacing:.02em}
.b1x-t tbody th{background:#f3f4f6;font-weight:600;text-align:left;padding:11px 20px;width:30%;border:0;border-top:1px solid #e5e7eb;vertical-align:top}
.b1x-t tbody td{padding:11px 20px;border:0;border-top:1px solid #e5e7eb;border-left:1px solid #e5e7eb;background:#fff;vertical-align:top}
.b1x-tw-cmp tbody td{text-align:center}
.b1x-tw-cmp tbody td[colspan]{text-align:left}
.b1x-t tbody tr:hover td{background:#fafafa}
.b1x-car{position:relative;width:100%}
.b1x-track{display:flex;gap:16px;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;scrollbar-width:none;padding:2px 0 4px;outline:0}
.b1x-track::-webkit-scrollbar{display:none}
.b1x-slide{position:relative;flex:0 0 calc((100% - 48px)/4);aspect-ratio:4/3;border-radius:12px;overflow:hidden;scroll-snap-align:start;background:#e9ebee;display:block}
.b1x-ph{position:absolute;inset:-20px;background-size:cover;background-position:center;filter:blur(18px) brightness(.9);transform:scale(1.08)}
.b1x-slide img{position:relative;width:100%;height:100%;object-fit:contain;display:block;transition:transform .5s ease}
.b1x-slide:hover img{transform:scale(1.03)}
.b1x-nav{position:absolute;top:calc(50% - 14px);transform:translateY(-50%);z-index:3;width:48px;height:48px;border-radius:50%;border:0;background:#fff;color:#1c1c1c;box-shadow:0 6px 20px rgba(0,0,0,.22);display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0;transition:opacity .2s ease}
.b1x-nav:hover{background:#fff;color:#ed1c24}
.b1x-nav.prev{left:-24px}.b1x-nav.next{right:-24px}
.b1x-nav[disabled]{opacity:0;pointer-events:none}
.b1x-dots{display:flex;justify-content:center;gap:8px;margin-top:18px;min-height:8px}
.b1x-dots span{width:8px;height:8px;border-radius:50%;background:#d1d5db;cursor:pointer;transition:background .2s,transform .2s}
.b1x-dots span.on{background:#ed1c24;transform:scale(1.15)}
@media(max-width:1024px){.b1x{padding:40px 32px 0}.b1x-pair{grid-template-columns:1fr}.b1x-slide{flex-basis:calc((100% - 32px)/3)}.b1x-nav.prev{left:6px}.b1x-nav.next{right:6px}}
@media(max-width:767px){.tax-product_category .elementor-element-c81cf70{padding-top:110px!important;padding-bottom:40px!important}.b1x{padding:28px 20px 0}.b1x-in{gap:36px}.b1x-t{font-size:14px}.b1x-t thead th{padding:13px 14px;font-size:14px}.b1x-t tbody th,.b1x-t tbody td{padding:10px 14px}.b1x-t-wide{min-width:720px}.b1x-t-wide tbody th,.b1x-t-wide thead th.b1x-c0{position:sticky;left:0;z-index:1;min-width:150px}.b1x-slide{flex-basis:82%}.b1x-nav{width:40px;height:40px}}
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
